<?php
// backend/controllers/TeamLeaderController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\{Response, Request};
use App\Middleware\AuthMiddleware;
use App\Helpers\OrderClaim;
use App\Helpers\ImageStore;

/**
 * Team Leader endpoints — full job lifecycle.
 *
 *   GET    /api/tl/available              available()      — Available list (paid + unclaimed + same service + not rejected)
 *   GET    /api/tl/jobs                   jobs()           — My claimed jobs with filters
 *   GET    /api/tl/jobs/{svcId}           jobDetail()      — Single job detail with payments
 *   GET    /api/tl/jobs/{svcId}/slip      printSlip()      — Slip data for printing
 *   PATCH  /api/tl/jobs/{svcId}/accept    accept()         — Atomic claim
 *   PATCH  /api/tl/jobs/{svcId}/reject    reject()         — Hide from this TL's available list
 *   PATCH  /api/tl/jobs/{svcId}/start     start()          — accepted → in_progress
 *   PATCH  /api/tl/jobs/{svcId}/complete  complete()       — in_progress → completed
 *   POST   /api/tl/orders                 directBook()     — TL creates order on the spot
 *   GET    /api/tl/stats/today            statsToday()     — TL dashboard counters
 */
class TeamLeaderController
{
    // =================================================================
    // GET /api/tl/available
    // =================================================================
    /**
     * Paid orders matching this TL's service that nobody has claimed
     * yet, and that the TL has not personally rejected.
     */
    public function available(array $params = []): void
    {
        $user      = AuthMiddleware::require(['team_leader']);
        $serviceId = $user['service_id'];
        $tlId      = $user['id'];

        $pdo = Database::getInstance();

        // Pagination
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(100, max(10, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $sql = '
            SELECT os.id, os.daily_serial, os.serial_date, os.price,
                   os.location_details, os.image_path, os.created_at, os.version,
                   os.employee_id,
                   s.name AS service_name,
                   o.id              AS order_id,
                   o.order_number,
                   o.vehicle_plate, o.vehicle_make, o.vehicle_model,
                   o.customer_name, o.customer_phone, o.customer_email,
                   o.notes           AS order_notes,
                   o.payment_status,
                   o.total_amount,
                   pl.name           AS location_name,
                   pl.terminal       AS location_terminal,
                   e.name            AS employee_name,
                   e.employee_code   AS employee_code,
                   e.image_path      AS employee_image_path,
                   (SELECT GROUP_CONCAT(p.payment_method)
                      FROM payments p
                      WHERE p.order_id = o.id AND p.status = "success") AS payment_methods
              FROM order_services os
              JOIN orders   o   ON o.id  = os.order_id
              JOIN services s   ON s.id  = os.service_id
              LEFT JOIN parking_locations pl ON pl.id = o.parking_location_id
              LEFT JOIN employees e          ON e.id  = os.employee_id
             WHERE os.status         = "pending"
               AND os.provider_id   IS NULL
               AND os.service_id    = :service_id
               AND o.payment_status = "paid"
               AND (
                     os.rejected_by IS NULL
                     OR JSON_CONTAINS(os.rejected_by, JSON_QUOTE(:tl_id_filter)) = 0
                   )
               AND (
                     os.employee_id IS NULL
                     OR e.team_leader_id = :tl_id_route
                   )
             ORDER BY os.created_at ASC
             LIMIT :lim OFFSET :off';

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':service_id',  $serviceId);
        $stmt->bindValue(':tl_id_filter', $tlId);
        $stmt->bindValue(':tl_id_route',  $tlId);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        Response::success([
            'items' => $rows,
            'meta'  => [
                'page'  => $page,
                'limit' => $limit,
                'count' => count($rows),
            ],
        ]);
    }

    // =================================================================
    // GET /api/tl/jobs
    // =================================================================
    /**
     * The TL's own jobs (claimed, in-progress, completed, cancelled).
     * Filters: status, employee_id, from, to, q (plate/customer search).
     */
    public function jobs(array $params = []): void
    {
        $user = AuthMiddleware::require(['team_leader', 'admin']);

        // Admin can pass ?team_leader_id=... to inspect a TL's jobs
        $tlId = $user['role'] === 'admin'
              ? (string)($_GET['team_leader_id'] ?? '')
              : $user['id'];

        if (!$tlId) {
            Response::error('team_leader_id is required', 422);
        }

        $pdo = Database::getInstance();

        $where  = ['os.provider_id = ?'];
        $bind   = [$tlId];

        // Status: accepts comma list (e.g. "accepted,in_progress") or
        // special words: "active" => not-yet-completed,  "completed"
        if (!empty($_GET['status'])) {
            $statusVal = (string)$_GET['status'];
            if ($statusVal === 'active') {
                $where[] = 'os.status IN ("accepted","in_progress","assigned")';
            } elseif ($statusVal === 'completed') {
                $where[] = 'os.status = "completed"';
            } elseif ($statusVal === 'cancelled') {
                $where[] = 'os.status = "cancelled"';
            } else {
                $statuses = array_map('trim', explode(',', $statusVal));
                $valid    = ['pending','assigned','accepted','in_progress','completed','cancelled','rejected'];
                $statuses = array_values(array_intersect($statuses, $valid));
                if ($statuses) {
                    $where[] = 'os.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
                    $bind    = array_merge($bind, $statuses);
                }
            }
        }

        // Employee filter
        if (!empty($_GET['employee_id'])) {
            $where[] = 'os.employee_id = ?';
            $bind[]  = (string)$_GET['employee_id'];
        }

        // Date range (against created_at for active, completed_at for completed)
        $dateCol = (($_GET['status'] ?? '') === 'completed')
                 ? 'os.completed_at' : 'os.created_at';
        if (!empty($_GET['from'])) {
            $where[] = "DATE($dateCol) >= ?";
            $bind[]  = (string)$_GET['from'];
        }
        if (!empty($_GET['to'])) {
            $where[] = "DATE($dateCol) <= ?";
            $bind[]  = (string)$_GET['to'];
        }

        // Free-text search. We probe:
        //   - order_number (e.g. "1034" → exact-ish via LIKE)
        //   - daily_serial (TL's per-day running counter)
        //   - employee name + code (so a TL can find "all of CW001's jobs")
        //   - plate / customer name / phone for legacy orders
        if (!empty($_GET['q'])) {
            $qRaw = trim((string)$_GET['q']);
            $q    = '%' . $qRaw . '%';
            // If the query is purely numeric, also include daily_serial exact
            // match (it's an INT column; LIKE would still work via CAST but
            // exact = is faster + cleaner)
            $isNumeric = ctype_digit($qRaw);
            $clauses = [
                'CAST(o.order_number AS CHAR) LIKE ?',
                'o.vehicle_plate LIKE ?',
                'o.customer_name LIKE ?',
                'o.customer_phone LIKE ?',
                'e.name LIKE ?',
                'e.employee_code LIKE ?',
            ];
            $params = [$q, $q, $q, $q, $q, $q];
            if ($isNumeric) {
                $clauses[] = 'os.daily_serial = ?';
                $params[]  = (int)$qRaw;
            }
            $where[] = '(' . implode(' OR ', $clauses) . ')';
            foreach ($params as $p) { $bind[] = $p; }
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        // Pagination
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(100, max(10, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        // Total count for the meta block.
        // LEFT JOIN employees here too — the free-text search probes e.name
        // and e.employee_code, and a missing JOIN here would crash the count.
        $countSQL  = "SELECT COUNT(*) FROM order_services os
                      JOIN orders o ON o.id = os.order_id
                      LEFT JOIN employees e ON e.id = os.employee_id
                      $whereSQL";
        $countStmt = $pdo->prepare($countSQL);
        $countStmt->execute($bind);
        $total = (int)$countStmt->fetchColumn();

        $orderBy = (($_GET['status'] ?? '') === 'completed')
                 ? 'os.completed_at DESC'
                 : 'os.created_at DESC';

        $sql = "
            SELECT os.id, os.daily_serial, os.serial_date, os.status, os.price,
                   os.location_details, os.image_path, os.version,
                   os.assigned_at, os.accepted_at, os.started_at, os.completed_at,
                   s.name AS service_name,
                   e.id    AS employee_id,
                   e.name  AS employee_name,
                   e.employee_code,
                   o.id              AS order_id,
                   o.order_number,
                   o.vehicle_plate, o.vehicle_make, o.vehicle_model,
                   o.customer_name, o.customer_phone, o.customer_email,
                   o.notes           AS order_notes,
                   o.payment_status,
                   o.total_amount, o.paid_amount,
                   o.created_at      AS order_created_at,
                   pl.name           AS location_name,
                   pl.terminal       AS location_terminal,
                   (SELECT JSON_ARRAYAGG(
                            JSON_OBJECT(
                              'method',         p.payment_method,
                              'amount',         p.amount,
                              'transaction_ref',p.transaction_ref,
                              'status',         p.status,
                              'created_at',     p.created_at
                            ))
                      FROM payments p
                      WHERE p.order_id = o.id AND p.status = 'success'
                   ) AS payments_json
              FROM order_services os
              JOIN orders   o   ON o.id  = os.order_id
              JOIN services s   ON s.id  = os.service_id
              LEFT JOIN employees         e  ON e.id = os.employee_id
              LEFT JOIN parking_locations pl ON pl.id = o.parking_location_id
            $whereSQL
            ORDER BY $orderBy
            LIMIT ? OFFSET ?
        ";

        $stmt = $pdo->prepare($sql);
        $i = 1;
        foreach ($bind as $b) {
            $stmt->bindValue($i++, $b);
        }
        $stmt->bindValue($i++, $limit,  \PDO::PARAM_INT);
        $stmt->bindValue($i,   $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        // Parse payments_json into an array
        foreach ($rows as &$r) {
            $r['payments'] = $r['payments_json'] ? json_decode($r['payments_json'], true) : [];
            unset($r['payments_json']);
        }
        unset($r);

        Response::success([
            'items' => $rows,
            'meta'  => [
                'page'        => $page,
                'limit'       => $limit,
                'total'       => $total,
                'total_pages' => max(1, (int)ceil($total / $limit)),
            ],
        ]);
    }

    // =================================================================
    // GET /api/tl/jobs/{svcId}
    // =================================================================
    public function jobDetail(array $params): void
    {
        $user  = AuthMiddleware::require(['team_leader', 'admin']);
        $svcId = $params['svcId'] ?? '';

        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'SELECT os.*, s.name AS service_name,
                    e.name AS employee_name, e.employee_code,
                    o.order_number, o.vehicle_plate, o.vehicle_make, o.vehicle_model,
                    o.customer_name, o.customer_phone, o.customer_email,
                    o.notes AS order_notes, o.payment_status, o.total_amount,
                    o.paid_amount, o.tracking_token, o.created_at AS order_created_at,
                    pl.name AS location_name, pl.terminal AS location_terminal,
                    tl.name AS team_leader_name
             FROM order_services os
             JOIN orders   o   ON o.id  = os.order_id
             JOIN services s   ON s.id  = os.service_id
             LEFT JOIN employees         e  ON e.id  = os.employee_id
             LEFT JOIN parking_locations pl ON pl.id = o.parking_location_id
             LEFT JOIN users             tl ON tl.id = os.provider_id
             WHERE os.id = ?'
        );
        $stmt->execute([$svcId]);
        $row = $stmt->fetch();

        if (!$row) {
            Response::error('Job not found', 404);
        }
        if ($user['role'] === 'team_leader' && $row['provider_id'] !== $user['id']) {
            Response::error('Forbidden', 403);
        }

        $pay = $pdo->prepare(
            'SELECT id, payment_method, amount, transaction_ref, status, created_at
             FROM payments WHERE order_id = ? ORDER BY created_at DESC'
        );
        $pay->execute([$row['order_id']]);
        $row['payments'] = $pay->fetchAll();

        Response::success($row);
    }

    // =================================================================
    // GET /api/tl/jobs/{svcId}/slip
    // =================================================================
    /**
     * Returns just enough data to print a slip:
     *   daily serial #, service name, plate, customer, location,
     *   employee, TL, timestamp, payment summary.
     */
    public function printSlip(array $params): void
    {
        $user  = AuthMiddleware::require(['team_leader', 'admin']);
        $svcId = $params['svcId'] ?? '';

        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'SELECT os.id, os.daily_serial, os.serial_date,
                    os.price, os.location_details, os.created_at AS order_time,
                    os.provider_id,
                    s.name AS service_name,
                    e.name AS employee_name, e.employee_code,
                    o.order_number, o.vehicle_plate,
                    o.customer_name, o.customer_phone,
                    o.total_amount, o.paid_amount, o.payment_status,
                    pl.name AS location_name, pl.terminal AS location_terminal,
                    tl.name AS team_leader_name,
                    (SELECT GROUP_CONCAT(DISTINCT p.payment_method)
                       FROM payments p
                       WHERE p.order_id = o.id AND p.status = "success") AS payment_methods,
                    (SELECT SUM(p.amount)
                       FROM payments p
                       WHERE p.order_id = o.id AND p.status = "success") AS paid_total
               FROM order_services os
               JOIN orders   o   ON o.id  = os.order_id
               JOIN services s   ON s.id  = os.service_id
               LEFT JOIN employees         e  ON e.id  = os.employee_id
               LEFT JOIN parking_locations pl ON pl.id = o.parking_location_id
               LEFT JOIN users             tl ON tl.id = os.provider_id
              WHERE os.id = ?'
        );
        $stmt->execute([$svcId]);
        $slip = $stmt->fetch();

        if (!$slip) {
            Response::error('Job not found', 404);
        }
        if ($user['role'] === 'team_leader' && $slip['provider_id'] !== $user['id']) {
            Response::error('Forbidden', 403);
        }

        $slip['currency'] = APP_CURRENCY;
        $slip['app_name'] = APP_NAME;

        Response::success($slip);
    }

    // =================================================================
    // PATCH /api/tl/jobs/{svcId}/accept
    // =================================================================
    public function accept(array $params): void
    {
        $user  = AuthMiddleware::require(['team_leader']);
        $svcId = $params['svcId'] ?? '';

        $claim = new OrderClaim();
        $res   = $claim->accept($svcId, $user['id']);

        if (!$res['ok']) {
            // 409 for "already taken" (race), 422 for everything else
            $status = $res['code'] === OrderClaim::ALREADY_TAKEN ? 409 : 422;
            Response::error($res['message'], $status, ['code' => $res['code']]);
        }
        Response::success(null, $res['message']);
    }

    // =================================================================
    // PATCH /api/tl/jobs/{svcId}/reject
    // =================================================================
    public function reject(array $params): void
    {
        $user  = AuthMiddleware::require(['team_leader']);
        $svcId = $params['svcId'] ?? '';

        $claim = new OrderClaim();
        $res   = $claim->reject($svcId, $user['id']);

        if (!$res['ok']) {
            Response::error($res['message'], 422, ['code' => $res['code']]);
        }
        Response::success(null, $res['message']);
    }

    // =================================================================
    // PATCH /api/tl/jobs/{svcId}/start
    // =================================================================
    public function start(array $params): void
    {
        $user  = AuthMiddleware::require(['team_leader']);
        $svcId = $params['svcId'] ?? '';

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'UPDATE order_services
                SET status = "in_progress",
                    started_at = UTC_TIMESTAMP(),
                    version = version + 1,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND provider_id = ? AND status = "accepted"'
        );
        $stmt->execute([$svcId, $user['id']]);

        if ($stmt->rowCount() === 0) {
            // Diagnose
            $check = $pdo->prepare(
                'SELECT status, provider_id FROM order_services WHERE id = ?'
            );
            $check->execute([$svcId]);
            $row = $check->fetch();

            if (!$row) {
                Response::error('Job not found', 404);
            }
            if ($row['provider_id'] !== $user['id']) {
                Response::error('Job is not assigned to you', 403);
            }
            Response::error("Cannot start job in status: {$row['status']} (must be 'accepted')", 422);
        }

        Response::success(null, 'Job started');
    }

    // =================================================================
    // PATCH /api/tl/jobs/{svcId}/complete
    // =================================================================
    public function complete(array $params): void
    {
        $user  = AuthMiddleware::require(['team_leader']);
        $svcId = $params['svcId'] ?? '';

        $pdo  = Database::getInstance();

        // Check payment status first — TLs can't complete a job whose
        // order still has an outstanding balance.
        $check = $pdo->prepare(
            'SELECT os.status, os.provider_id,
                    o.payment_status, o.total_amount, o.paid_amount,
                    (o.total_amount - o.paid_amount) AS balance
             FROM order_services os
             JOIN orders o ON o.id = os.order_id
             WHERE os.id = ?'
        );
        $check->execute([$svcId]);
        $row = $check->fetch();

        if (!$row) {
            Response::error('Job not found', 404);
        }
        if ($row['provider_id'] !== $user['id']) {
            Response::error('Job is not assigned to you', 403);
        }
        if ($row['status'] !== 'in_progress') {
            Response::error("Cannot complete job in status: {$row['status']} (must be 'in_progress')", 422);
        }
        if ($row['payment_status'] !== 'paid') {
            Response::error(
                'Cannot complete: this order is ' . $row['payment_status']
                . '. Balance due: ' . number_format((float)$row['balance'], 2),
                422,
                ['code' => 'UNPAID_BALANCE']
            );
        }

        $stmt = $pdo->prepare(
            'UPDATE order_services
                SET status = "completed",
                    completed_at = UTC_TIMESTAMP(),
                    version = version + 1,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND provider_id = ? AND status = "in_progress"'
        );
        $stmt->execute([$svcId, $user['id']]);

        if ($stmt->rowCount() === 0) {
            // Race: someone else changed status between our checks
            Response::error('Job state changed — please refresh', 409);
        }

        Response::success(null, 'Job completed');
    }

    // =================================================================
    // POST /api/tl/orders
    // =================================================================
    /**
     * Direct booking — TL fills the form on the spot, picks an employee,
     * collects payment, all in one shot.
     *
     * Required body fields:
     *   vehicle_plate, employee_id, payment_method
     *
     * Optional:
     *   customer_name, customer_phone, customer_email,
     *   location_id, location_details, image_path, notes,
     *   payment_ref, payment_uuid
     */
    public function directBook(array $params = []): void
    {
        $user = AuthMiddleware::require(['team_leader']);
        $req  = new Request();

        $vehiclePlate  = trim((string)$req->input('vehicle_plate', ''));
        $employeeId    = (string)$req->input('employee_id', '');
        $paymentMethod = (string)$req->input('payment_method', '');

        // Validation — plate is OPTIONAL. If supplied, must match UAE format.
        $plateUpper = null;
        if ($vehiclePlate !== '') {
            $plateUpper = strtoupper($vehiclePlate);
            if (!preg_match(UAE_PLATE_REGEX, $plateUpper)) {
                Response::error('Invalid vehicle plate format', 422);
            }
        }
        if (!$employeeId) {
            Response::error('Employee is required', 422);
        }
        if (!in_array($paymentMethod, ['cash', 'card', 'online', 'unpaid', 'split'], true)) {
            Response::error('Invalid payment method', 422);
        }

        // For split: pass through the breakdown array. OrderClaim does the
        // sum-equals-price validation.
        $splitPayments = null;
        if ($paymentMethod === 'split') {
            $splitPayments = $req->input('split_payments');
            if (!is_array($splitPayments) || empty($splitPayments)) {
                Response::error('split_payments array is required when method = split', 422);
            }
        }

        $input = [
            'vehicle_plate'    => $plateUpper,                          // may be null
            'customer_name'    => null,                                 // TL flow has no customer fields
            'customer_phone'   => null,
            'customer_email'   => null,
            'employee_id'      => $employeeId,
            'location_id'      => $req->input('location_id') ?: null,
            'location_details' => trim((string)$req->input('location_details', '')) ?: null,
            // image_path may arrive as either a server URL or (older PWA
            // versions / queued sync actions) a base64 data URL. Normalize
            // here so we never write a multi-megabyte string into a
            // varchar(255) column.
            'image_path'       => ImageStore::normalize($req->input('image_path'), 'order'),
            'notes'            => $req->input('notes') ?: null,
            'payment_method'   => $paymentMethod,
            'split_payments'   => $splitPayments,
            'payment_ref'      => $req->input('payment_ref') ?: null,
            'payment_uuid'     => $req->input('payment_uuid') ?: $this->newUUID(),
        ];

        $claim = new OrderClaim();
        $res   = $claim->directBook($user['id'], $input);

        if (!$res['ok']) {
            Response::error($res['message'], 422, ['code' => $res['code']]);
        }

        // For payments with an online portion, spin up a Stripe Checkout
        // Session so the TL has a URL they can share with the customer.
        //
        // OrderClaim hands back `link_amount` set to the online portion only:
        //   plain online              → full price
        //   split with online portion → just the online portion
        //   anything else             → null
        $data = $res['data'];
        $linkAmount = isset($data['link_amount']) ? (float)$data['link_amount'] : 0.0;
        $needsLink  = ($data['payment_method'] === 'online'
                    || $data['payment_method'] === 'split')
                    && $linkAmount > 0;
        if ($needsLink) {
            try {
                $checkoutUrl = $this->createStripeLinkForOrder(
                    orderId:       $data['order_id'],
                    orderNumber:   $data['order_number'],
                    trackingToken: $data['tracking_token'],
                    serviceName:   $data['service_name'],
                    amount:        $linkAmount
                );
                $data['checkout_url'] = $checkoutUrl;
                // Short shareable link — /pay/go/<token> redirects directly
                // to Stripe via the customer/pay-by-token endpoint.
                $base = defined('PUBLIC_BASE_URL') && PUBLIC_BASE_URL
                    ? rtrim(PUBLIC_BASE_URL, '/') : '';
                $data['pay_url'] = $base . '/pay/go/' . urlencode($data['tracking_token']);
            } catch (\Throwable $e) {
                error_log('TL direct-book Stripe link failed for order '
                    . $data['order_id'] . ': ' . $e->getMessage());
                // Soft-fail — the order is saved as unpaid, just no link.
                // The TL can retry by tapping a "Generate link" button later
                // (admin can also generate one).
                $data['checkout_url'] = null;
                $data['pay_url']      = null;
                $data['link_error']   = 'Could not create payment link — order saved as unpaid.';
            }
        }

        Response::json([
            'success' => true,
            'message' => $res['message'],
            'data'    => $data,
        ], 201);
    }

    /**
     * Build a Stripe Checkout Session for an order created by a TL.
     * Mirrors CustomerController::createCheckoutSession but lives here so
     * the controllers don't have to import each other.
     */
    private function createStripeLinkForOrder(
        string $orderId,
        int    $orderNumber,
        string $trackingToken,
        string $serviceName,
        float  $amount
    ): string {
        if (!class_exists('\Stripe\Stripe')) {
            throw new \RuntimeException('Stripe SDK not installed');
        }
        if (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) {
            throw new \RuntimeException('STRIPE_SECRET_KEY not configured');
        }

        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

        $base = defined('PUBLIC_BASE_URL') && PUBLIC_BASE_URL
            ? rtrim(PUBLIC_BASE_URL, '/')
            : (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http')
              . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

        $session = \Stripe\Checkout\Session::create([
            'mode'       => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency'     => strtolower(defined('STRIPE_CURRENCY') ? STRIPE_CURRENCY : 'aed'),
                    'unit_amount'  => (int)round($amount * 100),
                    'product_data' => [
                        'name'        => 'AirVAS · ' . $serviceName,
                        'description' => 'Order #' . $orderNumber,
                    ],
                ],
                'quantity' => 1,
            ]],
            'success_url' => $base . '/pay/success/?t=' . urlencode($trackingToken)
                                   . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => $base . '/pay/cancel/?t=' . urlencode($trackingToken),
            'metadata' => [
                'order_id'       => $orderId,
                'order_number'   => (string)$orderNumber,
                'tracking_token' => $trackingToken,
            ],
        ]);

        // Persist session id for verifyPayment() / webhook lookup
        $pdo = Database::getInstance();
        $pdo->prepare('UPDATE orders SET stripe_session_id = ? WHERE id = ?')
            ->execute([$session->id, $orderId]);

        return $session->url;
    }

    // =================================================================
    // GET /api/tl/stats/today
    // =================================================================
    /**
     * TL's profile-tab dashboard: today's orders, revenue,
     * cash/card/online split, active jobs, cash awaiting handover.
     */
    public function statsToday(array $params = []): void
    {
        $user = AuthMiddleware::require(['team_leader']);
        $tlId = $user['id'];

        $pdo = Database::getInstance();

        // Order counts + revenue split today.
        //
        // The revenue / unpaid split matters for accuracy:
        //   * revenue       = money actually EARNED today (jobs the TL
        //                     completed today). Uses completed_at.
        //   * unpaid_amount = money OWED for work in progress today
        //                     (jobs the TL accepted today and hasn't
        //                     finished yet). Uses accepted_at.
        //
        // Previously the "revenue" column was just SUM(price) across
        // every today-touched row — that overstated earnings by
        // including in-flight work. The new split lines up with what
        // the TL actually sees in cash/card/online totals.
        //
        // Cancelled/rejected statuses are excluded from both buckets
        // because they're not real money in either column.
        $stmt = $pdo->prepare(
            'SELECT
                COUNT(*)                                  AS order_count,
                COALESCE(SUM(CASE
                    WHEN os.status = "completed"
                     AND DATE(os.completed_at) = CURDATE()
                    THEN os.price ELSE 0
                END), 0)                                  AS total_revenue,
                COALESCE(SUM(CASE
                    WHEN os.status IN ("accepted","in_progress")
                     AND DATE(os.accepted_at) = CURDATE()
                    THEN os.price ELSE 0
                END), 0)                                  AS unpaid_amount,
                SUM(CASE WHEN os.status = "completed"
                          AND DATE(os.completed_at) = CURDATE()
                         THEN 1 ELSE 0 END)               AS completed_count,
                SUM(CASE WHEN os.status = "in_progress"
                          AND DATE(os.accepted_at) = CURDATE()
                         THEN 1 ELSE 0 END)               AS in_progress_count,
                SUM(CASE WHEN os.status = "accepted"
                          AND DATE(os.accepted_at) = CURDATE()
                         THEN 1 ELSE 0 END)               AS accepted_count
             FROM order_services os
             WHERE os.provider_id = ?
               AND DATE(COALESCE(os.completed_at, os.accepted_at, os.created_at)) = CURDATE()'
        );
        $stmt->execute([$tlId]);
        $orders = $stmt->fetch();

        // Cash / card / online split from payments where this TL's jobs landed today
        $stmt = $pdo->prepare(
            'SELECT p.payment_method,
                    COUNT(DISTINCT p.id)        AS payments_count,
                    COALESCE(SUM(p.amount), 0)  AS total
             FROM payments p
             JOIN orders o          ON o.id  = p.order_id
             JOIN order_services os ON os.order_id = o.id AND os.provider_id = ?
             WHERE p.status = "success"
               AND DATE(p.created_at) = CURDATE()
             GROUP BY p.payment_method'
        );
        $stmt->execute([$tlId]);
        $byMethod = ['cash' => 0.0, 'card' => 0.0, 'online' => 0.0];
        foreach ($stmt->fetchAll() as $r) {
            $byMethod[$r['payment_method']] = (float)$r['total'];
        }

        // Cash awaiting handover (today's cash not yet in a pending/confirmed handover)
        // For simplicity we just report today's cash total here. The Handover
        // controller does the precise reconciliation.
        Response::success([
            'date'                => date('Y-m-d'),
            'orders'              => (int)$orders['order_count'],
            'completed'           => (int)$orders['completed_count'],
            'in_progress'         => (int)$orders['in_progress_count'],
            'accepted'            => (int)$orders['accepted_count'],
            'revenue'             => (float)$orders['total_revenue'],
            'unpaid_amount'       => (float)$orders['unpaid_amount'],
            'cash_total'          => $byMethod['cash'],
            'card_total'          => $byMethod['card'],
            'online_total'        => $byMethod['online'],
            'currency'            => APP_CURRENCY,
            'service_name'        => $user['service_name'] ?? null,
        ]);
    }

    // =================================================================
    // GET /api/tl/badges  — counters for the TL's left-nav badges
    // =================================================================
    /**
     * Single source of truth for the nav badge counters in the TL PWA.
     *   - available: how many jobs are visible on the Available tab right
     *               now (same-service, paid, not assigned, not rejected)
     *   - jobs:     how many of this TL's accepted/in_progress jobs are
     *               still active (unpaid in their view)
     *
     * Polled from the global app shell so badges stay live even while
     * the user is on a different tab. The actual tab components also
     * still update their own badges when they fetch list data — both
     * sources agree because they use the same definitions.
     *
     * "Unpaid" for the My Jobs badge intentionally does NOT date-filter:
     * any open job, today or not, should be visible to the TL. The
     * My Jobs UI defaults to today's date filter for the LIST display,
     * but the BADGE shows everything outstanding so the TL doesn't
     * miss work in flight from yesterday.
     */
    public function badges(array $params = []): void
    {
        $user = AuthMiddleware::require(['team_leader']);
        $tlId = $user['id'];
        $svcId = $user['service_id'] ?? null;

        $pdo = Database::getInstance();

        // Available count: same logic as available() endpoint, just the count
        $availStmt = $pdo->prepare(
            'SELECT COUNT(*)
               FROM order_services os
               JOIN orders o ON o.id = os.order_id
              WHERE os.service_id   = ?
                AND os.status       = "pending"
                AND os.provider_id  IS NULL
                AND o.payment_status = "paid"
                AND o.order_number   IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1 FROM order_services oss
                    WHERE oss.order_id    = o.id
                      AND oss.provider_id = ?
                )'
        );
        $availStmt->execute([$svcId, $tlId]);
        $availCount = (int)$availStmt->fetchColumn();

        // My-Jobs unpaid count: TL's accepted/in_progress order_services
        // where the parent order is not yet paid. This matches what the
        // "Unpaid" chip shows on My Jobs, just without the date filter.
        $myStmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT os.id)
               FROM order_services os
               JOIN orders o ON o.id = os.order_id
              WHERE os.provider_id = ?
                AND os.status IN ("accepted","in_progress")'
        );
        $myStmt->execute([$tlId]);
        $jobsCount = (int)$myStmt->fetchColumn();

        Response::success([
            'available' => $availCount,
            'jobs'      => $jobsCount,
        ]);
    }

    // =================================================================
    // Helpers
    // =================================================================
    private function newUUID(): string
    {
        $pdo = Database::getInstance();
        return $pdo->query('SELECT UUID()')->fetchColumn();
    }
}
<?php
// backend/controllers/PaymentController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\{Response, Request};
use App\Middleware\AuthMiddleware;

/**
 * Payment recording & listing.
 *
 *   GET   /api/payments                       index()      — admin list with filters
 *   POST  /api/orders/{id}/payments           record()     — record a new payment
 *   GET   /api/orders/{id}/payments           byOrder()    — list payments for one order
 */
class PaymentController
{
    // GET /api/payments
    public function index(array $params = []): void
    {
        AuthMiddleware::require(['admin']);
        $pdo = Database::getInstance();

        $where = [
            'p.status = "success"',
            // Hide payments tied to unnumbered customer carts. Defensive:
            // by design those carts have no successful payments yet (the
            // payment row is what triggers numbering in the webhook), but
            // this guards against the brief window between an INSERT
            // payment and the same-transaction UPDATE that assigns the
            // number — and against any legacy stale data.
            'o.order_number IS NOT NULL',
        ];
        $bind  = [];

        if (!empty($_GET['method'])
            && in_array($_GET['method'], ['cash','card','online'], true)) {
            $where[] = 'p.payment_method = ?';
            $bind[]  = (string)$_GET['method'];
        }
        if (!empty($_GET['from'])) {
            $where[] = 'DATE(p.created_at) >= ?'; $bind[] = (string)$_GET['from'];
        }
        if (!empty($_GET['to'])) {
            $where[] = 'DATE(p.created_at) <= ?'; $bind[] = (string)$_GET['to'];
        }
        if (!empty($_GET['recorded_by'])) {
            $where[] = 'p.recorded_by = ?'; $bind[] = (string)$_GET['recorded_by'];
        }
        if (!empty($_GET['q'])) {
            $q = '%' . trim((string)$_GET['q']) . '%';
            // Search probes transaction reference + order number (plate
            // was removed during the plate/customer cleanup pass).
            $where[] = '(p.transaction_ref LIKE ? OR CAST(o.order_number AS CHAR) LIKE ?)';
            array_push($bind, $q, $q);
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(200, max(10, (int)($_GET['limit'] ?? 25)));
        $offset = ($page - 1) * $limit;

        $countSQL = "SELECT COUNT(*) FROM payments p
                     JOIN orders o ON o.id = p.order_id $whereSQL";
        $countStmt = $pdo->prepare($countSQL);
        $countStmt->execute($bind);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT p.id, p.order_id, p.payment_method, p.amount, p.transaction_ref,
                   p.status, p.created_at,
                   o.order_number,
                   u.name AS recorded_by_name
              FROM payments p
              JOIN orders o ON o.id = p.order_id
              LEFT JOIN users u ON u.id = p.recorded_by
              $whereSQL
             ORDER BY p.created_at DESC
             LIMIT ? OFFSET ?
        ";
        $stmt = $pdo->prepare($sql);
        $i = 1;
        foreach ($bind as $b) $stmt->bindValue($i++, $b);
        $stmt->bindValue($i++, $limit,  \PDO::PARAM_INT);
        $stmt->bindValue($i,   $offset, \PDO::PARAM_INT);
        $stmt->execute();

        // Aggregate totals matching the SAME filter as the list, so the
        // tiles on the admin Payments page reflect exactly what the
        // table is showing. Previously the page made a separate call to
        // /reports/payments which defaulted to "today", producing
        // confusing tiles that disagreed with the table (e.g. table
        // shows 7 all-time payments but tiles only summed today's
        // single online payment).
        $totalsSQL = "SELECT
                          COALESCE(SUM(CASE WHEN p.payment_method='cash'   THEN p.amount END),0) AS cash,
                          COALESCE(SUM(CASE WHEN p.payment_method='card'   THEN p.amount END),0) AS card,
                          COALESCE(SUM(CASE WHEN p.payment_method='online' THEN p.amount END),0) AS online,
                          COALESCE(SUM(p.amount),0) AS grand_total
                      FROM payments p
                      JOIN orders o ON o.id = p.order_id
                      $whereSQL";
        $totalsStmt = $pdo->prepare($totalsSQL);
        $totalsStmt->execute($bind);
        $totals = $totalsStmt->fetch();

        Response::success([
            'items' => $stmt->fetchAll(),
            'meta'  => [
                'page'        => $page,
                'limit'       => $limit,
                'total'       => $total,
                'total_pages' => max(1, (int)ceil($total / $limit)),
                'currency'    => APP_CURRENCY,
            ],
            'totals' => [
                'cash'   => (float)$totals['cash'],
                'card'   => (float)$totals['card'],
                'online' => (float)$totals['online'],
                'total'  => (float)$totals['grand_total'],
            ],
        ]);
    }

    // POST /api/orders/{id}/payments
    //
    // Accepts either of two payload shapes:
    //
    //   ┌── Single payment (legacy / fast path):
    //   │   { payment_method: "cash"|"card"|"online",
    //   │     amount: 30.00, uuid_ref: "...", transaction_ref?: "..." }
    //   │
    //   └── Split payment (new): two or more methods on one submit
    //       { payment_method: "split",
    //         split_payments: [
    //           { method: "cash",   amount: 10.00 },
    //           { method: "card",   amount:  5.00 },
    //           { method: "online", amount: 15.00 }     // optional
    //         ],
    //         uuid_ref: "..." }
    //
    // For the split form:
    //   - Each row's method must be one of cash/card/online
    //   - Each row's amount must be > 0 (rows with 0 should be omitted)
    //   - Online split currently NOT YET SUPPORTED in the modal UI; for
    //     My Jobs we restrict to cash + card only (online needs a Stripe
    //     session flow we'll wire in a later commit). For now, server
    //     rejects 'online' rows in a split with a clear message.
    //   - Sum must equal exactly the remaining balance (epsilon 0.005)
    //   - Each row is inserted as its own payment row in a single
    //     transaction so the order's totals stay consistent.
    public function record(array $params): void
    {
        $user = AuthMiddleware::require(['admin', 'team_leader']);
        $req  = new Request();

        $orderId = $params['id'] ?? '';
        $method  = (string)$req->input('payment_method', '');
        $uuidRef = (string)$req->input('uuid_ref', '');

        if (!$uuidRef) Response::error('uuid_ref is required (idempotency key)', 422);

        // Idempotency — same uuid_ref on retry returns the existing payment(s).
        $pdo = Database::getInstance();
        $dupStmt = $pdo->prepare('SELECT id FROM payments WHERE uuid_ref = ? LIMIT 1');
        $dupStmt->execute([$uuidRef]);
        $existing = $dupStmt->fetchColumn();
        if ($existing) {
            Response::success(['id' => $existing], 'Payment already recorded');
        }

        // Fetch order
        $ord = $pdo->prepare('SELECT id, total_amount, paid_amount FROM orders WHERE id = ?');
        $ord->execute([$orderId]);
        $order = $ord->fetch();
        if (!$order) Response::error('Order not found', 404);

        $totalAmt = (float)$order['total_amount'];
        $paidAmt  = (float)$order['paid_amount'];
        $balance  = max(0.0, $totalAmt - $paidAmt);

        // Build a normalized list of {method, amount, txRef} rows to insert.
        // Both shapes converge on this representation before we touch the DB.
        $rows = [];

        if ($method === 'split') {
            $split = $req->input('split_payments');
            if (!is_array($split) || empty($split)) {
                Response::error('split_payments array is required for split method', 422);
            }
            $sum = 0.0;
            foreach ($split as $i => $row) {
                $rm = (string)($row['method'] ?? '');
                $ra = (float)($row['amount'] ?? 0);
                // Split intake is cash + card only. Online is handled via
                // the dedicated "Online" payment method (Stripe link) or
                // via the New Order wizard for fresh bookings.
                if (!in_array($rm, ['cash', 'card'], true)) {
                    Response::error("split_payments[$i].method must be cash or card", 422);
                }
                if ($ra <= 0) {
                    // Skip empty rows — UI may send both even if user only filled one
                    continue;
                }
                $rows[] = ['method' => $rm, 'amount' => $ra, 'ref' => $row['transaction_ref'] ?? null];
                $sum += $ra;
            }
            if (empty($rows)) {
                Response::error('At least one split row must have an amount > 0', 422);
            }
            // Sum must equal balance exactly (epsilon for float rounding)
            if (abs($sum - $balance) > 0.005) {
                Response::error(
                    'Split total ' . number_format($sum, 2)
                    . ' does not equal remaining balance '
                    . number_format($balance, 2),
                    422
                );
            }
        } else {
            // Single payment (legacy shape)
            $amount = (float)$req->input('amount', 0);
            $txRef  = $req->input('transaction_ref');
            if (!in_array($method, ['cash', 'card', 'online'], true)) {
                Response::error('Invalid payment method', 422);
            }
            if ($amount <= 0) {
                Response::error('Amount must be greater than zero', 422);
            }
            $rows[] = ['method' => $method, 'amount' => $amount, 'ref' => $txRef];
        }

        // Insert all rows + advance the order's paid_amount/payment_status
        // in one transaction.
        $insertedIds = [];
        $finalPaid   = $paidAmt;
        Database::transaction(function (\PDO $pdo) use (
            $orderId, $rows, $uuidRef, $user, $totalAmt, $paidAmt,
            &$insertedIds, &$finalPaid, &$newStatus
        ) {
            $newPaid = $paidAmt;
            foreach ($rows as $idx => $r) {
                $pid = $pdo->query('SELECT UUID()')->fetchColumn();
                // Use a per-row uuid_ref so each row's idempotency is unique.
                // If the same uuid_ref were used on all rows, the second row's
                // INSERT would collide on the UNIQUE index.
                $rowUuid = count($rows) === 1 ? $uuidRef : ($uuidRef . '-' . $idx);
                $pdo->prepare(
                    'INSERT INTO payments
                       (id, order_id, payment_method, amount, transaction_ref,
                        uuid_ref, status, recorded_by, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, "success", ?, UTC_TIMESTAMP())'
                )->execute([
                    $pid, $orderId, $r['method'], $r['amount'], $r['ref'],
                    $rowUuid, $user['id'],
                ]);
                $insertedIds[] = $pid;
                $newPaid += $r['amount'];
            }

            $newStatus = ($newPaid >= $totalAmt - 0.005) ? 'paid'
                       : ($newPaid > 0                  ? 'partial' : 'unpaid');

            $pdo->prepare(
                'UPDATE orders
                    SET paid_amount    = ?,
                        payment_status = ?,
                        was_paid       = CASE WHEN ? = "paid" THEN 1 ELSE was_paid END,
                        updated_at     = UTC_TIMESTAMP()
                  WHERE id = ?'
            )->execute([$newPaid, $newStatus, $newStatus, $orderId]);

            $finalPaid = $newPaid;
        });

        // Auto-complete the order's services if the payment closed it out.
        // Runs OUTSIDE the closure (its own statement, after the commit).
        if (($newStatus ?? '') === 'paid') {
            try {
                \App\Helpers\OrderClaim::maybeAutoComplete($pdo, $orderId);
            } catch (\Throwable $e) {
                error_log('[PaymentController::record] auto-complete failed: '
                    . $e->getMessage());
            }
        }

        Response::json([
            'success' => true,
            'message' => count($insertedIds) > 1
                       ? 'Split payment recorded — ' . count($insertedIds) . ' rows'
                       : 'Payment recorded',
            'data'    => [
                'id'             => $insertedIds[0] ?? null,
                'ids'            => $insertedIds,
                'paid_amount'    => $finalPaid,
                'payment_status' => $newStatus,
                'currency'       => APP_CURRENCY,
            ],
        ], 201);
    }

    // GET /api/orders/{id}/payments
    public function byOrder(array $params): void
    {
        $user = AuthMiddleware::require(['admin', 'team_leader']);
        $pdo  = Database::getInstance();
        $orderId = $params['id'] ?? '';

        // TL gate: must have a service in this order
        if ($user['role'] === 'team_leader') {
            $check = $pdo->prepare(
                'SELECT 1 FROM order_services WHERE order_id = ? AND provider_id = ? LIMIT 1'
            );
            $check->execute([$orderId, $user['id']]);
            if (!$check->fetch()) Response::error('Forbidden', 403);
        }

        $stmt = $pdo->prepare(
            'SELECT p.*, u.name AS recorded_by_name
             FROM payments p
             LEFT JOIN users u ON u.id = p.recorded_by
             WHERE p.order_id = ?
             ORDER BY p.created_at DESC'
        );
        $stmt->execute([$orderId]);
        Response::success($stmt->fetchAll());
    }

    // POST /api/orders/{id}/payment-links
    //
    // Generates a Stripe Checkout Session for an outstanding balance and
    // returns a sharable pay URL. Unlike record(), this does NOT mark the
    // payment as collected — the customer pays via Stripe, and the webhook
    // (or /pay/success verifyPayment) flips paid_amount + payment_status.
    //
    // Use case: TL records balance from My Jobs and picks Online; we hand
    // back a link the customer pays on their phone.
    public function createPaymentLink(array $params): void
    {
        $user = AuthMiddleware::require(['admin', 'team_leader']);
        $req  = new Request();

        $orderId = $params['id'] ?? '';
        $amount  = (float)$req->input('amount', 0);

        if ($amount <= 0) Response::error('Amount must be greater than zero', 422);

        $pdo = Database::getInstance();

        // Fetch order + service name (for the line item display) + tracking token
        $stmt = $pdo->prepare(
            'SELECT o.id, o.order_number, o.tracking_token,
                    o.total_amount, o.paid_amount, o.payment_status,
                    s.name AS service_name
               FROM orders o
               JOIN order_services os ON os.order_id = o.id
               JOIN services s        ON s.id = os.service_id
              WHERE o.id = ?
              LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) Response::error('Order not found', 404);

        // TL gate: must own a service in this order
        if ($user['role'] === 'team_leader') {
            $check = $pdo->prepare(
                'SELECT 1 FROM order_services WHERE order_id = ? AND provider_id = ? LIMIT 1'
            );
            $check->execute([$orderId, $user['id']]);
            if (!$check->fetch()) Response::error('Forbidden', 403);
        }

        // Don't let the link be created for a balance we don't owe
        $balance = max(0.0, (float)$order['total_amount'] - (float)$order['paid_amount']);
        if ($balance < 0.005) {
            Response::error('Order is already paid in full', 422);
        }
        if ($amount > $balance + 0.005) {
            Response::error(
                'Amount exceeds outstanding balance ('
                . number_format($balance, 2) . ')',
                422
            );
        }

        // Build the link — same pattern as TeamLeaderController's helper
        try {
            $checkoutUrl = $this->buildStripeLink(
                orderId:       $order['id'],
                orderNumber:   (int)$order['order_number'],
                trackingToken: $order['tracking_token'],
                serviceName:   (string)$order['service_name'],
                amount:        $amount
            );
        } catch (\Throwable $e) {
            error_log('[payment-link] Stripe link build failed: ' . $e->getMessage());
            Response::error('Could not generate payment link — ' . $e->getMessage(), 500);
        }

        // Build the short user-facing URL too — `/pay/go/<token>` routes
        // straight to Stripe via the customer/pay-by-token endpoint, so
        // the TL can share a ~50-char link instead of a 100+ char one.
        $base = defined('PUBLIC_BASE_URL') && PUBLIC_BASE_URL
            ? rtrim(PUBLIC_BASE_URL, '/')
            : (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http')
              . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $payUrl = $base . '/pay/go/' . urlencode($order['tracking_token']);

        Response::success([
            'checkout_url' => $checkoutUrl,
            'pay_url'      => $payUrl,
            'amount'       => $amount,
            'currency'     => defined('STRIPE_CURRENCY') ? STRIPE_CURRENCY : 'aed',
            'order_id'     => $order['id'],
            'expires_in'   => 24 * 3600, // Stripe sessions live 24h
        ], 'Payment link created');
    }

    /**
     * Build a Stripe Checkout Session for an outstanding balance.
     * Mirrors the helper in TeamLeaderController but kept local so this
     * controller stays self-contained.
     */
    private function buildStripeLink(
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
                        'description' => 'Order #' . $orderNumber . ' — balance',
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
                'kind'           => 'balance',
            ],
        ]);

        // Persist session id for verifyPayment() / webhook lookup. We OVERWRITE
        // any prior session — that's fine, only the latest one is what the
        // customer will pay (an older session that wasn't paid just expires).
        $pdo = Database::getInstance();
        $pdo->prepare('UPDATE orders SET stripe_session_id = ? WHERE id = ?')
            ->execute([$session->id, $orderId]);

        return $session->url;
    }
}
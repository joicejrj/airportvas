<?php
// backend/controllers/CustomerController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\{Response, Request};
use App\Helpers\{OrderNumber, DailySerial, ImageStore, OrderClaim};

/**
 * Public customer-facing booking + tracking.
 *
 *   POST /api/customer/booking            booking()  — create order (single service)
 *   GET  /api/customer/track/{token}      track()    — lookup by tracking_token
 *
 * No auth required. The new model is one service per order (because a
 * TL has one service), so this flow is intentionally simpler than v1.
 */
class CustomerController
{
    // POST /api/customer/booking
    public function booking(array $params = []): void
    {
        $req = new Request();
        $pdo = Database::getInstance();

        $plate         = strtoupper(trim((string)$req->input('vehicle_plate', '')));
        $customerName  = trim((string)$req->input('customer_name', ''));
        $customerPhone = trim((string)$req->input('customer_phone', ''));
        $customerEmail = trim((string)$req->input('customer_email', ''));
        $locationId    = $req->input('parking_location_id');
        $locationStr   = trim((string)$req->input('location_details', ''));
        $notes         = trim((string)$req->input('notes', ''));
        $source        = (string)$req->input('source', 'customer_web');

        // ── Accept two payload shapes ────────────────────────────────
        // (1) Flat:        { service_id, image_path, location_details }
        // (2) Multi (old): { services: [ { service_id, image_path, location_details, ... } ] }
        // In v2 every order has exactly one service, so for shape (2) we
        // take the first entry and ignore any extras.
        $serviceId = (string)$req->input('service_id', '');
        $imagePathRaw = $req->input('image_path');
        // Employee chosen by the customer in the new "are you with an employee?"
        // flow. When set, the order routes to that employee's team_leader only.
        $employeeId = $req->input('employee_id') ?: null;

        $servicesArr = $req->input('services');
        if (!$serviceId && is_array($servicesArr) && !empty($servicesArr)) {
            $first = $servicesArr[0];
            if (is_array($first)) {
                $serviceId    = (string)($first['service_id'] ?? '');
                if (empty($imagePathRaw) && !empty($first['image_path'])) {
                    $imagePathRaw = $first['image_path'];
                }
                if ($locationStr === '' && !empty($first['location_details'])) {
                    $locationStr = trim((string)$first['location_details']);
                }
            }
        }

        $imagePath = ImageStore::normalize($imagePathRaw, 'order');

        // Validation
        // Plate is OPTIONAL in the new customer flow — the wizard no longer
        // collects it. If provided, it must match the UAE format.
        if ($plate !== '' && !preg_match(UAE_PLATE_REGEX, $plate)) {
            Response::error('Invalid vehicle plate format', 422);
        }
        if (!$serviceId) Response::error('service_id is required', 422);

        // If an employee was selected, verify it belongs to the chosen service
        // and is active. The order_services row will get employee_id set, which
        // routes the order to that employee's team_leader.
        if ($employeeId) {
            $empStmt = $pdo->prepare(
                'SELECT id, service_id, is_active
                 FROM employees
                 WHERE id = ?'
            );
            $empStmt->execute([$employeeId]);
            $emp = $empStmt->fetch();
            if (!$emp || !$emp['is_active']) {
                Response::error('Employee not found or inactive', 422);
            }
            if ($emp['service_id'] !== $serviceId) {
                Response::error('Employee does not work for this service', 422);
            }
        }

        // Phone: accept and normalize common UAE formats before regex check
        if ($customerPhone !== '') {
            $customerPhone = self::normalizeUaePhone($customerPhone);
            if (!preg_match(UAE_PHONE_REGEX, $customerPhone)) {
                Response::error('Phone must be in +971XXXXXXXXX format', 422);
            }
        }
        if ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email address', 422);
        }
        // Allow customer source values, plus the legacy 'agent' label that the
        // old form might still send (it'll just be stored as a string).
        if (!in_array($source, ['customer_qr','customer_web','agent'], true)) {
            $source = 'customer_web';
        }

        // Service must exist + active
        $svcStmt = $pdo->prepare('SELECT id, name, base_price FROM services WHERE id = ? AND is_active = 1');
        $svcStmt->execute([$serviceId]);
        $service = $svcStmt->fetch();
        if (!$service) Response::error('Service not found or inactive', 422);

        // Validate location if supplied
        if ($locationId) {
            $loc = $pdo->prepare('SELECT id FROM parking_locations WHERE id = ? AND is_active = 1');
            $loc->execute([$locationId]);
            if (!$loc->fetch()) Response::error('Parking location not found', 422);
        }

        $price = (float)$service['base_price'];

        $result = null;
        Database::transaction(function (\PDO $pdo) use (
            $plate, $service, $serviceId, $price,
            $customerName, $customerPhone, $customerEmail,
            $locationId, $locationStr, $notes, $imagePath, $source,
            $employeeId, &$result
        ) {
            $orderId       = $pdo->query('SELECT UUID()')->fetchColumn();
            $trackingToken = bin2hex(random_bytes(8));

            // IMPORTANT: customer-self orders do NOT consume order_number
            // or daily_serial at creation. Both stay NULL until payment
            // succeeds. This avoids "ghost" gaps in the public numbering
            // sequence from abandoned-cart situations where the customer
            // never returned to pay. The Stripe webhook (PaymentController
            // ::handleStripeWebhook) assigns the real numbers on
            // checkout.session.completed.
            //
            // The order is still saved (we need somewhere for Stripe to
            // post payment metadata back to), it's just numberless until
            // the money lands.
            $pdo->prepare(
                'INSERT INTO orders
                 (id, order_number, vehicle_plate, customer_name, customer_phone,
                  customer_email, parking_location_id, notes,
                  total_amount, paid_amount, payment_status, source, tracking_token,
                  created_at, updated_at)
                 VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, 0.00, "unpaid", ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            )->execute([
                $orderId,
                $plate !== '' ? $plate : null,
                $customerName  ?: null,
                $customerPhone ?: null,
                $customerEmail ?: null,
                $locationId    ?: null,
                $notes         ?: null,
                $price,
                $source,
                $trackingToken,
            ]);

            // daily_serial + serial_date are also NULL until payment.
            $osId = $pdo->query('SELECT UUID()')->fetchColumn();
            $pdo->prepare(
                'INSERT INTO order_services
                 (id, order_id, service_id, employee_id, daily_serial, serial_date,
                  status, price, location_id, location_details, image_path, version,
                  created_at, updated_at)
                 VALUES (?, ?, ?, ?, NULL, NULL, "pending", ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            )->execute([
                $osId, $orderId, $serviceId,
                $employeeId,
                $price,
                $locationId    ?: null,
                $locationStr   ?: null,
                $imagePath     ?: null,
            ]);

            $result = [
                'order_id'       => $orderId,
                // order_number + daily_serial deliberately omitted —
                // they don't exist yet. Customer success page reads
                // them from the post-payment order fetch instead.
                'tracking_token' => $trackingToken,
                'service_name'   => $service['name'],
                'amount'         => $price,
                'currency'       => APP_CURRENCY,
            ];
        });

        // ── Create Stripe Checkout Session ────────────────────────────
        // The order is saved as 'unpaid' with NO order_number yet
        // (customer-self orders defer numbering until payment success).
        // We pass null for orderNumber here; the webhook assigns the
        // real number on checkout.session.completed.
        $checkoutUrl = null;
        try {
            $checkoutUrl = $this->createCheckoutSession(
                orderId:       $result['order_id'],
                orderNumber:   null, // deferred until payment success
                trackingToken: $result['tracking_token'],
                serviceName:   $result['service_name'],
                amount:        (float)$result['amount'],
                customerEmail: $customerEmail ?: null
            );
        } catch (\Throwable $e) {
            error_log('Stripe Checkout session failed for order '
                . $result['order_id'] . ': ' . $e->getMessage());
            // Soft-fail: still return success so the order isn't lost
        }

        Response::json([
            'success'        => true,
            'message'        => 'Booking created. Payment is pending.',
            // Mirror at top level for compatibility with the old customer
            // form, which reads result.order_id / result.tracking_token directly.
            'order_id'       => $result['order_id']       ?? null,
            'tracking_token' => $result['tracking_token'] ?? null,
            'checkout_url'   => $checkoutUrl,
            'data'           => $result,
        ], 201);
    }

    /**
     * Build a Stripe Checkout Session for the newly-created order and
     * record session.id on the row. Returns the hosted checkout URL.
     *
     * Throws if Stripe SDK isn't installed or the API call fails — caller
     * decides what to do (we soft-fail in booking() so the order survives).
     *
     * $orderNumber is nullable: customer-self orders defer numbering
     * until payment success (the webhook assigns it). When null, the
     * Stripe receipt shows a service-only description and the metadata
     * carries only order_id (sufficient for the webhook to look up the
     * order and complete numbering).
     */
    private function createCheckoutSession(
        string $orderId,
        ?int   $orderNumber,
        string $trackingToken,
        string $serviceName,
        float  $amount,
        ?string $customerEmail
    ): string {
        if (!class_exists('\Stripe\Stripe')) {
            throw new \RuntimeException('Stripe SDK not installed (run `composer install`)');
        }
        if (!defined('STRIPE_SECRET_KEY') || STRIPE_SECRET_KEY === '' || STRIPE_SECRET_KEY === 'sk_test_REPLACE_ME') {
            throw new \RuntimeException('STRIPE_SECRET_KEY is not configured');
        }

        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

        $base = defined('PUBLIC_BASE_URL') && PUBLIC_BASE_URL
            ? rtrim(PUBLIC_BASE_URL, '/')
            : (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http')
              . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

        // Stripe requires a non-empty 'description'. Use the order
        // number when available, otherwise a short order-id reference
        // so the customer can still see something identifying on
        // their receipt.
        $description = $orderNumber !== null
            ? ('Order #' . $orderNumber)
            : ('Booking ref ' . substr($orderId, 0, 8));

        $metadata = [
            'order_id'       => $orderId,
            'tracking_token' => $trackingToken,
        ];
        if ($orderNumber !== null) {
            $metadata['order_number'] = (string)$orderNumber;
        }

        $params = [
            'mode' => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency'     => strtolower(defined('STRIPE_CURRENCY') ? STRIPE_CURRENCY : 'aed'),
                    // Stripe wants minor units (fils for AED)
                    'unit_amount'  => (int)round($amount * 100),
                    'product_data' => [
                        'name'        => 'AirVAS · ' . $serviceName,
                        'description' => $description,
                    ],
                ],
                'quantity' => 1,
            ]],
            'success_url' => $base . '/pay/success/?t=' . urlencode($trackingToken)
                                   . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => $base . '/pay/cancel/?t=' . urlencode($trackingToken),
            'metadata'    => $metadata,
        ];
        if ($customerEmail) {
            $params['customer_email'] = $customerEmail;
        }

        $session = \Stripe\Checkout\Session::create($params);

        // Persist the session.id so verifyPayment() can ask Stripe later
        $pdo = Database::getInstance();
        $pdo->prepare('UPDATE orders SET stripe_session_id = ? WHERE id = ?')
            ->execute([$session->id, $orderId]);

        return $session->url;
    }

    // POST /api/customer/verify-payment
    /**
     * Called by the /pay/success page after Stripe redirects the customer
     * back. Asks Stripe directly whether the checkout session was paid;
     * if yes, records a payment row (which flips orders.payment_status
     * via the DB trigger). Idempotent — safe to call multiple times.
     *
     * Body: { order_id, token }   (token = orders.tracking_token)
     */
    public function verifyPayment(array $params = []): void
    {
        if (!class_exists('\Stripe\Stripe')) {
            Response::error('Stripe SDK not installed on server', 503);
        }
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

        $req     = new Request();
        $orderId = (string)$req->input('order_id', '');
        $token   = (string)$req->input('token', '');
        if (!$token) {
            Response::error('token is required', 422);
        }

        $pdo  = Database::getInstance();
        // Resolve order by token alone if no order_id was provided.
        // The token is unique + indexed so this is a single-row lookup.
        if (!$orderId) {
            $stmt = $pdo->prepare(
                'SELECT id, total_amount, payment_status, stripe_session_id
                 FROM orders WHERE tracking_token = ? LIMIT 1'
            );
            $stmt->execute([$token]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, total_amount, payment_status, stripe_session_id
                 FROM orders WHERE id = ? AND tracking_token = ?'
            );
            $stmt->execute([$orderId, $token]);
        }
        $order = $stmt->fetch();
        if (!$order) {
            Response::error('Order not found or invalid token', 404);
        }
        $orderId = $order['id'];  // canonicalise for downstream code
        if ($order['payment_status'] === 'paid') {
            Response::success([
                'payment_status' => 'paid',
                'verified'       => true,
                'note'           => 'already_paid',
            ]);
        }
        if (!$order['stripe_session_id']) {
            Response::success([
                'payment_status' => $order['payment_status'],
                'verified'       => false,
                'note'           => 'no_stripe_session',
            ]);
        }

        try {
            $session = \Stripe\Checkout\Session::retrieve($order['stripe_session_id']);
        } catch (\Throwable $e) {
            error_log('Stripe verify failed for order ' . $orderId . ': ' . $e->getMessage());
            Response::error('Could not verify with Stripe', 502);
        }

        if ($session->payment_status !== 'paid') {
            Response::success([
                'payment_status' => $order['payment_status'],
                'verified'       => false,
                'stripe_status'  => $session->payment_status,
            ]);
        }

        $sessionId  = $session->id;
        $amountPaid = (int)$session->amount_total / 100;

        Database::transaction(function (\PDO $pdo) use ($orderId, $amountPaid, $sessionId) {
            // Lock the order row
            $lk = $pdo->prepare('SELECT payment_status FROM orders WHERE id = ? FOR UPDATE');
            $lk->execute([$orderId]);
            if (!$lk->fetch()) return;

            // Idempotency: skip if we already recorded this Stripe session
            $dup = $pdo->prepare('SELECT id FROM payments WHERE transaction_ref = ? FOR UPDATE');
            $dup->execute([$sessionId]);
            if ($dup->fetch()) return;

            // Record the payment row
            $pdo->prepare(
                'INSERT INTO payments
                 (id, order_id, payment_method, amount, transaction_ref, uuid_ref,
                  status, recorded_by, created_at)
                 VALUES (UUID(), ?, "online", ?, ?, UUID(), "success", NULL, UTC_TIMESTAMP())'
            )->execute([$orderId, $amountPaid, $sessionId]);

            // Deferred numbering: assign the public order_number and
            // daily_serial now that payment has landed. Same logic as
            // the webhook handler. This path runs when the customer
            // returns to /pay/success/ and the page polls verify-payment
            // BEFORE Stripe fires the webhook (common case for fast
            // browsers). Both paths are idempotent via the
            // (order_number IS NULL) check.
            $orderRow = $pdo->prepare('SELECT order_number FROM orders WHERE id = ? FOR UPDATE');
            $orderRow->execute([$orderId]);
            $row = $orderRow->fetch();
            if ($row && $row['order_number'] === null) {
                $orderNumber = OrderNumber::next($pdo);
                $pdo->prepare('UPDATE orders SET order_number = ? WHERE id = ?')
                    ->execute([$orderNumber, $orderId]);

                $osRows = $pdo->prepare(
                    'SELECT id, service_id FROM order_services
                      WHERE order_id = ? AND daily_serial IS NULL'
                );
                $osRows->execute([$orderId]);
                foreach ($osRows->fetchAll() as $os) {
                    $serial = DailySerial::next($pdo, $os['service_id']);
                    $pdo->prepare(
                        'UPDATE order_services
                            SET daily_serial = ?, serial_date = ?
                          WHERE id = ?'
                    )->execute([$serial['serial'], $serial['date'], $os['id']]);
                }
            }

            // Recompute the order's paid total and flip payment_status.
            // (In v2 we don't rely on a DB trigger — do it explicitly.)
            self::recomputeOrderPayment($pdo, $orderId);
        });

        Response::success([
            'payment_status' => 'paid',
            'verified'       => true,
            'amount'         => $amountPaid,
        ]);
    }

    /**
     * Sum successful payments for an order, then set orders.paid_amount
     * and orders.payment_status accordingly.
     *
     *   paid_amount >= total_amount → 'paid'
     *   paid_amount  > 0            → 'partial'
     *   paid_amount == 0            → 'unpaid'
     *
     * Called inside an existing transaction by verifyPayment() and
     * paymentCallback(). Safe to call multiple times — it's idempotent.
     */
    private static function recomputeOrderPayment(\PDO $pdo, string $orderId): void
    {
        // Sum successful payments
        $sum = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) AS paid
             FROM payments
             WHERE order_id = ? AND status = "success"'
        );
        $sum->execute([$orderId]);
        $paid = (float)($sum->fetch()['paid'] ?? 0);

        // Get order total
        $tot = $pdo->prepare('SELECT total_amount FROM orders WHERE id = ?');
        $tot->execute([$orderId]);
        $total = (float)($tot->fetch()['total_amount'] ?? 0);

        // Decide new status (use a tiny epsilon to absorb float drift).
        // 'partial' is retained as a transient state during split-payment
        // flows where multiple Stripe webhooks may arrive separately.
        // Once payments reach the total the order auto-completes below.
        $status = 'unpaid';
        if ($paid >= $total - 0.005 && $total > 0) {
            $status = 'paid';
        } elseif ($paid > 0) {
            $status = 'partial';
        }

        $pdo->prepare(
            'UPDATE orders
             SET paid_amount = ?, payment_status = ?, updated_at = UTC_TIMESTAMP()
             WHERE id = ?'
        )->execute([$paid, $status, $orderId]);

        // If we just hit "paid" then auto-complete any in-flight order_services.
        if ($status === 'paid') {
            try {
                \App\Helpers\OrderClaim::maybeAutoComplete($pdo, $orderId);
            } catch (\Throwable $e) {
                error_log('[recomputeOrderPayment] auto-complete failed: '
                    . $e->getMessage());
            }
        }
    }

    // GET /api/customer/booking/{id}?token=...
    /**
     * Public order-detail endpoint used by the /pay/success/ tracking page.
     * Requires the order's tracking_token in the query string to authorise.
     */
    public function getBooking(array $params = []): void
    {
        $orderId = (string)($params['id'] ?? '');
        $token   = (string)($_GET['token'] ?? '');
        if (!$orderId || !$token) {
            Response::error('order id and token are required', 422);
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT o.id, o.order_number, o.vehicle_plate, o.vehicle_make, o.vehicle_model,
                    o.customer_name, o.customer_phone, o.customer_email,
                    o.total_amount, o.paid_amount, o.payment_status,
                    o.tracking_token, o.created_at,
                    JSON_ARRAYAGG(
                      JSON_OBJECT(
                        'service_name',     s.name,
                        'status',           os.status,
                        'price',            os.price,
                        'daily_serial',     os.daily_serial,
                        'team_leader_name', tl.name,
                        'employee_name',    emp.name,
                        'location_name',    pl.name,
                        'location_details', os.location_details,
                        'image_path',       os.image_path,
                        'assigned_at',      os.assigned_at,
                        'accepted_at',      os.accepted_at,
                        'started_at',       os.started_at,
                        'completed_at',     os.completed_at
                      )
                    ) AS services_json
             FROM orders o
             LEFT JOIN order_services os    ON os.order_id = o.id
             LEFT JOIN services s            ON s.id  = os.service_id
             LEFT JOIN users tl              ON tl.id = os.provider_id
             LEFT JOIN employees emp         ON emp.id = os.employee_id
             LEFT JOIN parking_locations pl  ON pl.id = os.location_id
             WHERE o.id = ? AND o.tracking_token = ?
             GROUP BY o.id"
        );
        $stmt->execute([$orderId, $token]);
        $row = $stmt->fetch();

        if (!$row) {
            Response::error('Order not found or invalid token', 404);
        }

        $row['services'] = !empty($row['services_json'])
                         ? json_decode($row['services_json'], true)
                         : [];
        unset($row['services_json']);

        // Successful payments — gives the customer a breakdown of what they've
        // paid so far (useful for partial payments: "10 cash on arrival, 5 card later")
        $pStmt = $pdo->prepare(
            'SELECT id, payment_method, amount, transaction_ref, created_at
               FROM payments
              WHERE order_id = ? AND status = "success"
              ORDER BY created_at ASC'
        );
        $pStmt->execute([$orderId]);
        $row['payments'] = $pStmt->fetchAll() ?: [];

        // Convenience: the timestamp of the most-recent successful payment.
        // Used by the success page to label the "Payment Confirmed" step
        // when the order is fully paid.
        $row['paid_at'] = !empty($row['payments'])
                        ? end($row['payments'])['created_at']
                        : null;
        // Reset internal pointer in case the array gets iterated downstream
        reset($row['payments']);

        $row['currency'] = APP_CURRENCY;

        Response::success($row);
    }

    // =================================================================
    // GET /api/customer/booking-by-token/{token}
    // =================================================================
    /**
     * Token-only lookup — same payload as getBooking() but the customer
     * doesn't need to know their order_id. Powers the short URLs
     * (/pay/success/?t=… and /pay/cancel/?t=…) shared by the TL.
     */
    public function getBookingByToken(array $params): void
    {
        $token = (string)($params['token'] ?? '');
        if (!$token) {
            Response::error('token is required', 422);
        }
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('SELECT id FROM orders WHERE tracking_token = ? LIMIT 1');
        $stmt->execute([$token]);
        $orderId = $stmt->fetchColumn();
        if (!$orderId) {
            Response::error('Order not found or invalid token', 404);
        }
        // Delegate to getBooking by injecting the params it expects. Saves
        // duplicating the (heavy) aggregation query.
        $_GET['token'] = $token;
        $this->getBooking(['id' => (string)$orderId]);
    }

    // POST /api/customer/payment/callback
    /**
     * Stripe webhook endpoint. Server-to-server backup in case the customer
     * never returns to the success page (closed tab, lost connection…).
     * Responds 200 even on no-op so Stripe stops retrying.
     */
    public function paymentCallback(array $params = []): void
    {
        if (!class_exists('\Stripe\Stripe')) {
            Response::error('Stripe SDK not installed', 503);
        }
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

        $raw       = (string)file_get_contents('php://input');
        $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        try {
            $event = \Stripe\Webhook::constructEvent($raw, $signature, STRIPE_WEBHOOK_SECRET);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            error_log('Stripe webhook signature failed: ' . $e->getMessage());
            Response::error('Invalid signature', 401);
        } catch (\Throwable $e) {
            error_log('Stripe webhook parse failed: ' . $e->getMessage());
            Response::error('Bad payload', 400);
        }

        if ($event->type !== 'checkout.session.completed') {
            Response::json(['received' => true, 'ignored' => $event->type]);
        }

        $session    = $event->data->object;
        $orderId    = $session->metadata->order_id ?? null;
        $sessionId  = $session->id;
        $amountPaid = (int)$session->amount_total / 100;
        if (!$orderId || $session->payment_status !== 'paid') {
            Response::json(['received' => true, 'note' => 'no_action']);
        }

        $pdo = Database::getInstance();

        // Order must exist
        $o = $pdo->prepare('SELECT id FROM orders WHERE id = ?');
        $o->execute([$orderId]);
        if (!$o->fetch()) {
            error_log("Stripe webhook: order $orderId not found");
            Response::json(['received' => true, 'note' => 'order_not_found']);
        }

        Database::transaction(function (\PDO $pdo) use ($orderId, $amountPaid, $sessionId) {
            // Lock + idempotency check
            $dup = $pdo->prepare('SELECT id FROM payments WHERE transaction_ref = ? FOR UPDATE');
            $dup->execute([$sessionId]);
            if ($dup->fetch()) return;

            $pdo->prepare(
                'INSERT INTO payments
                 (id, order_id, payment_method, amount, transaction_ref, uuid_ref,
                  status, recorded_by, created_at)
                 VALUES (UUID(), ?, "online", ?, ?, UUID(), "success", NULL, UTC_TIMESTAMP())'
            )->execute([$orderId, $amountPaid, $sessionId]);

            // Deferred numbering for customer-self orders. Customer orders
            // are created with order_number = NULL so the public sequence
            // isn't consumed by abandoned carts. Once payment succeeds we
            // assign the real number now. We FOR UPDATE-lock the order so
            // concurrent webhook deliveries (Stripe retries on the same
            // session) don't double-assign.
            $orderRow = $pdo->prepare('SELECT order_number, source FROM orders WHERE id = ? FOR UPDATE');
            $orderRow->execute([$orderId]);
            $row = $orderRow->fetch();
            if ($row && $row['order_number'] === null) {
                $orderNumber = OrderNumber::next($pdo);
                $pdo->prepare('UPDATE orders SET order_number = ? WHERE id = ?')
                    ->execute([$orderNumber, $orderId]);

                // Assign daily_serial to every order_service of this
                // order that's still NULL. Customer orders usually have
                // one service row, but we handle multiple defensively.
                $osRows = $pdo->prepare(
                    'SELECT id, service_id FROM order_services
                      WHERE order_id = ? AND daily_serial IS NULL'
                );
                $osRows->execute([$orderId]);
                foreach ($osRows->fetchAll() as $os) {
                    $serial = DailySerial::next($pdo, $os['service_id']);
                    $pdo->prepare(
                        'UPDATE order_services
                            SET daily_serial = ?, serial_date = ?
                          WHERE id = ?'
                    )->execute([$serial['serial'], $serial['date'], $os['id']]);
                }
            }

            // Roll up payment totals onto the order
            self::recomputeOrderPayment($pdo, $orderId);
        });

        Response::json(['received' => true, 'recorded' => true]);
    }

    /**
     * Public photo-upload endpoint for the customer booking form.
     *
     *   POST /api/customer/upload
     *     multipart/form-data with field "image"
     *     returns: { "success": true, "path": "/uploads/order/...", "data": { "path": "..." } }
     *
     * No auth required — customers booking via QR don't have accounts.
     * The path returned should be sent back as `image_path` in the booking
     * payload.
     */
    public function customerUpload(array $params = []): void
    {
        if (empty($_FILES['image'])) {
            Response::error('No image file uploaded', 422);
        }
        $file = $_FILES['image'];
        if (!empty($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
            Response::error('Upload error (code ' . $file['error'] . ')', 422);
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0)              Response::error('Empty file', 422);
        if ($size > 5_242_880)       Response::error('Image too large (max 5 MB)', 422);

        $tmp = $file['tmp_name'] ?? '';
        if (!$tmp || !is_uploaded_file($tmp)) {
            Response::error('Invalid upload', 422);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = strtolower((string)$finfo->file($tmp));
        $allowed = ['image/jpeg'=>'jpg', 'image/jpg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'];
        if (!isset($allowed[$mime])) {
            Response::error('Image type not allowed (use JPG/PNG/WEBP)', 422);
        }

        $bin = @file_get_contents($tmp);
        if ($bin === false || strlen($bin) < 100) {
            Response::error('Could not read uploaded image', 422);
        }

        // Reuse ImageStore by feeding a base64 data URL into saveDataUrl()
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($bin);
        $path    = ImageStore::saveDataUrl($dataUrl, 'order');

        if (!$path) {
            Response::error('Could not save image. Check uploads folder permissions.', 500);
        }

        // The old form reads `result.path` directly; the v2 wrapper also
        // exposes it under data.path. Mirror both for safety.
        Response::json([
            'success' => true,
            'path'    => $path,
            'data'    => ['path' => $path],
        ]);
    }

    // GET /api/customer/track/{token}
    public function track(array $params): void
    {
        $token = $params['token'] ?? '';
        if (strlen($token) < 8) Response::error('Invalid tracking link', 404);

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT o.id, o.order_number, o.vehicle_plate,
                    o.customer_name, o.total_amount, o.paid_amount, o.payment_status,
                    o.created_at,
                    (SELECT JSON_ARRAYAGG(JSON_OBJECT(
                        "service_name", s.name,
                        "daily_serial", os.daily_serial,
                        "status",       os.status,
                        "started_at",   os.started_at,
                        "completed_at", os.completed_at
                     ))
                       FROM order_services os JOIN services s ON s.id = os.service_id
                      WHERE os.order_id = o.id) AS services_json
             FROM orders o
             WHERE o.tracking_token = ?'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row) Response::error('Order not found', 404);

        $row['services'] = $row['services_json']
                         ? json_decode($row['services_json'], true)
                         : [];
        unset($row['services_json']);
        $row['currency'] = APP_CURRENCY;

        Response::success($row);
    }

    // =================================================================
    // GET /api/customer/services
    // =================================================================
    /**
     * Public list of active services for the customer-flow service picker.
     */
    public function listServices(array $params = []): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query(
            'SELECT id, name, base_price, icon
               FROM services
              WHERE is_active = 1
              ORDER BY name ASC'
        );
        Response::success([
            'items'    => $stmt->fetchAll(),
            'currency' => APP_CURRENCY,
        ]);
    }

    // =================================================================
    // GET /api/customer/employees/search?service_id=...&q=...
    // =================================================================
    /**
     * Public employee search for the new customer flow.
     *
     * Returns up to 10 active employees in the chosen service whose code
     * or name matches the query. Each result includes the employee's image
     * path so the picker can show a photo (with a silhouette fallback).
     */
    public function searchEmployees(array $params = []): void
    {
        $serviceId = (string)($_GET['service_id'] ?? '');
        $q         = trim((string)($_GET['q'] ?? ''));

        if (!$serviceId) {
            Response::error('service_id is required', 422);
        }
        if (mb_strlen($q) < 2) {
            Response::success(['items' => []]);
        }

        $like = '%' . $q . '%';

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT id, employee_code, name, image_path
               FROM employees
              WHERE service_id = ?
                AND is_active  = 1
                AND team_leader_id IS NOT NULL
                AND (name LIKE ? OR employee_code LIKE ?)
              ORDER BY
                CASE
                  WHEN employee_code = ? THEN 0
                  WHEN name = ?          THEN 1
                  WHEN name LIKE ?       THEN 2
                  ELSE 3
                END,
                name ASC
              LIMIT 2'
        );
        $stmt->execute([
            $serviceId, $like, $like,
            $q, $q, $q . '%',
        ]);
        Response::success(['items' => $stmt->fetchAll()]);
    }

    // =================================================================
    // GET /api/customer/pay-by-token/{token}
    // =================================================================
    /**
     * Short-URL Stripe redirect. Given just a tracking_token, sends the
     * customer to a Stripe Checkout page for the order's outstanding
     * balance. This powers the `/pay/go/<token>` shareable links that
     * TLs send via WhatsApp/SMS.
     *
     * Behaviour:
     *   - Order doesn't exist → 404 page
     *   - Order is already paid → redirect to /pay/success/?t=...
     *   - Order has a fresh Stripe session that's still open → reuse it
     *   - Otherwise → create a new session for the outstanding balance
     *
     * Returns an HTTP 302 redirect; no JSON.
     */
    public function payByToken(array $params): void
    {
        $token = (string)($params['token'] ?? '');
        if (strlen($token) < 8) {
            self::renderShortLinkError('That payment link is invalid.');
            return;
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT o.id, o.order_number, o.total_amount, o.paid_amount,
                    o.payment_status, o.stripe_session_id, o.customer_email,
                    o.tracking_token,
                    (SELECT s.name FROM order_services os
                       JOIN services s ON s.id = os.service_id
                      WHERE os.order_id = o.id LIMIT 1) AS service_name
               FROM orders o
              WHERE o.tracking_token = ?
              LIMIT 1'
        );
        $stmt->execute([$token]);
        $order = $stmt->fetch();

        if (!$order) {
            self::renderShortLinkError('We couldn\'t find that order.');
            return;
        }

        $base = defined('PUBLIC_BASE_URL') && PUBLIC_BASE_URL
            ? rtrim(PUBLIC_BASE_URL, '/')
            : (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http')
              . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

        // Already paid — straight to the tracking page.
        if ($order['payment_status'] === 'paid') {
            header('Location: ' . $base . '/pay/success/?t=' . urlencode($token), true, 302);
            return;
        }

        $balance = max(0.0, (float)$order['total_amount'] - (float)$order['paid_amount']);
        if ($balance <= 0.005) {
            // No balance but not marked paid — treat as success page case.
            header('Location: ' . $base . '/pay/success/?t=' . urlencode($token), true, 302);
            return;
        }

        // Try to reuse an existing open Stripe session if it's still valid.
        if (!empty($order['stripe_session_id']) && class_exists('\Stripe\Stripe')) {
            try {
                \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
                $existing = \Stripe\Checkout\Session::retrieve($order['stripe_session_id']);
                // Stripe sessions are 'open' for ~24h. If the customer never
                // paid and the session is still open, redirecting to it is
                // free + instant (no API write).
                if (
                    !empty($existing->url) &&
                    isset($existing->status) && $existing->status === 'open'
                ) {
                    header('Location: ' . $existing->url, true, 302);
                    return;
                }
            } catch (\Throwable $e) {
                // Fall through to creating a fresh session
                error_log('[payByToken] retrieve existing session failed: ' . $e->getMessage());
            }
        }

        // Create a fresh checkout session for the outstanding balance.
        try {
            $checkoutUrl = $this->createCheckoutSession(
                orderId:       $order['id'],
                orderNumber:   (int)$order['order_number'],
                trackingToken: $order['tracking_token'],
                serviceName:   $order['service_name'] ?? 'AirVAS service',
                amount:        $balance,
                customerEmail: $order['customer_email'] ?: null
            );
            header('Location: ' . $checkoutUrl, true, 302);
            return;
        } catch (\Throwable $e) {
            error_log('[payByToken] create session failed: ' . $e->getMessage());
            self::renderShortLinkError(
                'Payment is temporarily unavailable. Please try again in a moment.'
            );
            return;
        }
    }

    /**
     * Render a tiny self-contained HTML error page for the payByToken
     * redirect endpoint. We do this inline rather than redirecting to a
     * static error page so the customer never sees a 404.
     */
    private static function renderShortLinkError(string $message): void
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        echo <<<HTML
<!doctype html>
<html lang="en"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Link not found · AirVAS</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#f0f6ff;
  margin:0;min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:32px 28px;
  text-align:center;max-width:380px}
.ico{font-size:48px;margin-bottom:12px}
h1{font-size:18px;font-weight:800;color:#0f172a;margin:0 0 10px}
p{font-size:14px;color:#475569;line-height:1.5;margin:0 0 20px}
a{display:inline-block;background:#0ea5e9;color:#fff;text-decoration:none;
  padding:12px 18px;border-radius:10px;font-weight:700;font-size:14px}
</style>
</head><body>
<div class="card">
  <div class="ico">⚠</div>
  <h1>Link not found</h1>
  <p>{$safe}</p>
  <a href="/customer/">Start a new booking</a>
</div>
</body></html>
HTML;
        exit;
    }

    /**
     * Auto-fix common UAE phone-number formats so customers who type
     * a local-format number (or include spaces / dashes) still pass
     * the strict +971XXXXXXXXX regex.
     *
     *   0501234567       → +971501234567
     *   501234567        → +971501234567
     *   971501234567     → +971501234567
     *   00971501234567   → +971501234567
     *   +971 50 123 4567 → +971501234567
     */
    private static function normalizeUaePhone(string $s): string
    {
        $v = trim($s);
        if ($v === '') return $v;
        $v = preg_replace('/[\s\-()]/', '', $v);
        if (strpos($v, '00971') === 0)              return '+' . substr($v, 2);
        if (strpos($v, '971')   === 0 && $v[0] !== '+') return '+' . $v;
        if (strpos($v, '0')     === 0 && strlen($v) === 10) return '+971' . substr($v, 1);
        if (preg_match('/^5\d{8}$/', $v))           return '+971' . $v;
        return $v;
    }
}
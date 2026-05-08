<?php
// backend/controllers/CustomerController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\{Response, Request};
use App\Helpers\{AssignmentEngine, NotificationService};

/**
 * Customer-facing public API (no auth required — public booking flow).
 *
 * POST /api/customer/booking              – create booking
 * GET  /api/customer/booking/{id}         – track booking
 * POST /api/customer/payment/callback     – payment gateway callback
 */
class CustomerController
{
    // ── POST /api/customer/booking ────────────────────────────────
    public function createBooking(): void
    {
        $req = new Request();
        $pdo = Database::getInstance();

        // ── Validate ──
        $plate      = strtoupper(trim((string)$req->input('vehicle_plate', '')));
        $locationId = $req->input('location_id');
        $services   = $req->input('services'); // [{service_id, location_details, image_path}]
        $email      = trim((string)$req->input('email', ''));
        $phone      = trim((string)$req->input('phone', ''));

        if (!$plate) {
            Response::error('vehicle_plate is required', 422);
        }
        if (!$locationId) {
            Response::error('location_id is required', 422);
        }
        if (empty($services) || !is_array($services)) {
            Response::error('At least one service is required', 422);
        }
        if (!$email && !$phone) {
            Response::error('email or phone is required for booking confirmation', 422);
        }

        Database::transaction(function ($pdo) use ($req, $plate, $locationId, $services, $email, $phone) {
            // Verify location
            $locStmt = $pdo->prepare('SELECT id FROM parking_locations WHERE id = ? AND is_active = 1');
            $locStmt->execute([$locationId]);
            if (!$locStmt->fetch()) {
                Response::error('Invalid or inactive parking location', 422);
            }

            // Fetch service prices
            $svcIds       = array_column($services, 'service_id');
            $placeholders = implode(',', array_fill(0, count($svcIds), '?'));
            $svcStmt      = $pdo->prepare(
                "SELECT id, name, price FROM services
                 WHERE id IN ({$placeholders}) AND is_active = 1"
            );
            $svcStmt->execute($svcIds);
            $catalogue = [];
            foreach ($svcStmt->fetchAll() as $row) {
                $catalogue[$row['id']] = $row;
            }

            // Validate all services exist
            foreach ($svcIds as $sid) {
                if (!isset($catalogue[$sid])) {
                    Response::error("Service {$sid} not found or inactive", 422);
                }
            }

            $totalAmount = array_sum(array_column($catalogue, 'price'));
            $orderId     = $this->generateUUID();

            // Create order
            $pdo->prepare(
                'INSERT INTO orders
                 (id, vehicle_plate, vehicle_make, vehicle_color,
                  parking_location_id, customer_email, customer_phone,
                  customer_name, notes, total_amount, paid_amount,
                  payment_status, created_by, source, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, "unpaid", NULL, "customer", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            )->execute([
                $orderId,
                $plate,
                $req->input('vehicle_make'),
                $req->input('vehicle_color'),
                $locationId,
                $email ?: null,
                $phone ?: null,
                trim((string)$req->input('customer_name', '')),
                $req->input('notes'),
                $totalAmount,
            ]);

            // Create order_services
            $engine      = new AssignmentEngine();
            $svcInsert   = $pdo->prepare(
                'INSERT INTO order_services
                 (id, order_id, service_id, status, price,
                  location_details, image_path, version, created_at, updated_at)
                 VALUES (UUID(), ?, ?, "pending", ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );

            foreach ($services as $svcInput) {
                $sid = $svcInput['service_id'];
                $svcInsert->execute([
                    $orderId,
                    $sid,
                    $catalogue[$sid]['price'],
                    $svcInput['location_details'] ?? null,
                    $svcInput['image_path']        ?? null,
                ]);

                // Fetch the newly created os id for assignment
                $osId = $pdo->prepare(
                    'SELECT id FROM order_services
                     WHERE order_id = ? AND service_id = ?
                     ORDER BY created_at DESC LIMIT 1'
                );
                $osId->execute([$orderId, $sid]);
                $osRow = $osId->fetch();
                if ($osRow) {
                    $engine->assign($osRow['id']);
                }
            }

            // Generate a short tracking token for the customer
            $trackingToken = bin2hex(random_bytes(8));
            $pdo->prepare('UPDATE orders SET tracking_token = ? WHERE id = ?')
                ->execute([$trackingToken, $orderId]);

            Response::json([
                'success'        => true,
                'order_id'       => $orderId,
                'tracking_token' => $trackingToken,
                'total_amount'   => $totalAmount,
                'service_count'  => count($services),
                'payment_url'    => PAYMENT_GATEWAY_URL . '?order=' . $orderId . '&token=' . $trackingToken,
            ], 201);
        });
    }

    // ── GET /api/customer/booking/{id} ────────────────────────────
    public function getBooking(array $params): void
    {
        $pdo   = Database::getInstance();
        $token = $_GET['token'] ?? '';

        $stmt = $pdo->prepare(
            'SELECT o.id, o.vehicle_plate, o.vehicle_make, o.vehicle_color,
                    o.total_amount, o.paid_amount, o.payment_status,
                    o.created_at, pl.name AS location_name, pl.code AS location_code,
                    o.customer_name, o.customer_email
             FROM orders o
             LEFT JOIN parking_locations pl ON pl.id = o.parking_location_id
             WHERE o.id = ? AND o.tracking_token = ?'
        );
        $stmt->execute([$params['id'], $token]);
        $order = $stmt->fetch();

        if (!$order) {
            Response::error('Booking not found or invalid token', 404);
        }

        // Attach service statuses (no sensitive fields)
        $svcs = $pdo->prepare(
            'SELECT os.id, s.name AS service_name, os.status,
                    os.started_at, os.completed_at
             FROM order_services os
             JOIN services s ON s.id = os.service_id
             WHERE os.order_id = ?
             ORDER BY os.created_at ASC'
        );
        $svcs->execute([$params['id']]);
        $order['services'] = $svcs->fetchAll();

        Response::success($order);
    }

    // ── POST /api/customer/payment/callback ───────────────────────
    /**
     * Called by payment gateway (e.g. Stripe/Razorpay webhook).
     * Expects: order_id, transaction_ref, amount, status, gateway_signature
     */
    public function paymentCallback(): void
    {
        $req = new Request();
        $pdo = Database::getInstance();

        // ── Signature verification (gateway-specific) ──
        $signature  = $_SERVER['HTTP_X_GATEWAY_SIGNATURE'] ?? '';
        $rawBody    = file_get_contents('php://input');
        $expected   = hash_hmac('sha256', $rawBody, PAYMENT_WEBHOOK_SECRET);

        if (!hash_equals($expected, $signature)) {
            Response::error('Invalid signature', 401);
        }

        $orderId   = $req->input('order_id');
        $txnRef    = $req->input('transaction_ref');
        $amount    = (float)$req->input('amount', 0);
        $status    = $req->input('status'); // success / failed

        if (!$orderId || !$txnRef || $amount <= 0 || !in_array($status, ['success', 'failed'], true)) {
            Response::error('Invalid callback payload', 422);
        }

        Database::transaction(function ($pdo) use ($orderId, $txnRef, $amount, $status) {
            // Idempotency — skip if already recorded
            $dup = $pdo->prepare('SELECT id FROM payments WHERE transaction_ref = ?');
            $dup->execute([$txnRef]);
            if ($dup->fetch()) {
                Response::success(['note' => 'already_processed']);
            }

            // Verify order
            $order = $pdo->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE');
            $order->execute([$orderId]);
            if (!$order->fetch()) {
                Response::error('Order not found', 404);
            }

            $uuidRef = $this->generateUUID();
            $pdo->prepare(
                'INSERT INTO payments
                 (id, order_id, payment_method, amount, transaction_ref, uuid_ref,
                  status, recorded_by, created_at)
                 VALUES (UUID(), ?, "online", ?, ?, ?, ?, "gateway", UTC_TIMESTAMP())'
            )->execute([$orderId, round($amount, 2), $txnRef, $uuidRef, $status]);

            if ($status === 'success') {
                (new NotificationService())->dispatch(
                    $orderId,
                    'payment_received',
                    "Online payment of ₹{$amount} confirmed for order #{$orderId}"
                );
            }

            Response::success(['recorded' => true]);
        });
    }

    // ── Helper ────────────────────────────────────────────────────
    private function generateUUID(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

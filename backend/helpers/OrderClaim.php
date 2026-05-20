<?php
// backend/helpers/OrderClaim.php

declare(strict_types=1);

namespace App\Helpers;

use App\Config\Database;
use PDO;
use RuntimeException;

/**
 * Order-Service claim engine for Team Leaders.
 *
 * Replaces v1's AssignmentEngine. Three operations:
 *
 *   1) accept($svcId, $tlId)
 *      A TL claims a paid+unassigned job from the Available list.
 *      Race-safe: only one TL wins; others get OrderClaimResult::ALREADY_TAKEN.
 *
 *   2) reject($svcId, $tlId)
 *      The TL hides this job from their own Available list.
 *      No effect on other TLs. No reassignment, no status change.
 *
 *   3) directBook(...)
 *      Direct booking flow — TL types in the customer + employee +
 *      service info, collects payment, and the order is created and
 *      assigned to that TL in one transaction.
 *
 * ─── Race-safety contract ────────────────────────────────────────────
 * accept() relies on a single UPDATE statement with a strict WHERE
 * clause. MySQL row-locks the matched row for the duration of the
 * statement, so if two TLs hit accept() at the same millisecond,
 * exactly one UPDATE will match (rowCount = 1) and the other will
 * find status != 'pending' and match nothing (rowCount = 0).
 *
 * No SELECT … FOR UPDATE is needed because the UPDATE itself is the
 * decision point.
 */
class OrderClaim
{
    public const OK             = 'ok';
    public const ALREADY_TAKEN  = 'already_taken';
    public const NOT_PAID       = 'not_paid';
    public const WRONG_SERVICE  = 'wrong_service';
    public const NOT_FOUND      = 'not_found';
    public const INVALID_STATE  = 'invalid_state';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    // =================================================================
    // accept — atomic claim
    // =================================================================
    /**
     * The TL claims an order_service from the Available list.
     *
     * @return array{ok: bool, code: string, message: string}
     */
    public function accept(string $svcId, string $tlId): array
    {
        // Fetch TL's service_id so we can verify the job belongs to it.
        $tl = $this->fetchTeamLeader($tlId);
        if (!$tl) {
            return self::fail(self::NOT_FOUND, 'Team leader not found or inactive');
        }
        if (!$tl['service_id']) {
            return self::fail(self::WRONG_SERVICE, 'No service assigned to your account');
        }

        // The whole accept logic is one statement. Either rowCount=1
        // (we won) or rowCount=0 (someone else won or order is not in
        // the right state).
        //
        // Routing rules:
        //   - service_id must match the TL's service (existing)
        //   - payment_status must be "paid" (existing)
        //   - if order_services.employee_id is set (customer-with-employee
        //     flow), that employee's team_leader_id must match this TL.
        //     Customer-without-employee orders (employee_id IS NULL) are
        //     still open to any TL of the matching service.
        $stmt = $this->pdo->prepare(
            'UPDATE order_services os
             JOIN   orders o ON o.id = os.order_id
             LEFT JOIN employees e ON e.id = os.employee_id
             SET    os.provider_id = :tl_id,
                    os.status      = "accepted",
                    os.assigned_at = COALESCE(os.assigned_at, UTC_TIMESTAMP()),
                    os.accepted_at = UTC_TIMESTAMP(),
                    os.version     = os.version + 1,
                    os.updated_at  = UTC_TIMESTAMP()
             WHERE  os.id            = :svc_id
               AND  os.status        = "pending"
               AND  os.provider_id  IS NULL
               AND  os.service_id    = :service_id
               AND  o.payment_status = "paid"
               AND  (os.employee_id IS NULL OR e.team_leader_id = :tl_id_route)'
        );
        $stmt->execute([
            ':tl_id'       => $tlId,
            ':svc_id'      => $svcId,
            ':service_id'  => $tl['service_id'],
            ':tl_id_route' => $tlId,
        ]);

        if ($stmt->rowCount() === 1) {
            // Order is required to be 'paid' for accept to succeed (see
            // the WHERE clause above). For customer-self orders the
            // customer already paid via Stripe BEFORE the TL accepted —
            // so the job goes straight from pending → accepted → completed
            // right here, with no Record-Payment step in between.
            //
            // For TL-side orders, this is a no-op (the customer pays
            // AFTER the TL accepts; auto-complete runs again at that
            // payment-recording moment).
            //
            // We need to know the order_id to pass to maybeAutoComplete.
            // Look it up by the service-id we just accepted.
            try {
                $oid = $this->pdo->prepare(
                    'SELECT order_id FROM order_services WHERE id = ?'
                );
                $oid->execute([$svcId]);
                $orderId = (string)$oid->fetchColumn();
                if ($orderId !== '') {
                    self::maybeAutoComplete($this->pdo, $orderId);
                }
            } catch (\Throwable $e) {
                // Soft-fail: accept already succeeded; auto-complete
                // can be retried via the next payment write or manually.
                error_log('[OrderClaim::accept] auto-complete failed: ' . $e->getMessage());
            }
            return self::ok('Job accepted');
        }

        // Diagnose why we missed — useful for the toast message.
        return $this->diagnoseAcceptFailure($svcId, $tl['service_id']);
    }

    // =================================================================
    // reject — hide for this TL only
    // =================================================================
    /**
     * Append the TL's id to order_services.rejected_by so the Available
     * list query filters this row out for this TL. Other TLs of the
     * same service continue to see it normally.
     *
     * Idempotent — re-rejecting is a no-op (we use JSON_SET on a
     * computed array that includes the id only once).
     */
    public function reject(string $svcId, string $tlId): array
    {
        // Only allow rejecting rows still pending — once accepted, the
        // owning TL has start/complete buttons, not reject.
        $check = $this->pdo->prepare(
            'SELECT os.status, os.provider_id, os.service_id, os.employee_id,
                    u.service_id AS tl_service
             FROM   order_services os
             JOIN   users u ON u.id = :tl_id
             WHERE  os.id = :svc_id'
        );
        $check->execute([':svc_id' => $svcId, ':tl_id' => $tlId]);
        $row = $check->fetch();

        if (!$row) {
            return self::fail(self::NOT_FOUND, 'Service not found');
        }
        if ($row['status'] !== 'pending' || $row['provider_id'] !== null) {
            return self::fail(self::INVALID_STATE, 'Job is no longer available');
        }
        if ($row['service_id'] !== $row['tl_service']) {
            return self::fail(self::WRONG_SERVICE, 'Not your service');
        }
        // Customer-with-employee orders cannot be rejected — the customer
        // explicitly picked this TL's employee, so the TL owns it. Rejecting
        // would orphan a paid order (no other TL can see it because of the
        // routing constraint in available()). The TL must accept and serve;
        // genuinely off-shift cases are handled by admin reassignment.
        if (!empty($row['employee_id'])) {
            return self::fail(
                self::INVALID_STATE,
                'This order was booked by a customer for your employee — you must accept it.'
            );
        }

        // Append-if-not-present using JSON functions. MariaDB & MySQL 5.7+ both
        // support these. We keep rejected_by as a JSON array of strings.
        $upd = $this->pdo->prepare(
            'UPDATE order_services
             SET rejected_by = (
                   CASE
                     WHEN rejected_by IS NULL
                       THEN JSON_ARRAY(:tl_id)
                     WHEN JSON_CONTAINS(rejected_by, JSON_QUOTE(:tl_id)) = 1
                       THEN rejected_by
                     ELSE JSON_ARRAY_APPEND(rejected_by, "$", :tl_id)
                   END
                 ),
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :svc_id'
        );
        $upd->execute([':tl_id' => $tlId, ':svc_id' => $svcId]);

        return self::ok('Job rejected (hidden from your list)');
    }

    // =================================================================
    // directBook — TL takes a new order on the spot
    // =================================================================
    /**
     * Creates the order + order_service + payment (optional) and assigns
     * to the TL — all in one transaction.
     *
     * $input expected keys:
     *   vehicle_plate   string  (required)
     *   customer_name   string|null
     *   customer_phone  string|null
     *   customer_email  string|null
     *   employee_id     string  (required, must belong to TL's team)
     *   location_id     string|null
     *   location_details string|null
     *   image_path      string|null
     *   notes           string|null
     *   payment_method  'cash'|'card'|'online' (required)
     *   payment_ref     string|null
     *   payment_uuid    string  (idempotency key, required)
     *
     * @return array{ok: bool, code: string, message: string, data?: array}
     */
    public function directBook(string $tlId, array $input): array
    {
        $tl = $this->fetchTeamLeader($tlId);
        if (!$tl || !$tl['service_id']) {
            return self::fail(self::NOT_FOUND, 'Team leader or service not found');
        }

        $serviceId  = $tl['service_id'];
        $employeeId = $input['employee_id'] ?? null;

        // Validate employee belongs to this TL.
        $emp = $this->pdo->prepare(
            'SELECT id, name, service_id, team_leader_id, is_active
             FROM employees WHERE id = ?'
        );
        $emp->execute([$employeeId]);
        $employee = $emp->fetch();
        if (!$employee || !$employee['is_active']) {
            return self::fail(self::NOT_FOUND, 'Employee not found or inactive');
        }
        if ($employee['service_id'] !== $serviceId) {
            return self::fail(self::WRONG_SERVICE, 'Employee is not on your service');
        }
        if ($employee['team_leader_id'] !== $tlId) {
            return self::fail(self::WRONG_SERVICE, 'Employee is not in your team');
        }

        // Fetch service price
        $svcStmt = $this->pdo->prepare(
            'SELECT id, name, base_price FROM services WHERE id = ? AND is_active = 1'
        );
        $svcStmt->execute([$serviceId]);
        $service = $svcStmt->fetch();
        if (!$service) {
            return self::fail(self::NOT_FOUND, 'Service not active');
        }

        $price = (float)$service['base_price'];

        // Resolve the payment scenario:
        //   cash / card      → collected on the spot, full amount, order = paid
        //   online           → Stripe link will be created by the controller,
        //                      no payment row yet, order = unpaid
        //   unpaid           → order created without any payment, status = unpaid
        //                      (TL collects later from the My Jobs tab)
        //   split            → mix of cash + card + online; cash/card rows
        //                      inserted immediately, online portion drives a
        //                      Stripe link. Sum must equal price.
        //
        // NOTE: 'partial' has been removed from the project. Orders are either
        // unpaid or paid (eventually). The Split flow records multiple payment
        // rows totalling the full price — still a "paid" outcome (or partial,
        // if there's an online portion still pending the Stripe webhook).
        $method = (string)($input['payment_method'] ?? 'cash');

        // Remember the original intent — for split, this stays 'split' so the
        // success screen can show the breakdown back to the TL.
        $originalMethod = $method;

        // splitRows is normalised to: [{method: cash|card|online, amount: N}, ...]
        // with amount > 0. The online row (at most one) is pulled out separately
        // because it does NOT get a payment row — it becomes a Stripe link instead.
        $splitRows         = [];
        $splitOnlineAmount = 0.0;
        $splitImmediateSum = 0.0; // sum of cash/card portions

        if ($method === 'split') {
            $raw = $input['split_payments'] ?? null;
            if (!is_array($raw) || empty($raw)) {
                return self::fail(self::INVALID_STATE, 'split_payments array is required for split method');
            }
            $totalSplit = 0.0;
            foreach ($raw as $row) {
                $rm = (string)($row['method'] ?? '');
                $ra = (float)($row['amount'] ?? 0);
                // Online is no longer allowed in split — the New Order
                // wizard's "Online" method is the path for Stripe links.
                // Split is for in-person collection only (cash + card).
                if (!in_array($rm, ['cash', 'card'], true)) {
                    return self::fail(self::INVALID_STATE, 'Each split row must have method = cash or card');
                }
                if ($ra <= 0) continue;  // skip empty rows
                $totalSplit += $ra;
                $splitRows[] = ['method' => $rm, 'amount' => $ra];
                $splitImmediateSum += $ra;
            }
            if (empty($splitRows) && $splitOnlineAmount <= 0) {
                return self::fail(self::INVALID_STATE, 'At least one split row must have an amount > 0');
            }
            if (abs($totalSplit - $price) > 0.005) {
                return self::fail(
                    self::INVALID_STATE,
                    'Split total ' . number_format($totalSplit, 2)
                    . ' must equal order total ' . number_format($price, 2)
                );
            }
        }

        // What goes in the payments table (single-method path; null = no row)
        $insertPayMethod = null;
        $insertPayAmount = 0.0;
        if ($method === 'cash' || $method === 'card') {
            $insertPayMethod = $method;
            $insertPayAmount = $price;
        }
        // 'online', 'unpaid', and 'split' produce no row here — split inserts
        // its own rows from $splitRows inside the transaction below.

        // Final order-header values derived from the payment scenario
        if ($method === 'cash' || $method === 'card') {
            $orderPaidAmount   = $price;
            $orderPaymentState = 'paid';
            $orderWasPaid      = 1;
        } elseif ($method === 'split') {
            // Cash/card portions are recorded immediately. If there's also an
            // online portion the order starts as 'partial' (or 'unpaid' if all
            // immediate were 0, meaning the entire split is just the online
            // portion — degenerate case = behaves like plain online).
            $orderPaidAmount   = $splitImmediateSum;
            if ($splitOnlineAmount > 0) {
                $orderPaymentState = $splitImmediateSum > 0 ? 'partial' : 'unpaid';
                $orderWasPaid      = 0;
            } else {
                $orderPaymentState = 'paid';
                $orderWasPaid      = 1;
            }
        } else {
            // online OR unpaid
            $orderPaidAmount   = 0.0;
            $orderPaymentState = 'unpaid';
            $orderWasPaid      = 0;
        }
        $isOnline = ($method === 'online') || ($method === 'split' && $splitOnlineAmount > 0);

        // Run the whole thing in a transaction
        $result = ['ok' => false];

        Database::transaction(function (PDO $pdo) use (
            $tlId, $tl, $service, $serviceId, $employee, $employeeId, $price, $input,
            $method, $originalMethod,
            $insertPayMethod, $insertPayAmount,
            $splitRows, $splitOnlineAmount, $splitImmediateSum,
            $isOnline, $orderPaidAmount, $orderPaymentState, $orderWasPaid, &$result
        ) {
            // ── 1. Create the order header ───────────────────────────
            $orderId       = self::newUUID($pdo);
            $orderNumber   = OrderNumber::next($pdo);
            $trackingToken = bin2hex(random_bytes(8));

            $pdo->prepare(
                'INSERT INTO orders
                 (id, order_number, vehicle_plate, customer_name, customer_phone,
                  customer_email, parking_location_id, notes,
                  total_amount, paid_amount, payment_status, was_paid,
                  created_by, source, tracking_token,
                  created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "team_leader", ?,
                         UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            )->execute([
                $orderId,
                $orderNumber,
                // Convert empty/missing plate to NULL (column is now nullable)
                (function ($v) {
                    $v = strtoupper(trim((string)$v));
                    return $v === '' ? null : $v;
                })($input['vehicle_plate'] ?? null),
                $input['customer_name']    ?? null,
                $input['customer_phone']   ?? null,
                $input['customer_email']   ?? null,
                $input['location_id']      ?? null,
                $input['notes']            ?? null,
                $price,
                $orderPaidAmount,
                $orderPaymentState,
                $orderWasPaid,
                $tlId,
                $trackingToken,
            ]);

            // ── 2. Daily serial for this service ─────────────────────
            $serial = DailySerial::next($pdo, $serviceId);

            // ── 3. Create order_service row, already accepted ───────
            $osId = self::newUUID($pdo);
            $pdo->prepare(
                'INSERT INTO order_services
                 (id, order_id, service_id, daily_serial, serial_date,
                  provider_id, employee_id, status, price,
                  location_id, location_details, image_path,
                  assigned_at, accepted_at, version,
                  created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "accepted", ?, ?, ?, ?,
                         UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1,
                         UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            )->execute([
                $osId,
                $orderId,
                $serviceId,
                $serial['serial'],
                $serial['date'],
                $tlId,
                $employeeId,
                $price,
                $input['location_id']       ?? null,
                $input['location_details']  ?? null,
                $input['image_path']        ?? null,
            ]);

            // ── 4. Payment row(s) (only when we actually collected money) ─
            // Three shapes:
            //   single method (cash/card) → one row, full price
            //   split with cash/card portions → one row per non-online portion
            //   online or unpaid → no rows here; Stripe link is created later
            $paymentUuid = $input['payment_uuid'] ?? self::newUUID($pdo);
            if ($method === 'split' && !empty($splitRows)) {
                // Insert one payment row per cash/card portion. Per-row
                // uuid_ref so we don't collide on the UNIQUE index.
                foreach ($splitRows as $idx => $r) {
                    $payId   = self::newUUID($pdo);
                    $rowUuid = $paymentUuid . '-' . $idx;
                    $pdo->prepare(
                        'INSERT INTO payments
                         (id, order_id, payment_method, amount, transaction_ref,
                          uuid_ref, status, recorded_by, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, "success", ?, UTC_TIMESTAMP())'
                    )->execute([
                        $payId,
                        $orderId,
                        $r['method'],
                        $r['amount'],
                        $input['payment_ref'] ?? null,
                        $rowUuid,
                        $tlId,
                    ]);
                }
            } elseif ($insertPayMethod !== null && $insertPayAmount > 0) {
                $payId = self::newUUID($pdo);
                $pdo->prepare(
                    'INSERT INTO payments
                     (id, order_id, payment_method, amount, transaction_ref,
                      uuid_ref, status, recorded_by, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, "success", ?, UTC_TIMESTAMP())'
                )->execute([
                    $payId,
                    $orderId,
                    $insertPayMethod,
                    $insertPayAmount,
                    $input['payment_ref'] ?? null,
                    $paymentUuid,
                    $tlId,
                ]);
            }

            // Message tailored to scenario
            $msg = 'Order created and assigned';
            if ($method === 'split' && $splitOnlineAmount > 0) {
                $msg = 'Order created — cash/card recorded, awaiting online portion';
            } elseif ($method === 'split') {
                $msg = 'Order created — split payment recorded';
            } elseif ($isOnline) {
                $msg = 'Order created — awaiting online payment';
            } elseif ($method === 'unpaid') {
                $msg = 'Order created — unpaid';
            }

            // What amount should go on a Stripe Checkout Session (if any)?
            //   plain 'online'                → full price
            //   split with online portion     → online portion only
            //   everything else               → not applicable
            $linkAmount = null;
            if ($method === 'online') {
                $linkAmount = $price;
            } elseif ($method === 'split' && $splitOnlineAmount > 0) {
                $linkAmount = $splitOnlineAmount;
            }

            $result = [
                'ok'             => true,
                'code'           => self::OK,
                'message'        => $msg,
                'data'           => [
                    'order_id'         => $orderId,
                    'order_service_id' => $osId,
                    'order_number'     => $orderNumber,
                    'daily_serial'     => $serial['serial'],
                    'serial_date'      => $serial['date'],
                    'service_name'     => $service['name'],
                    'employee_name'    => $employee['name'],
                    'amount'           => $price,
                    'paid_amount'      => $orderPaidAmount,
                    'balance'          => max(0.0, $price - $orderPaidAmount),
                    'payment_method'   => $method,
                    'originating_method' => $originalMethod,
                    'payment_status'   => $orderPaymentState,
                    'tracking_token'   => $trackingToken,
                    'link_amount'      => $linkAmount,
                ],
            ];
        });

        // If the order was paid in full at booking time (cash/card),
        // auto-complete it now. This runs in a fresh statement after the
        // transaction has committed, so the order_services rows are visible.
        if ($result['ok'] && ($orderPaymentState ?? '') === 'paid') {
            try {
                self::maybeAutoComplete($this->pdo, $result['data']['order_id']);
            } catch (\Throwable $e) {
                error_log('[OrderClaim] auto-complete failed for '
                    . $result['data']['order_id'] . ': ' . $e->getMessage());
            }
        }

        return $result;
    }

    // =================================================================
    // Helpers
    // =================================================================
    private function fetchTeamLeader(string $tlId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, service_id, is_active
             FROM users
             WHERE id = ? AND role = "team_leader"'
        );
        $stmt->execute([$tlId]);
        $row = $stmt->fetch();
        if (!$row || !$row['is_active']) return null;
        return $row;
    }

    /**
     * After an accept() UPDATE returned 0 rows, figure out *why* so we
     * can return a useful message.
     */
    private function diagnoseAcceptFailure(string $svcId, string $tlServiceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT os.status, os.provider_id, os.service_id, o.payment_status
             FROM order_services os
             JOIN orders o ON o.id = os.order_id
             WHERE os.id = ?'
        );
        $stmt->execute([$svcId]);
        $row = $stmt->fetch();

        if (!$row) {
            return self::fail(self::NOT_FOUND, 'Order not found');
        }
        if ($row['service_id'] !== $tlServiceId) {
            return self::fail(self::WRONG_SERVICE, 'Not your service');
        }
        if ($row['payment_status'] !== 'paid') {
            return self::fail(self::NOT_PAID, 'Order is not paid yet');
        }
        if ($row['provider_id'] !== null || $row['status'] !== 'pending') {
            return self::fail(self::ALREADY_TAKEN, 'Already taken by another team leader');
        }
        return self::fail(self::INVALID_STATE, 'Could not accept order');
    }

    private static function newUUID(PDO $pdo): string
    {
        return $pdo->query('SELECT UUID()')->fetchColumn();
    }

    private static function ok(string $msg): array
    {
        return ['ok' => true, 'code' => self::OK, 'message' => $msg];
    }

    private static function fail(string $code, string $msg): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $msg];
    }

    /**
     * Auto-complete an order's services the moment it becomes fully paid.
     *
     * Called from any code path that records a payment (PaymentController,
     * CustomerController::verifyPayment, OrderClaim::directBook when cash/
     * card paid at booking time, etc.).
     *
     * Behaviour:
     *   - Only touches orders whose payment_status is "paid"
     *   - For each linked order_service: if status is accepted or in_progress,
     *     flip to "completed" and set completed_at to NOW(). Already-completed
     *     and cancelled services are left alone.
     *   - Returns the number of services it advanced (mostly for logging).
     *
     * Safe to call repeatedly — idempotent.
     */
    public static function maybeAutoComplete(\PDO $pdo, string $orderId): int
    {
        // Only auto-complete when the order is actually paid in full
        $st = $pdo->prepare(
            'SELECT payment_status FROM orders WHERE id = ?'
        );
        $st->execute([$orderId]);
        $status = $st->fetchColumn();
        if ($status !== 'paid') return 0;

        // Advance any in-flight services. We deliberately allow accepted
        // (the new flow has no Start step) AND in_progress (back-compat for
        // any old orders mid-cycle).
        $upd = $pdo->prepare(
            'UPDATE order_services
                SET status       = "completed",
                    completed_at = UTC_TIMESTAMP(),
                    version      = version + 1,
                    updated_at   = UTC_TIMESTAMP()
              WHERE order_id = ?
                AND status IN ("accepted", "in_progress")'
        );
        $upd->execute([$orderId]);
        return $upd->rowCount();
    }
}
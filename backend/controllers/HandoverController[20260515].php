<?php
// backend/controllers/HandoverController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\{Response, Request};
use App\Middleware\AuthMiddleware;
use App\Helpers\Mailer;

/**
 * Cash handover endpoints.
 *
 *   TL-side (require team_leader):
 *     GET    /api/tl/handovers/today        todayCash()      — preview today's cash + order list
 *     POST   /api/tl/handovers              submit()         — create handover row, email admin
 *     GET    /api/tl/handovers              myHistory()      — TL's own handover history
 *
 *   Admin-side (require admin):
 *     GET    /api/handovers                 adminList()      — all handovers with filters
 *     GET    /api/handovers/{id}            adminDetail()    — full detail with linked orders
 *     PATCH  /api/handovers/{id}/confirm    adminConfirm()
 *     PATCH  /api/handovers/{id}/dispute    adminDispute()
 *
 *   Public (token-gated, no login):
 *     GET    /api/handovers/confirm/{token} viewByToken()    — returns JSON of pending handover
 *     POST   /api/handovers/confirm/{token} confirmByToken() — single-use confirm
 *
 * ─── Cash logic ──────────────────────────────────────────────────────
 * "Today's cash" for a TL means: the sum of all successful cash payments
 * on orders where this TL completed at least one order_service today,
 * minus anything already snapshotted into a handover for today.
 *
 * In practice we just sum payments tied to orders whose order_services
 * for this TL have completed_at = today. This is the conservative
 * definition: the TL only hands over money for jobs they actually
 * finished today.
 */
class HandoverController
{
    // =================================================================
    // GET /api/tl/handovers/today
    // =================================================================
    /**
     * Returns today's cash total + the list of contributing orders so
     * the TL can review before submitting.
     */
    public function todayCash(array $params = []): void
    {
        $user = AuthMiddleware::require(['team_leader']);
        $tlId = $user['id'];

        $pdo = Database::getInstance();

        // Sum cash payments for orders this TL completed today, minus any
        // already part of a non-disputed handover.
        $stmt = $pdo->prepare(
            'SELECT
                o.id              AS order_id,
                o.order_number,
                o.vehicle_plate,
                o.customer_name,
                os.id             AS order_service_id,
                os.daily_serial,
                os.completed_at,
                s.name            AS service_name,
                p.id              AS payment_id,
                p.amount,
                p.transaction_ref,
                p.created_at      AS paid_at
             FROM order_services os
             JOIN orders   o   ON o.id  = os.order_id
             JOIN services s   ON s.id  = os.service_id
             JOIN payments p   ON p.order_id = o.id
                              AND p.status = "success"
                              AND p.payment_method = "cash"
             WHERE os.provider_id = :tl_id
               AND os.status      = "completed"
               AND DATE(os.completed_at) = CURDATE()
               AND NOT EXISTS (
                     SELECT 1 FROM cash_handovers ch
                     WHERE ch.team_leader_id = :tl_id_sub
                       AND ch.status IN ("pending","confirmed")
                       AND JSON_CONTAINS(ch.order_ids, JSON_QUOTE(o.id)) = 1
                   )
             ORDER BY os.completed_at ASC'
        );
        $stmt->execute([
            ':tl_id'     => $tlId,
            ':tl_id_sub' => $tlId,
        ]);
        $orders = $stmt->fetchAll();

        $total = 0.0;
        foreach ($orders as $o) {
            $total += (float)$o['amount'];
        }

        Response::success([
            'date'         => date('Y-m-d'),
            'currency'     => APP_CURRENCY,
            'amount'       => round($total, 2),
            'order_count'  => count($orders),
            'orders'       => $orders,
        ]);
    }

    // =================================================================
    // POST /api/tl/handovers
    // =================================================================
    /**
     * Create the handover record, snapshot the orders, email admin.
     *
     * Body (all optional):
     *   notes         text the TL wants the admin to see
     *
     * The amount + orders are computed server-side from todayCash()
     * logic so the TL can't fudge the number.
     */
    public function submit(array $params = []): void
    {
        $user = AuthMiddleware::require(['team_leader']);
        $tlId = $user['id'];
        $req  = new Request();

        $pdo = Database::getInstance();

        // Re-run the same query as todayCash() to get authoritative numbers
        $stmt = $pdo->prepare(
            'SELECT DISTINCT o.id AS order_id, o.order_number,
                    o.vehicle_plate, os.daily_serial, p.amount
             FROM order_services os
             JOIN orders   o ON o.id = os.order_id
             JOIN payments p ON p.order_id = o.id
                            AND p.status = "success"
                            AND p.payment_method = "cash"
             WHERE os.provider_id = :tl_id
               AND os.status      = "completed"
               AND DATE(os.completed_at) = CURDATE()
               AND NOT EXISTS (
                     SELECT 1 FROM cash_handovers ch
                     WHERE ch.team_leader_id = :tl_id_sub
                       AND ch.status IN ("pending","confirmed")
                       AND JSON_CONTAINS(ch.order_ids, JSON_QUOTE(o.id)) = 1
                   )
             ORDER BY os.completed_at ASC'
        );
        $stmt->execute([':tl_id' => $tlId, ':tl_id_sub' => $tlId]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            Response::error('No cash orders to hand over today', 422);
        }

        $amount   = 0.0;
        $orderIds = [];
        foreach ($rows as $r) {
            $amount     += (float)$r['amount'];
            $orderIds[] = $r['order_id'];
        }
        $amount      = round($amount, 2);
        $orderCount  = count($orderIds);

        if ($amount < MIN_HANDOVER_AMOUNT) {
            Response::error('Amount is below the minimum handover threshold', 422);
        }

        // Build snapshot for email
        $emailOrders = array_map(function ($r) {
            return [
                'daily_serial'  => $r['daily_serial'],
                'order_number'  => $r['order_number'],
                'vehicle_plate' => $r['vehicle_plate'],
                'amount'        => $r['amount'],
            ];
        }, $rows);

        $notes = trim((string)$req->input('notes', '')) ?: null;
        $token = bin2hex(random_bytes(20));  // 40 chars
        $id    = $pdo->query('SELECT UUID()')->fetchColumn();

        // Single transaction: insert handover row
        $handoverRow = null;
        Database::transaction(function (\PDO $pdo) use (
            $id, $tlId, $amount, $orderCount, $orderIds, $notes, $token, &$handoverRow
        ) {
            $pdo->prepare(
                'INSERT INTO cash_handovers
                 (id, team_leader_id, handover_date, amount, order_count,
                  order_ids, notes, status, confirm_token, created_at, updated_at)
                 VALUES (?, ?, CURDATE(), ?, ?, ?, ?, "pending", ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            )->execute([
                $id, $tlId, $amount, $orderCount,
                json_encode($orderIds, JSON_UNESCAPED_SLASHES),
                $notes, $token,
            ]);

            $sel = $pdo->prepare('SELECT * FROM cash_handovers WHERE id = ?');
            $sel->execute([$id]);
            $handoverRow = $sel->fetch();
        });

        // Send email (best effort — never fail the request if mail fails)
        try {
            Mailer::sendHandoverSubmitted($handoverRow, [
                'id'    => $user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
            ], $emailOrders);
        } catch (\Throwable $e) {
            error_log('Handover email failed: ' . $e->getMessage());
        }

        Response::json([
            'success' => true,
            'message' => 'Handover submitted. Awaiting admin confirmation.',
            'data'    => [
                'id'           => $id,
                'amount'       => $amount,
                'currency'     => APP_CURRENCY,
                'order_count'  => $orderCount,
                'status'       => 'pending',
                'created_at'   => $handoverRow['created_at'] ?? null,
            ],
        ], 201);
    }

    // =================================================================
    // GET /api/tl/handovers
    // =================================================================
    public function myHistory(array $params = []): void
    {
        $user = AuthMiddleware::require(['team_leader']);
        $tlId = $user['id'];

        $pdo = Database::getInstance();

        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(100, max(10, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM cash_handovers WHERE team_leader_id = ?');
        $countStmt->execute([$tlId]);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT id, handover_date, amount, order_count, status, notes,
                    dispute_reason, confirmed_at, created_at
             FROM cash_handovers
             WHERE team_leader_id = ?
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $tlId);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
        $stmt->execute();

        Response::success([
            'items' => $stmt->fetchAll(),
            'meta'  => [
                'page'        => $page,
                'limit'       => $limit,
                'total'       => $total,
                'total_pages' => max(1, (int)ceil($total / $limit)),
            ],
        ]);
    }

    // =================================================================
    // GET /api/handovers (admin)
    // =================================================================
    public function adminList(array $params = []): void
    {
        AuthMiddleware::require(['admin']);

        $pdo = Database::getInstance();

        $where  = [];
        $bind   = [];

        if (!empty($_GET['team_leader_id'])) {
            $where[] = 'ch.team_leader_id = ?';
            $bind[]  = (string)$_GET['team_leader_id'];
        }
        if (!empty($_GET['status'])
            && in_array($_GET['status'], ['pending','confirmed','disputed'], true)) {
            $where[] = 'ch.status = ?';
            $bind[]  = (string)$_GET['status'];
        }
        if (!empty($_GET['from'])) {
            $where[] = 'ch.handover_date >= ?';
            $bind[]  = (string)$_GET['from'];
        }
        if (!empty($_GET['to'])) {
            $where[] = 'ch.handover_date <= ?';
            $bind[]  = (string)$_GET['to'];
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(200, max(10, (int)($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM cash_handovers ch $whereSQL");
        $countStmt->execute($bind);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT ch.id, ch.handover_date, ch.amount, ch.order_count,
                   ch.status, ch.notes, ch.dispute_reason,
                   ch.confirmed_at, ch.created_at,
                   tl.id    AS team_leader_id,
                   tl.name  AS team_leader_name,
                   tl.email AS team_leader_email,
                   s.name   AS service_name,
                   confirmer.name AS confirmed_by_name
              FROM cash_handovers ch
              JOIN users    tl ON tl.id = ch.team_leader_id
              LEFT JOIN services s         ON s.id = tl.service_id
              LEFT JOIN users    confirmer ON confirmer.id = ch.confirmed_by
              $whereSQL
             ORDER BY ch.created_at DESC
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

        Response::success([
            'items' => $stmt->fetchAll(),
            'meta'  => [
                'page'        => $page,
                'limit'       => $limit,
                'total'       => $total,
                'total_pages' => max(1, (int)ceil($total / $limit)),
            ],
        ]);
    }

    // =================================================================
    // GET /api/handovers/{id} (admin)
    // =================================================================
    public function adminDetail(array $params): void
    {
        AuthMiddleware::require(['admin']);
        $id  = $params['id'] ?? '';
        $pdo = Database::getInstance();

        $handover = $this->fetchHandover($pdo, $id);
        if (!$handover) {
            Response::error('Handover not found', 404);
        }

        // Decode order_ids and fetch the order details
        $orderIds = $handover['order_ids']
                  ? (json_decode($handover['order_ids'], true) ?: [])
                  : [];

        $orders = [];
        if ($orderIds) {
            $ph    = implode(',', array_fill(0, count($orderIds), '?'));
            $ostmt = $pdo->prepare(
                "SELECT o.id, o.order_number, o.vehicle_plate,
                        o.customer_name, o.customer_phone,
                        os.daily_serial, os.completed_at,
                        s.name AS service_name,
                        p.amount, p.transaction_ref, p.created_at AS paid_at
                 FROM orders o
                 JOIN order_services os ON os.order_id = o.id
                                       AND os.provider_id = ?
                                       AND os.status      = 'completed'
                 JOIN services s ON s.id = os.service_id
                 JOIN payments p ON p.order_id = o.id
                                AND p.payment_method = 'cash'
                                AND p.status = 'success'
                 WHERE o.id IN ($ph)
                 ORDER BY os.completed_at ASC"
            );
            $ostmt->execute(array_merge([$handover['team_leader_id']], $orderIds));
            $orders = $ostmt->fetchAll();
        }

        $handover['orders'] = $orders;
        unset($handover['order_ids']);  // we expose orders[] instead

        Response::success($handover);
    }

    // =================================================================
    // PATCH /api/handovers/{id}/confirm  (admin)
    // =================================================================
    public function adminConfirm(array $params): void
    {
        $admin = AuthMiddleware::require(['admin']);
        $id    = $params['id'] ?? '';
        $pdo   = Database::getInstance();

        $this->doConfirm($pdo, $id, $admin['id']);
    }

    // =================================================================
    // PATCH /api/handovers/{id}/dispute  (admin)
    // =================================================================
    public function adminDispute(array $params): void
    {
        $admin = AuthMiddleware::require(['admin']);
        $id    = $params['id'] ?? '';
        $req   = new Request();

        $reason = trim((string)$req->input('reason', ''));
        if ($reason === '') {
            Response::error('Dispute reason is required', 422);
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'UPDATE cash_handovers
                SET status = "disputed",
                    dispute_reason = ?,
                    confirmed_by   = ?,
                    confirmed_at   = UTC_TIMESTAMP(),
                    updated_at     = UTC_TIMESTAMP()
              WHERE id = ? AND status = "pending"'
        );
        $stmt->execute([$reason, $admin['id'], $id]);

        if ($stmt->rowCount() === 0) {
            // Diagnose
            $h = $this->fetchHandover($pdo, $id);
            if (!$h) Response::error('Handover not found', 404);
            Response::error("Cannot dispute handover in status: {$h['status']}", 422);
        }

        Response::success(null, 'Handover marked as disputed');
    }

    // =================================================================
    // GET /api/handovers/confirm/{token}  (public)
    // =================================================================
    /**
     * Public view of a handover so the admin can see what they're
     * confirming before clicking the button. No login required.
     */
    public function viewByToken(array $params): void
    {
        $token = $params['token'] ?? '';
        if (strlen($token) !== 40) {
            Response::error('Invalid token', 404);
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT ch.id, ch.handover_date, ch.amount, ch.order_count,
                    ch.status, ch.notes, ch.created_at, ch.confirmed_at,
                    tl.name AS team_leader_name,
                    s.name  AS service_name
             FROM cash_handovers ch
             JOIN users tl         ON tl.id = ch.team_leader_id
             LEFT JOIN services s  ON s.id  = tl.service_id
             WHERE ch.confirm_token = ?
               AND ch.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
             LIMIT 1'
        );
        $stmt->execute([$token, HANDOVER_TOKEN_TTL_DAYS]);
        $h = $stmt->fetch();

        if (!$h) {
            Response::error('Link has expired or is invalid', 404);
        }

        $h['currency'] = APP_CURRENCY;
        Response::success($h);
    }

    // =================================================================
    // POST /api/handovers/confirm/{token}  (public, single-use)
    // =================================================================
    public function confirmByToken(array $params): void
    {
        $token = $params['token'] ?? '';
        if (strlen($token) !== 40) {
            Response::error('Invalid token', 404);
        }

        $pdo = Database::getInstance();

        // Find the handover; must be pending and within TTL
        $stmt = $pdo->prepare(
            'SELECT id FROM cash_handovers
             WHERE confirm_token = ?
               AND status        = "pending"
               AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
             LIMIT 1'
        );
        $stmt->execute([$token, HANDOVER_TOKEN_TTL_DAYS]);
        $id = $stmt->fetchColumn();

        if (!$id) {
            Response::error('Link has expired or is invalid (or already used)', 404);
        }

        // No authenticated user → confirmed_by stays NULL.
        // We invalidate the token by rotating it to a junk value so it
        // can't be reused.
        $this->doConfirm($pdo, $id, null, true);
    }

    // =================================================================
    // Internals
    // =================================================================
    private function fetchHandover(\PDO $pdo, string $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT ch.*, tl.name AS team_leader_name, tl.email AS team_leader_email,
                    s.name AS service_name
             FROM cash_handovers ch
             JOIN users tl        ON tl.id = ch.team_leader_id
             LEFT JOIN services s ON s.id = tl.service_id
             WHERE ch.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Shared confirm path used by adminConfirm() and confirmByToken().
     * Optionally rotates the confirm_token to invalidate the public link.
     */
    private function doConfirm(\PDO $pdo, string $id, ?string $adminId, bool $rotateToken = false): void
    {
        // Atomic: only flips pending → confirmed
        $stmt = $pdo->prepare(
            'UPDATE cash_handovers
                SET status        = "confirmed",
                    confirmed_by  = ?,
                    confirmed_at  = UTC_TIMESTAMP(),
                    confirm_token = ' . ($rotateToken ? 'CONCAT("used-", LEFT(UUID(),35))' : 'confirm_token') . ',
                    updated_at    = UTC_TIMESTAMP()
              WHERE id = ? AND status = "pending"'
        );
        $stmt->execute([$adminId, $id]);

        if ($stmt->rowCount() === 0) {
            $h = $this->fetchHandover($pdo, $id);
            if (!$h) Response::error('Handover not found', 404);
            Response::error("Cannot confirm handover in status: {$h['status']}", 422);
        }

        // Email the TL (best effort)
        try {
            $h = $this->fetchHandover($pdo, $id);
            if ($h) {
                Mailer::sendHandoverConfirmed($h, [
                    'name'  => $h['team_leader_name'],
                    'email' => $h['team_leader_email'],
                ]);
            }
        } catch (\Throwable $e) {
            error_log('Handover confirm email failed: ' . $e->getMessage());
        }

        Response::success(null, 'Handover confirmed');
    }
}
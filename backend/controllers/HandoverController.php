<?php
// backend/controllers/HandoverController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\{Response, Request};
use App\Middleware\AuthMiddleware;
use App\Helpers\Mailer;

/**
 * Payment handover endpoints.
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
     * Returns ALL not-yet-handed-over payments for this TL, across every
     * method (cash + card + online) and every date — not just today.
     *
     * The "today" route name is preserved for frontend backwards compat.
     * Internally this is the "outstanding payments" report — what the TL
     * still owes the admin as a reconciliation, with a per-method breakdown.
     *
     * A payment is "outstanding" when:
     *   1. its order_service is status = completed
     *   2. its order_service belongs to this TL (provider_id)
     *   3. the payment row is status = success
     *   4. the parent order is NOT already in a pending/confirmed handover
     *
     * Disputed handovers DON'T block payments — TL can re-submit them in a
     * fresh handover once the dispute is resolved.
     */
    public function todayCash(array $params = []): void
    {
        $user = AuthMiddleware::require(['team_leader']);
        $tlId = $user['id'];

        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'SELECT
                o.id              AS order_id,
                o.order_number,
                os.id             AS order_service_id,
                os.daily_serial,
                os.completed_at,
                s.name            AS service_name,
                p.id              AS payment_id,
                p.payment_method,
                p.amount,
                p.transaction_ref,
                p.created_at      AS paid_at
             FROM order_services os
             JOIN orders   o   ON o.id  = os.order_id
             JOIN services s   ON s.id  = os.service_id
             JOIN payments p   ON p.order_id = o.id
                              AND p.status = "success"
             WHERE os.provider_id = :tl_id
               AND os.status      = "completed"
               AND NOT EXISTS (
                     SELECT 1 FROM cash_handovers ch
                     WHERE ch.team_leader_id = :tl_id_sub
                       AND ch.status IN ("pending","confirmed")
                       AND JSON_CONTAINS(ch.order_ids, JSON_QUOTE(o.id)) = 1
                   )
             ORDER BY os.completed_at ASC, p.created_at ASC'
        );
        $stmt->execute([
            ':tl_id'     => $tlId,
            ':tl_id_sub' => $tlId,
        ]);
        $payments = $stmt->fetchAll();

        // Aggregate: per-method totals + unique order count
        $byMethod = ['cash' => 0.0, 'card' => 0.0, 'online' => 0.0];
        $orderIds = [];
        foreach ($payments as $p) {
            $m = $p['payment_method'];
            if (isset($byMethod[$m])) $byMethod[$m] += (float)$p['amount'];
            $orderIds[$p['order_id']] = true;
        }
        $orderCount = count($orderIds);

        // Round each bucket first, then derive the grand total from
        // the rounded buckets. This guarantees that the displayed
        // total ALWAYS equals cash + card + online to the fil, even
        // when raw amounts had fractional fils (e.g. from FX rounding
        // on partial-method payments). Without this, you could see
        // by-method rows summing to 200.02 next to a total of 200.01.
        foreach ($byMethod as $k => $v) $byMethod[$k] = round($v, 2);
        $total = $byMethod['cash'] + $byMethod['card'] + $byMethod['online'];

        Response::success([
            'date'         => date('Y-m-d'),
            'currency'     => APP_CURRENCY,
            'amount'       => round($total, 2),
            'by_method'    => $byMethod,
            'order_count'  => $orderCount,
            'payment_count'=> count($payments),
            'orders'       => $payments,   // legacy key — kept for back-compat
            'payments'     => $payments,   // new explicit key
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

        // Gate: TL cannot submit a new handover while a prior handover is
        // still pending admin approval. A rejected handover does NOT block —
        // the new submission supersedes it (we delete the rejected row so
        // the gate stays clean). This is the "resubmit after rejection"
        // path: TL fixes whatever was wrong and submits again.
        $blockStmt = $pdo->prepare(
            'SELECT id, status, dispute_reason, amount, handover_date, created_at
               FROM cash_handovers
              WHERE team_leader_id = ?
                AND status = "pending"
              ORDER BY created_at DESC
              LIMIT 1'
        );
        $blockStmt->execute([$tlId]);
        if ($blocker = $blockStmt->fetch()) {
            Response::error(
                'You have a pending handover awaiting admin approval. Wait for that to be approved before submitting another.',
                409,
                [
                    'blocking_handover' => [
                        'id'             => $blocker['id'],
                        'status'         => $blocker['status'],
                        'amount'         => (float)$blocker['amount'],
                        'handover_date'  => $blocker['handover_date'],
                        'created_at'     => $blocker['created_at'],
                        'reject_reason'  => $blocker['dispute_reason'],
                    ],
                ]
            );
        }

        // If TL has a prior REJECTED (disputed) handover, clear it now —
        // the new submission supersedes it. The rejected handover's orders
        // are already back in the "unsettled" pool (todayCash() only
        // excludes pending+confirmed handovers, not disputed), so the new
        // submission will pick them up again automatically.
        $pdo->prepare(
            'DELETE FROM cash_handovers
              WHERE team_leader_id = ? AND status = "disputed"'
        )->execute([$tlId]);


        // Gate: TL cannot submit handover while they still have OWN
        // (TL-originated) orders that aren't paid.
        //
        // Why this gate:
        //   The TL is the cashier on jobs they accepted. If a job is
        //   accepted or in_progress and the customer hasn't paid yet,
        //   that's money the TL is still responsible for collecting.
        //   Submitting a handover before that means part of the day's
        //   billing is still in limbo — admin can't reconcile against
        //   "all jobs done today" cleanly.
        //
        // What we EXCLUDE from this check:
        //   - Customer-self orders (source IN 'customer_qr','customer_web').
        //     Those are paid by the customer through Stripe, not by the
        //     TL. They have their own collection flow and shouldn't
        //     block the TL's handover.
        //   - Completed orders. Once a job is completed it's either paid
        //     (good, handover includes it) or refunded/cancelled (out of
        //     scope here).
        //   - Pending orders. Those haven't been accepted yet — not
        //     this TL's responsibility yet.
        //
        // We use o.payment_status (the order-level rollup) since that's
        // what the rest of the UI keys off; an order is 'paid' when
        // sum(payments) >= total_amount.
        $unpaidStmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT o.id) AS n
               FROM order_services os
               JOIN orders o ON o.id = os.order_id
              WHERE os.provider_id  = ?
                AND os.status       IN ("accepted","in_progress")
                AND o.source        = "team_leader"
                AND o.payment_status IN ("unpaid","partial")'
        );
        $unpaidStmt->execute([$tlId]);
        $unpaidCount = (int)$unpaidStmt->fetchColumn();
        if ($unpaidCount > 0) {
            Response::error(
                'You have ' . $unpaidCount . ' unpaid job' . ($unpaidCount === 1 ? '' : 's') . ' in progress. Collect payment on all open jobs before submitting a handover.',
                409,
                ['unpaid_count' => $unpaidCount]
            );
        }


        // Re-run the same query as todayCash() to get authoritative numbers.
        //
        // Differences from the legacy query:
        //   - No DATE = CURDATE() filter — handover spans all uncollected dates
        //   - No payment_method = "cash" filter — covers cash + card + online
        //
        // The query returns ONE ROW PER PAYMENT (not per order). An order may
        // have multiple payment rows (split payment). Each row's `payment_method`
        // is what we aggregate the per-method totals from.
        $stmt = $pdo->prepare(
            'SELECT o.id AS order_id, o.order_number,
                    os.daily_serial,
                    p.id AS payment_id, p.amount, p.payment_method
             FROM order_services os
             JOIN orders   o ON o.id = os.order_id
             JOIN payments p ON p.order_id = o.id
                            AND p.status = "success"
             WHERE os.provider_id = :tl_id
               AND os.status      = "completed"
               AND NOT EXISTS (
                     SELECT 1 FROM cash_handovers ch
                     WHERE ch.team_leader_id = :tl_id_sub
                       AND ch.status IN ("pending","confirmed")
                       AND JSON_CONTAINS(ch.order_ids, JSON_QUOTE(o.id)) = 1
                   )
             ORDER BY os.completed_at ASC, p.created_at ASC'
        );
        $stmt->execute([':tl_id' => $tlId, ':tl_id_sub' => $tlId]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            Response::error('No outstanding payments to hand over', 422);
        }

        // Sum amounts across every payment row, broken down by method.
        // Dedupe order_ids in PHP so the handover snapshot has each order
        // listed once even when it had multiple split payments.
        $byMethod = ['cash' => 0.0, 'card' => 0.0, 'online' => 0.0];
        $seenIds  = [];
        $orderIds = [];
        foreach ($rows as $r) {
            $m = $r['payment_method'];
            if (isset($byMethod[$m])) $byMethod[$m] += (float)$r['amount'];
            if (!isset($seenIds[$r['order_id']])) {
                $seenIds[$r['order_id']] = true;
                $orderIds[] = $r['order_id'];
            }
        }
        $amount     = round(array_sum($byMethod), 2);
        $cashAmt    = round($byMethod['cash'],   2);
        $cardAmt    = round($byMethod['card'],   2);
        $onlineAmt  = round($byMethod['online'], 2);
        $orderCount = count($orderIds);

        // Note: MIN_HANDOVER_AMOUNT compares against the GRAND TOTAL across
        // methods, not just cash. Card/online portions count.
        if ($amount < MIN_HANDOVER_AMOUNT) {
            Response::error('Amount is below the minimum handover threshold', 422);
        }

        // Build snapshot for email (one line per payment for full audit
        // trail). Handover reports don't carry plate or customer details;
        // those have been deliberately scrubbed from the snapshot.
        $emailOrders = array_map(function ($r) {
            return [
                'daily_serial'   => $r['daily_serial'],
                'order_number'   => $r['order_number'],
                'amount'         => $r['amount'],
                'payment_method' => $r['payment_method'],
            ];
        }, $rows);

        $notes = trim((string)$req->input('notes', '')) ?: null;
        $token = bin2hex(random_bytes(20));  // 40 chars
        $id    = $pdo->query('SELECT UUID()')->fetchColumn();

        // Single transaction: insert handover row with per-method breakdown
        $handoverRow = null;
        Database::transaction(function (\PDO $pdo) use (
            $id, $tlId, $amount, $cashAmt, $cardAmt, $onlineAmt,
            $orderCount, $orderIds, $notes, $token, &$handoverRow
        ) {
            $pdo->prepare(
                'INSERT INTO cash_handovers
                 (id, team_leader_id, handover_date, amount,
                  cash_amount, card_amount, online_amount, order_count,
                  order_ids, notes, status, confirm_token,
                  created_at, updated_at)
                 VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?,
                         "pending", ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            )->execute([
                $id, $tlId, $amount,
                $cashAmt, $cardAmt, $onlineAmt, $orderCount,
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

        $where = ['team_leader_id = ?'];
        $bind  = [$tlId];

        if (!empty($_GET['status'])
            && in_array($_GET['status'], ['pending', 'confirmed', 'disputed'], true)) {
            $where[] = 'status = ?';
            $bind[]  = (string)$_GET['status'];
        }
        if (!empty($_GET['from'])) {
            $where[] = 'handover_date >= ?';
            $bind[]  = (string)$_GET['from'];
        }
        if (!empty($_GET['to'])) {
            $where[] = 'handover_date <= ?';
            $bind[]  = (string)$_GET['to'];
        }
        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(100, max(5, (int)($_GET['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM cash_handovers $whereSQL");
        $countStmt->execute($bind);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT id, handover_date, amount,
                    cash_amount, card_amount, online_amount,
                    order_count, status, notes,
                    dispute_reason, confirmed_at, created_at
             FROM cash_handovers
             $whereSQL
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?"
        );
        $i = 1;
        foreach ($bind as $b) { $stmt->bindValue($i++, $b); }
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
    // GET /api/tl/handovers/status
    // =================================================================
    /**
     * Returns the TL's blocking handover, if any. The TL UI calls this on
     * load: if it returns a `blocking` row with status 'pending' or
     * 'disputed', show the appropriate banner instead of the submit form.
     */
    public function myStatus(array $params = []): void
    {
        $user = AuthMiddleware::require(['team_leader']);
        $tlId = $user['id'];

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT id, status, dispute_reason, amount, handover_date,
                    cash_amount, card_amount, online_amount,
                    order_count, created_at
               FROM cash_handovers
              WHERE team_leader_id = ?
                AND status IN ("pending", "disputed")
              ORDER BY created_at DESC
              LIMIT 1'
        );
        $stmt->execute([$tlId]);
        $blocking = $stmt->fetch() ?: null;

        // Also report unpaid TL-originated in-flight orders so the UI can
        // show the same gate banner the submit endpoint would enforce.
        // Mirrors the gate in submit(): TL's own accepted/in_progress
        // orders with payment outstanding. Customer-self orders excluded.
        $unpaidStmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT o.id) AS n
               FROM order_services os
               JOIN orders o ON o.id = os.order_id
              WHERE os.provider_id  = ?
                AND os.status       IN ("accepted","in_progress")
                AND o.source        = "team_leader"
                AND o.payment_status IN ("unpaid","partial")'
        );
        $unpaidStmt->execute([$tlId]);
        $unpaidCount = (int)$unpaidStmt->fetchColumn();

        Response::success([
            'blocking'     => $blocking,
            'unpaid_count' => $unpaidCount,
            // can_submit only when there's no pending handover AND no
            // unpaid TL-originated work in progress.
            'can_submit'   => $blocking === null && $unpaidCount === 0,
        ]);
    }

    // =================================================================
    // GET /api/handovers/unsettled-by-tl (admin)
    // =================================================================
    /**
     * The "live tile view" for the admin Payment Handover page.
     *
     * Returns one row per team leader, with:
     *   - the TL's identity (id, name, service_name)
     *   - their cumulative unsettled cash/card/online + total
     *     (success payments on completed order_services owned by this TL,
     *      NOT already inside a pending/confirmed handover snapshot)
     *   - the unsettled order count
     *   - their latest pending or rejected handover (if any), so admin
     *     can see "this TL submitted X — approve/reject it" inline
     *
     * Admin filter: ?team_leader_id=<uuid> narrows to a single TL.
     */
    public function unsettledByTL(array $params = []): void
    {
        AuthMiddleware::require(['admin']);
        $pdo = Database::getInstance();

        $tlFilter = (string)($_GET['team_leader_id'] ?? '');

        // Step 1: load all active team leaders
        $tlWhere = ["u.role = 'team_leader'"];
        $tlBind  = [];
        if ($tlFilter !== '') {
            $tlWhere[] = 'u.id = ?';
            $tlBind[]  = $tlFilter;
        }
        $tlSQL = 'SELECT u.id, u.name, u.email, u.service_id, s.name AS service_name
                    FROM users u
                    LEFT JOIN services s ON s.id = u.service_id
                    WHERE ' . implode(' AND ', $tlWhere) . '
                    ORDER BY u.name';
        $tlStmt = $pdo->prepare($tlSQL);
        $tlStmt->execute($tlBind);
        $tls = $tlStmt->fetchAll();

        if (!$tls) {
            Response::success(['items' => []]);
        }

        // Step 2: for each TL, sum unsettled payments. This is N+1 queries
        // but N is tiny (number of TLs), and each subquery is well-indexed.
        $payStmt = $pdo->prepare(
            'SELECT
               COALESCE(SUM(CASE WHEN p.payment_method = "cash"   THEN p.amount END), 0) AS cash_amount,
               COALESCE(SUM(CASE WHEN p.payment_method = "card"   THEN p.amount END), 0) AS card_amount,
               COALESCE(SUM(CASE WHEN p.payment_method = "online" THEN p.amount END), 0) AS online_amount,
               COALESCE(SUM(p.amount), 0) AS total,
               COUNT(DISTINCT o.id)       AS order_count
             FROM order_services os
             JOIN orders   o ON o.id  = os.order_id
             JOIN payments p ON p.order_id = o.id
                            AND p.status = "success"
             WHERE os.provider_id = :tl_id
               AND os.status      = "completed"
               AND NOT EXISTS (
                     SELECT 1 FROM cash_handovers ch
                     WHERE ch.team_leader_id = :tl_id_sub
                       AND ch.status IN ("pending","confirmed")
                       AND JSON_CONTAINS(ch.order_ids, JSON_QUOTE(o.id)) = 1
                   )'
        );

        $blockerStmt = $pdo->prepare(
            'SELECT id, status, amount, cash_amount, card_amount, online_amount,
                    order_count, handover_date, created_at, dispute_reason, notes
               FROM cash_handovers
              WHERE team_leader_id = ?
                AND status IN ("pending", "disputed")
              ORDER BY created_at DESC
              LIMIT 1'
        );

        $items = [];
        foreach ($tls as $tl) {
            $payStmt->execute([
                ':tl_id'     => $tl['id'],
                ':tl_id_sub' => $tl['id'],
            ]);
            $sums = $payStmt->fetch() ?: [
                'cash_amount' => 0, 'card_amount' => 0,
                'online_amount' => 0, 'total' => 0, 'order_count' => 0,
            ];

            $blockerStmt->execute([$tl['id']]);
            $blocker = $blockerStmt->fetch() ?: null;

            $items[] = [
                'team_leader_id'   => $tl['id'],
                'team_leader_name' => $tl['name'],
                'team_leader_email'=> $tl['email'],
                'service_id'       => $tl['service_id'],
                'service_name'     => $tl['service_name'],
                'unsettled' => [
                    'cash_amount'   => round((float)$sums['cash_amount'],   2),
                    'card_amount'   => round((float)$sums['card_amount'],   2),
                    'online_amount' => round((float)$sums['online_amount'], 2),
                    'total'         => round((float)$sums['total'],         2),
                    'order_count'   => (int)$sums['order_count'],
                ],
                'pending_handover' => $blocker,
            ];
        }

        // Sort so TLs with submitted-pending handovers float to the top
        // (action needed), then TLs with rejected handovers, then unsettled
        // by descending total, then alphabetically.
        usort($items, function ($a, $b) {
            $rank = function ($it) {
                if (!empty($it['pending_handover'])
                    && $it['pending_handover']['status'] === 'pending') return 0;
                if (!empty($it['pending_handover'])
                    && $it['pending_handover']['status'] === 'disputed') return 1;
                if (($it['unsettled']['total'] ?? 0) > 0) return 2;
                return 3;
            };
            $ra = $rank($a); $rb = $rank($b);
            if ($ra !== $rb) return $ra - $rb;
            // Same rank — sort by unsettled total descending, then name
            $diff = ($b['unsettled']['total'] ?? 0) - ($a['unsettled']['total'] ?? 0);
            if (abs($diff) > 0.005) return $diff > 0 ? 1 : -1;
            return strcmp($a['team_leader_name'], $b['team_leader_name']);
        });

        Response::success(['items' => $items]);
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
        if (!empty($_GET['service_id'])) {
            $where[] = 'tl.service_id = ?';
            $bind[]  = (string)$_GET['service_id'];
        }
        if (!empty($_GET['status'])
            && in_array($_GET['status'], ['pending','confirmed','disputed'], true)) {
            $where[] = 'ch.status = ?';
            $bind[]  = (string)$_GET['status'];
        }
        // exclude_status — comma-separated list of statuses to omit. Used by
        // the History page to hide currently-pending handovers (those live
        // on the main Payment Handovers tile-view page).
        if (!empty($_GET['exclude_status'])) {
            $excluded = array_intersect(
                array_map('trim', explode(',', (string)$_GET['exclude_status'])),
                ['pending', 'confirmed', 'disputed']
            );
            if ($excluded) {
                $placeholders = implode(',', array_fill(0, count($excluded), '?'));
                $where[] = "ch.status NOT IN ($placeholders)";
                $bind    = array_merge($bind, array_values($excluded));
            }
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
    // GET /api/handovers/export.csv (admin)
    // =================================================================
    /**
     * Stream every matching handover as a CSV. Same filters as adminList()
     * (team_leader_id / service_id / status / from / to) but no pagination —
     * the export is the full filtered result so admins can build their own
     * pivots in Excel.
     *
     * The AuthMiddleware accepts either the standard Authorization header
     * OR a ?token= query parameter (the same fallback used by other
     * download endpoints in the codebase) — necessary because <a href>
     * downloads can't carry custom headers.
     */
    public function adminExport(array $params = []): void
    {
        AuthMiddleware::require(['admin']);

        $pdo = Database::getInstance();

        $where = [];
        $bind  = [];
        if (!empty($_GET['team_leader_id'])) {
            $where[] = 'ch.team_leader_id = ?';
            $bind[]  = (string)$_GET['team_leader_id'];
        }
        if (!empty($_GET['service_id'])) {
            $where[] = 'tl.service_id = ?';
            $bind[]  = (string)$_GET['service_id'];
        }
        if (!empty($_GET['status'])
            && in_array($_GET['status'], ['pending','confirmed','disputed'], true)) {
            $where[] = 'ch.status = ?';
            $bind[]  = (string)$_GET['status'];
        }
        if (!empty($_GET['exclude_status'])) {
            $excluded = array_intersect(
                array_map('trim', explode(',', (string)$_GET['exclude_status'])),
                ['pending', 'confirmed', 'disputed']
            );
            if ($excluded) {
                $placeholders = implode(',', array_fill(0, count($excluded), '?'));
                $where[] = "ch.status NOT IN ($placeholders)";
                $bind    = array_merge($bind, array_values($excluded));
            }
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

        $sql = "
            SELECT ch.id, ch.handover_date, ch.created_at, ch.confirmed_at,
                   ch.amount, ch.cash_amount, ch.card_amount, ch.online_amount,
                   ch.order_count, ch.status, ch.notes, ch.dispute_reason,
                   tl.name  AS team_leader_name,
                   tl.email AS team_leader_email,
                   s.name   AS service_name,
                   confirmer.name AS confirmed_by_name
              FROM cash_handovers ch
              JOIN users    tl ON tl.id = ch.team_leader_id
              LEFT JOIN services s         ON s.id = tl.service_id
              LEFT JOIN users    confirmer ON confirmer.id = ch.confirmed_by
              $whereSQL
             ORDER BY ch.handover_date DESC, ch.created_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);

        // Build a download-friendly filename including the date filter.
        $today = date('Y-m-d');
        $filenameParts = ['handovers', $today];
        if (!empty($_GET['from']) || !empty($_GET['to'])) {
            $filenameParts[] = ($_GET['from'] ?? 'start') . '_to_' . ($_GET['to'] ?? 'end');
        }
        $filename = implode('_', $filenameParts) . '.csv';

        // Flush any buffered output so the CSV body isn't preceded by stray bytes
        while (ob_get_level() > 0) { ob_end_clean(); }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM so Excel opens the file correctly (especially for non-ASCII names)
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, [
            'Handover ID',
            'Handover Date',
            'Submitted At (UTC)',
            'Team Leader',
            'TL Email',
            'Service',
            'Status',
            'Confirmed By',
            'Confirmed At (UTC)',
            'Orders',
            'Cash (AED)',
            'Card (AED)',
            'Online (AED)',
            'Total (AED)',
            'TL Notes',
            'Reject Reason',
        ]);

        while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            fputcsv($out, [
                $r['id'],
                $r['handover_date'],
                $r['created_at'],
                $r['team_leader_name'],
                $r['team_leader_email'],
                $r['service_name'] ?? '',
                $r['status'],
                $r['confirmed_by_name'] ?? '',
                $r['confirmed_at'] ?? '',
                $r['order_count'],
                number_format((float)$r['cash_amount'],   2, '.', ''),
                number_format((float)$r['card_amount'],   2, '.', ''),
                number_format((float)$r['online_amount'], 2, '.', ''),
                number_format((float)$r['amount'],        2, '.', ''),
                $r['notes'] ?? '',
                $r['dispute_reason'] ?? '',
            ]);
        }
        fclose($out);
        exit;
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
            // One row PER ORDER (deduped). Per-method amounts are aggregated
            // into a JSON object so the UI can show split breakdowns. Cash/
            // card/online payments are all included — the legacy
            // `payment_method = 'cash'` filter was a holdover from when
            // handovers were cash-only and undercounted the contributing
            // orders (e.g. an 11-order handover would only return the
            // cash-paid subset).
            $ostmt = $pdo->prepare(
                "SELECT o.id, o.order_number,
                        os.daily_serial, os.completed_at,
                        s.name AS service_name,
                        COALESCE(SUM(CASE WHEN p.payment_method = 'cash'   THEN p.amount END), 0) AS cash_amount,
                        COALESCE(SUM(CASE WHEN p.payment_method = 'card'   THEN p.amount END), 0) AS card_amount,
                        COALESCE(SUM(CASE WHEN p.payment_method = 'online' THEN p.amount END), 0) AS online_amount,
                        COALESCE(SUM(p.amount), 0) AS amount,
                        GROUP_CONCAT(DISTINCT p.payment_method ORDER BY p.payment_method SEPARATOR ',') AS payment_methods
                 FROM orders o
                 JOIN order_services os ON os.order_id = o.id
                                       AND os.provider_id = ?
                                       AND os.status      = 'completed'
                 JOIN services s ON s.id = os.service_id
                 JOIN payments p ON p.order_id = o.id
                                AND p.status = 'success'
                 WHERE o.id IN ($ph)
                 GROUP BY o.id, o.order_number, os.daily_serial, os.completed_at, s.name
                 ORDER BY os.completed_at ASC, os.daily_serial ASC"
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
                    s.name AS service_name,
                    confirmer.name AS confirmed_by_name
             FROM cash_handovers ch
             JOIN users tl              ON tl.id = ch.team_leader_id
             LEFT JOIN services s       ON s.id = tl.service_id
             LEFT JOIN users  confirmer ON confirmer.id = ch.confirmed_by
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
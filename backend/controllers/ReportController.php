<?php
// backend/controllers/ReportController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Response;
use App\Middleware\AuthMiddleware;

/**
 * Reports — admin-only data exports.
 *
 *   GET /api/reports/daily?date=YYYY-MM-DD
 *   GET /api/reports/team-leader/{id}?from=&to=
 *   GET /api/reports/employee/{id}?from=&to=
 *   GET /api/reports/payments?from=&to=                 [?format=csv]
 *
 *   New detailed endpoints (all support ?format=csv):
 *   GET /api/reports/orders?from=&to=&status=&service_id=&team_leader_id=&employee_id=&payment_status=&payment_method=
 *   GET /api/reports/by-employee?from=&to=&service_id=&team_leader_id=
 *   GET /api/reports/by-team-leader?from=&to=
 *   GET /api/reports/by-service?from=&to=
 */
class ReportController
{
    // =================================================================
    // EXISTING ENDPOINTS (kept intact)
    // =================================================================

    public function daily(array $params = []): void
    {
        AuthMiddleware::require(['admin']);
        $pdo  = Database::getInstance();
        $date = (string)($_GET['date'] ?? date('Y-m-d'));

        // Unnumbered customer carts (pre-payment) are excluded from
        // every aggregate — they're not real revenue yet.
        $stmt = $pdo->prepare('
            SELECT
                COUNT(DISTINCT o.id)        AS order_count,
                COALESCE(SUM(p.amount),0)   AS total_revenue,
                COALESCE(SUM(CASE WHEN p.payment_method="cash"   THEN p.amount END),0) AS cash,
                COALESCE(SUM(CASE WHEN p.payment_method="card"   THEN p.amount END),0) AS card,
                COALESCE(SUM(CASE WHEN p.payment_method="online" THEN p.amount END),0) AS online,
                COUNT(DISTINCT CASE WHEN o.payment_status IN ("unpaid","partial") THEN o.id END) AS unpaid_orders
            FROM orders o
            LEFT JOIN payments p ON p.order_id = o.id AND p.status = "success"
            WHERE DATE(o.created_at) = ?
              AND o.order_number IS NOT NULL
        ');
        $stmt->execute([$date]);
        $summary = $stmt->fetch();

        $svc = $pdo->prepare('
            SELECT s.name, COUNT(os.id) AS count, COALESCE(SUM(os.price),0) AS revenue
            FROM order_services os
            JOIN services s ON s.id = os.service_id
            JOIN orders   o ON o.id = os.order_id
            WHERE DATE(o.created_at) = ?
              AND o.order_number IS NOT NULL
            GROUP BY s.id, s.name
            ORDER BY revenue DESC
        ');
        $svc->execute([$date]);

        $tr = $pdo->prepare('
            SELECT DATE(o.created_at) AS day,
                   COUNT(DISTINCT o.id) AS orders,
                   COALESCE(SUM(p.amount),0) AS revenue
            FROM orders o
            LEFT JOIN payments p ON p.order_id = o.id AND p.status = "success"
            WHERE DATE(o.created_at) BETWEEN DATE_SUB(?, INTERVAL 6 DAY) AND ?
              AND o.order_number IS NOT NULL
            GROUP BY DATE(o.created_at)
            ORDER BY day ASC
        ');
        $tr->execute([$date, $date]);
        $byDay = [];
        foreach ($tr->fetchAll() as $r) {
            $byDay[$r['day']] = ['orders'=>(int)$r['orders'], 'revenue'=>(float)$r['revenue']];
        }
        $week = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("$date -$i day"));
            $week[] = [
                'day'     => $d,
                'label'   => date('D', strtotime($d)),
                'orders'  => $byDay[$d]['orders']  ?? 0,
                'revenue' => $byDay[$d]['revenue'] ?? 0.0,
            ];
        }

        Response::success([
            'date'     => $date,
            'currency' => APP_CURRENCY,
            'summary'  => $summary,
            'services' => $svc->fetchAll(),
            'trend'    => $week,
        ]);
    }

    public function teamLeader(array $params): void
    {
        $user = AuthMiddleware::require(['admin','team_leader']);
        $id   = $params['id'] ?? '';
        if ($user['role'] === 'team_leader' && $user['id'] !== $id) {
            Response::error('Forbidden', 403);
        }
        $pdo  = Database::getInstance();
        $from = (string)($_GET['from'] ?? date('Y-m-01'));
        $to   = (string)($_GET['to']   ?? date('Y-m-d'));

        $stmt = $pdo->prepare('
            SELECT
                COUNT(DISTINCT o.id)                                   AS order_count,
                COALESCE(SUM(p.amount),0)                              AS total_sales,
                COALESCE(SUM(CASE WHEN p.payment_method="cash"   THEN p.amount END),0) AS cash,
                COALESCE(SUM(CASE WHEN p.payment_method="card"   THEN p.amount END),0) AS card,
                COALESCE(SUM(CASE WHEN p.payment_method="online" THEN p.amount END),0) AS online
            FROM orders o
            JOIN order_services os ON os.order_id = o.id AND os.provider_id = ?
            LEFT JOIN payments p ON p.order_id = o.id AND p.status = "success"
            WHERE DATE(o.created_at) BETWEEN ? AND ?
        ');
        $stmt->execute([$id, $from, $to]);
        Response::success(array_merge(
            ['from'=>$from, 'to'=>$to, 'currency'=>APP_CURRENCY],
            $stmt->fetch()
        ));
    }

    public function employee(array $params): void
    {
        AuthMiddleware::require(['admin']);
        $pdo  = Database::getInstance();
        $id   = $params['id'] ?? '';
        $from = (string)($_GET['from'] ?? date('Y-m-01'));
        $to   = (string)($_GET['to']   ?? date('Y-m-d'));

        $stmt = $pdo->prepare('
            SELECT
                e.name AS employee_name, e.employee_code,
                COUNT(os.id) AS jobs_handled,
                SUM(CASE WHEN os.status="completed" THEN 1 ELSE 0 END) AS completed,
                COALESCE(SUM(os.price),0) AS revenue
            FROM employees e
            LEFT JOIN order_services os ON os.employee_id = e.id
              AND DATE(COALESCE(os.completed_at, os.created_at)) BETWEEN ? AND ?
            WHERE e.id = ?
            GROUP BY e.id
        ');
        $stmt->execute([$from, $to, $id]);
        $row = $stmt->fetch();
        if (!$row) Response::error('Employee not found', 404);

        Response::success(array_merge(['from'=>$from, 'to'=>$to, 'currency'=>APP_CURRENCY], $row));
    }

    // GET /api/reports/payments  [?format=csv]
    public function payments(array $params = []): void
    {
        AuthMiddleware::require(['admin']);
        $pdo  = Database::getInstance();
        // Defaults removed: when the caller passes no dates the
        // endpoint returns ALL-TIME totals, matching the behavior of
        // /api/payments (the list endpoint). Previously this defaulted
        // to month-to-date, which caused the admin Payments page tiles
        // to disagree with the table when no date filter was active.
        // The Payments page now reads aggregate totals directly from
        // /api/payments, so this endpoint is here only for CSV export
        // and any legacy callers.
        $from = (string)($_GET['from'] ?? '');
        $to   = (string)($_GET['to']   ?? '');

        $dateClause = '';
        $bind = [];
        if ($from !== '' && $to !== '') {
            $dateClause = ' AND DATE(created_at) BETWEEN ? AND ?';
            $bind = [$from, $to];
        } elseif ($from !== '') {
            $dateClause = ' AND DATE(created_at) >= ?';
            $bind = [$from];
        } elseif ($to !== '') {
            $dateClause = ' AND DATE(created_at) <= ?';
            $bind = [$to];
        }

        $agg = $pdo->prepare('
            SELECT payment_method,
                   COUNT(*) AS count,
                   COALESCE(SUM(amount),0) AS total
            FROM payments
            WHERE status = "success"' . $dateClause . '
            GROUP BY payment_method
        ');
        $agg->execute($bind);
        $byMethod = ['cash'=>0.0, 'card'=>0.0, 'online'=>0.0];
        $counts   = ['cash'=>0,   'card'=>0,   'online'=>0];
        foreach ($agg->fetchAll() as $r) {
            $byMethod[$r['payment_method']] = (float)$r['total'];
            $counts[$r['payment_method']]   = (int)$r['count'];
        }

        $day = $pdo->prepare('
            SELECT DATE(p.created_at) AS day,
                   p.payment_method,
                   COUNT(*) AS count,
                   COALESCE(SUM(p.amount),0) AS total
            FROM payments p
            WHERE p.status = "success"' . $dateClause . '
            GROUP BY DATE(p.created_at), p.payment_method
            ORDER BY day ASC, p.payment_method ASC
        ');
        $day->execute($bind);
        $rows = $day->fetchAll();

        if (($_GET['format'] ?? '') === 'csv') {
            $rangeLabel = ($from !== '' && $to !== '') ? ($from . ' to ' . $to)
                        : ($from !== '' ? ('from ' . $from)
                        : ($to !== '' ? ('to ' . $to) : 'all time'));
            $fileLabel  = ($from !== '' && $to !== '') ? ($from . '_to_' . $to)
                        : ($from !== '' ? ('from_' . $from)
                        : ($to !== '' ? ('to_' . $to) : 'all_time'));
            $this->csvHeader("payments_{$fileLabel}.csv");
            $out = fopen('php://output', 'w');
            $this->csvRow($out, ['Payment Report', $rangeLabel]);
            $this->csvRow($out, []);
            $this->csvRow($out, ['Summary by method']);
            $this->csvRow($out, ['Method', 'Count', 'Total (' . APP_CURRENCY . ')']);
            foreach (['cash','card','online'] as $m) {
                $this->csvRow($out, [ucfirst($m), $counts[$m], number_format($byMethod[$m], 2, '.', '')]);
            }
            $this->csvRow($out, ['Total', array_sum($counts), number_format(array_sum($byMethod), 2, '.', '')]);
            $this->csvRow($out, []);
            $this->csvRow($out, ['Daily breakdown']);
            $this->csvRow($out, ['Date', 'Method', 'Count', 'Total (' . APP_CURRENCY . ')']);
            foreach ($rows as $r) {
                $this->csvRow($out, [
                    $r['day'],
                    ucfirst((string)$r['payment_method']),
                    $r['count'],
                    number_format((float)$r['total'], 2, '.', ''),
                ]);
            }
            fclose($out);
            exit;
        }

        $byDay = [];
        foreach ($rows as $r) {
            $d = $r['day'];
            if (!isset($byDay[$d])) $byDay[$d] = ['day'=>$d, 'cash'=>0.0, 'card'=>0.0, 'online'=>0.0, 'total'=>0.0];
            $byDay[$d][$r['payment_method']] = (float)$r['total'];
            $byDay[$d]['total'] += (float)$r['total'];
        }

        Response::success([
            'from'     => $from,
            'to'       => $to,
            'currency' => APP_CURRENCY,
            'totals'   => $byMethod,
            'counts'   => $counts,
            'total'    => array_sum($byMethod),
            'by_day'   => array_values($byDay),
        ]);
    }

    // =================================================================
    // NEW DETAILED ENDPOINTS
    // =================================================================

    /**
     * GET /api/reports/orders  [?format=csv]
     * Full transactional list. One row per order_service.
     */
    public function orders(array $params = []): void
    {
        AuthMiddleware::require(['admin']);
        $pdo  = Database::getInstance();
        $from = (string)($_GET['from'] ?? date('Y-m-01'));
        $to   = (string)($_GET['to']   ?? date('Y-m-d'));

        $where = [
            'DATE(o.created_at) BETWEEN ? AND ?',
            // Hide customer-self orders that haven't been paid yet
            // (those have order_number = NULL). Matches the filter
            // used everywhere else in admin (orders list, payments
            // list, summary tiles, all four report tabs).
            'o.order_number IS NOT NULL',
        ];
        $bind  = [$from, $to];

        if (!empty($_GET['status'])) {
            $statuses = array_map('trim', explode(',', (string)$_GET['status']));
            $valid    = ['pending','assigned','accepted','in_progress','completed','cancelled','rejected'];
            $statuses = array_values(array_intersect($statuses, $valid));
            if (!empty($statuses)) {
                $placeholders = implode(',', array_fill(0, count($statuses), '?'));
                $where[] = "os.status IN ({$placeholders})";
                $bind    = array_merge($bind, $statuses);
            }
        }
        if (!empty($_GET['payment_status'])) {
            $ps = (string)$_GET['payment_status'];
            if (in_array($ps, ['paid','partial','unpaid'], true)) {
                $where[] = 'o.payment_status = ?';
                $bind[]  = $ps;
            }
        }
        if (!empty($_GET['payment_method'])) {
            $pm = (string)$_GET['payment_method'];
            if (in_array($pm, ['cash','card','online'], true)) {
                $where[] = 'EXISTS (SELECT 1 FROM payments p2 WHERE p2.order_id = o.id AND p2.status="success" AND p2.payment_method = ?)';
                $bind[]  = $pm;
            }
        }
        if (!empty($_GET['service_id'])) {
            $where[] = 'os.service_id = ?';
            $bind[]  = (string)$_GET['service_id'];
        }
        if (!empty($_GET['team_leader_id'])) {
            $where[] = 'os.provider_id = ?';
            $bind[]  = (string)$_GET['team_leader_id'];
        }
        if (!empty($_GET['employee_id'])) {
            $where[] = 'os.employee_id = ?';
            $bind[]  = (string)$_GET['employee_id'];
        }
        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        $sql = "
            SELECT
                o.order_number,
                os.daily_serial,
                os.serial_date,
                o.created_at,
                s.name              AS service_name,
                tl.name             AS team_leader_name,
                e.employee_code,
                e.name              AS employee_name,
                pl.terminal         AS location_terminal,
                pl.name             AS location_name,
                os.location_details,
                os.status           AS job_status,
                o.payment_status,
                o.total_amount,
                o.paid_amount,
                (o.total_amount - o.paid_amount) AS balance,
                (
                  SELECT GROUP_CONCAT(DISTINCT p.payment_method ORDER BY p.payment_method SEPARATOR ',')
                  FROM payments p
                  WHERE p.order_id = o.id AND p.status = 'success'
                ) AS payment_methods,
                os.completed_at
            FROM order_services os
            JOIN orders   o   ON o.id  = os.order_id
            JOIN services s   ON s.id  = os.service_id
            LEFT JOIN users     tl ON tl.id = os.provider_id
            LEFT JOIN employees e  ON e.id  = os.employee_id
            LEFT JOIN parking_locations pl ON pl.id = os.location_id
            {$whereSQL}
            ORDER BY o.created_at DESC, os.daily_serial DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $rows = $stmt->fetchAll();

        $totals = [
            'rows'         => count($rows),
            'total_amount' => 0.0,
            'paid_amount'  => 0.0,
            'balance'      => 0.0,
        ];
        foreach ($rows as $r) {
            $totals['total_amount'] += (float)$r['total_amount'];
            $totals['paid_amount']  += (float)$r['paid_amount'];
            $totals['balance']      += (float)$r['balance'];
        }

        if (($_GET['format'] ?? '') === 'csv') {
            $this->csvHeader("orders_{$from}_to_{$to}.csv");
            $out = fopen('php://output', 'w');
            $this->csvRow($out, ['Orders Report', $from . ' to ' . $to]);
            $this->csvRow($out, ['Generated', date('Y-m-d H:i:s')]);
            $this->csvRow($out, []);
            $this->csvRow($out, [
                'Order #', 'Serial', 'Date', 'Time',
                'Service', 'Team Leader',
                'Employee Code', 'Employee Name',
                'Location', 'Bay/Details',
                'Job Status', 'Payment Status', 'Payment Methods',
                'Total (' . APP_CURRENCY . ')',
                'Paid (' . APP_CURRENCY . ')',
                'Balance (' . APP_CURRENCY . ')',
                'Completed At',
            ]);
            foreach ($rows as $r) {
                $dt   = new \DateTime($r['created_at']);
                $loc  = trim(($r['location_terminal'] ? $r['location_terminal'] . ' · ' : '') . ($r['location_name'] ?? ''));
                $this->csvRow($out, [
                    $r['order_number'],
                    $r['daily_serial'],
                    $dt->format('Y-m-d'),
                    $dt->format('H:i'),
                    $r['service_name'],
                    $r['team_leader_name'],
                    $r['employee_code'],
                    $r['employee_name'],
                    $loc,
                    $r['location_details'],
                    $r['job_status'],
                    $r['payment_status'],
                    $r['payment_methods'],
                    number_format((float)$r['total_amount'], 2, '.', ''),
                    number_format((float)$r['paid_amount'], 2, '.', ''),
                    number_format((float)$r['balance'], 2, '.', ''),
                    $r['completed_at'],
                ]);
            }
            $this->csvRow($out, []);
            $this->csvRow($out, [
                'TOTALS', '', '', '', '', '', '', '', '', '', '', '', '',
                number_format($totals['total_amount'], 2, '.', ''),
                number_format($totals['paid_amount'],  2, '.', ''),
                number_format($totals['balance'],      2, '.', ''),
            ]);
            fclose($out);
            exit;
        }

        Response::success([
            'from'     => $from,
            'to'       => $to,
            'currency' => APP_CURRENCY,
            'totals'   => $totals,
            'rows'     => $rows,
        ]);
    }

    public function byEmployee(array $params = []): void
    {
        AuthMiddleware::require(['admin']);
        $pdo  = Database::getInstance();
        $from = (string)($_GET['from'] ?? date('Y-m-01'));
        $to   = (string)($_GET['to']   ?? date('Y-m-d'));

        $extra = '';
        $extraBind = [];
        if (!empty($_GET['service_id'])) {
            $extra      .= ' AND e.service_id = ?';
            $extraBind[] = (string)$_GET['service_id'];
        }
        // Filter the employee performance report to a single team
        // leader. Joins through employees.team_leader_id, which is
        // the canonical TL→employee mapping in the new schema.
        if (!empty($_GET['team_leader_id'])) {
            $extra      .= ' AND e.team_leader_id = ?';
            $extraBind[] = (string)$_GET['team_leader_id'];
        }

        // One row per employee. Columns parallel byTeamLeader so the
        // two reports compose cleanly:
        //   jobs / cash / card / online / paid / unpaid / revenue
        // Unnumbered customer orders (pre-payment carts) excluded.
        // Cancelled status excluded too (no cancel UI any more).
        //
        // Each metric in its own pre-aggregated subquery to avoid
        // cartesian-product inflation (see byTeamLeader's commentary
        // for why the naive join-then-group approach is wrong).
        $sql = "
            SELECT
                e.id,
                e.employee_code,
                e.name      AS employee_name,
                e.phone,
                e.is_active,
                s.name      AS service_name,
                tl.name     AS team_leader_name,
                COALESCE(os_agg.jobs_total, 0) AS jobs_total,
                COALESCE(pay_agg.cash,   0.0)  AS cash,
                COALESCE(pay_agg.card,   0.0)  AS card,
                COALESCE(pay_agg.online, 0.0)  AS online,
                COALESCE(pay_agg.paid,   0.0)  AS paid,
                COALESCE(unp_agg.unpaid, 0.0)  AS unpaid,
                COALESCE(pay_agg.paid, 0.0) + COALESCE(unp_agg.unpaid, 0.0) AS revenue
            FROM employees e
            LEFT JOIN services s  ON s.id  = e.service_id
            LEFT JOIN users    tl ON tl.id = e.team_leader_id

            -- Job counts per employee
            LEFT JOIN (
                SELECT os.employee_id, COUNT(*) AS jobs_total
                FROM order_services os
                JOIN orders o ON o.id = os.order_id
                WHERE DATE(os.created_at) BETWEEN ? AND ?
                  AND o.order_number IS NOT NULL
                  AND os.status != 'cancelled'
                GROUP BY os.employee_id
            ) os_agg ON os_agg.employee_id = e.id

            -- Payment aggregates per employee, deduped at order level
            LEFT JOIN (
                SELECT
                    os.employee_id,
                    COALESCE(SUM(CASE WHEN p.payment_method = 'cash'   THEN p.amount END), 0) AS cash,
                    COALESCE(SUM(CASE WHEN p.payment_method = 'card'   THEN p.amount END), 0) AS card,
                    COALESCE(SUM(CASE WHEN p.payment_method = 'online' THEN p.amount END), 0) AS online,
                    COALESCE(SUM(p.amount), 0) AS paid
                FROM payments p
                JOIN orders o ON o.id = p.order_id
                JOIN (
                    SELECT order_id, MAX(employee_id) AS employee_id
                    FROM order_services
                    WHERE employee_id IS NOT NULL
                    GROUP BY order_id
                ) os ON os.order_id = p.order_id
                WHERE p.status = 'success'
                  AND DATE(p.created_at) BETWEEN ? AND ?
                  AND o.order_number IS NOT NULL
                GROUP BY os.employee_id
            ) pay_agg ON pay_agg.employee_id = e.id

            -- Unpaid amounts per employee (balance due on open jobs)
            LEFT JOIN (
                SELECT
                    os_dedup.employee_id,
                    COALESCE(SUM(o.total_amount - o.paid_amount), 0) AS unpaid
                FROM orders o
                JOIN (
                    SELECT order_id, MAX(employee_id) AS employee_id
                    FROM order_services
                    WHERE employee_id IS NOT NULL
                      AND status IN ('accepted','in_progress')
                    GROUP BY order_id
                ) os_dedup ON os_dedup.order_id = o.id
                WHERE o.payment_status IN ('unpaid','partial')
                  AND o.order_number IS NOT NULL
                  AND DATE(o.created_at) BETWEEN ? AND ?
                GROUP BY os_dedup.employee_id
            ) unp_agg ON unp_agg.employee_id = e.id

            WHERE 1=1 {$extra}
            ORDER BY revenue DESC, e.name ASC
        ";

        // bind covers 3 date-window pairs (jobs, payments, unpaid) +
        // whatever extra filters were appended ($extraBind).
        $stmt = $pdo->prepare($sql);
        $allBind = array_merge([$from, $to, $from, $to, $from, $to], $extraBind);
        $stmt->execute($allBind);
        $rows = $stmt->fetchAll();

        $totals = [
            'employees'  => count($rows),
            'jobs_total' => 0,
            'cash'       => 0.0,
            'card'       => 0.0,
            'online'     => 0.0,
            'paid'       => 0.0,
            'unpaid'     => 0.0,
            'revenue'    => 0.0,
        ];
        foreach ($rows as $r) {
            $totals['jobs_total'] += (int)$r['jobs_total'];
            $totals['cash']       += (float)$r['cash'];
            $totals['card']       += (float)$r['card'];
            $totals['online']     += (float)$r['online'];
            $totals['paid']       += (float)$r['paid'];
            $totals['unpaid']     += (float)$r['unpaid'];
            $totals['revenue']    += (float)$r['revenue'];
        }

        if (($_GET['format'] ?? '') === 'csv') {
            $this->csvHeader("by_employee_{$from}_to_{$to}.csv");
            $out = fopen('php://output', 'w');
            $this->csvRow($out, ['Employee Performance Report', $from . ' to ' . $to]);
            $this->csvRow($out, ['Generated', date('Y-m-d H:i:s')]);
            $this->csvRow($out, []);
            $this->csvRow($out, [
                'Code', 'Name', 'Phone', 'Service', 'Team Leader', 'Active?',
                'Jobs',
                'Cash (' . APP_CURRENCY . ')',
                'Card (' . APP_CURRENCY . ')',
                'Online (' . APP_CURRENCY . ')',
                'Paid (' . APP_CURRENCY . ')',
                'Unpaid (' . APP_CURRENCY . ')',
                'Revenue (' . APP_CURRENCY . ')',
            ]);
            foreach ($rows as $r) {
                $this->csvRow($out, [
                    $r['employee_code'],
                    $r['employee_name'],
                    $r['phone'],
                    $r['service_name'],
                    $r['team_leader_name'],
                    $r['is_active'] ? 'Yes' : 'No',
                    $r['jobs_total'],
                    number_format((float)$r['cash'],    2, '.', ''),
                    number_format((float)$r['card'],    2, '.', ''),
                    number_format((float)$r['online'],  2, '.', ''),
                    number_format((float)$r['paid'],    2, '.', ''),
                    number_format((float)$r['unpaid'],  2, '.', ''),
                    number_format((float)$r['revenue'], 2, '.', ''),
                ]);
            }
            $this->csvRow($out, []);
            $this->csvRow($out, [
                'TOTALS', '', '', '', '', '',
                $totals['jobs_total'],
                number_format($totals['cash'],    2, '.', ''),
                number_format($totals['card'],    2, '.', ''),
                number_format($totals['online'],  2, '.', ''),
                number_format($totals['paid'],    2, '.', ''),
                number_format($totals['unpaid'],  2, '.', ''),
                number_format($totals['revenue'], 2, '.', ''),
            ]);
            fclose($out);
            exit;
        }

        Response::success([
            'from'     => $from,
            'to'       => $to,
            'currency' => APP_CURRENCY,
            'totals'   => $totals,
            'rows'     => $rows,
        ]);
    }

    public function byTeamLeader(array $params = []): void
    {
        AuthMiddleware::require(['admin']);
        $pdo  = Database::getInstance();
        $from = (string)($_GET['from'] ?? date('Y-m-01'));
        $to   = (string)($_GET['to']   ?? date('Y-m-d'));

        // What this report shows (one row per team leader):
        //   * team_size    — active employees under this TL
        //   * jobs_total   — TL's accepted-or-completed jobs in date window
        //   * cash/card/online — collected per method
        //   * paid         — sum of all successful payments on this TL's jobs
        //                    (== cash + card + online by definition)
        //   * unpaid       — balance due on TL's in-flight (accepted /
        //                    in_progress) orders. Sum of
        //                    (order.total_amount - order.paid_amount) over
        //                    the TL's open jobs.
        //   * revenue      — paid + unpaid (total billed for this TL's
        //                    work in the period)
        //
        // We deliberately exclude unnumbered customer orders (those are
        // pre-payment carts) by JOINing through orders and requiring
        // o.order_number IS NOT NULL.
        //
        // We deliberately exclude 'cancelled' from job counts since
        // user requested cancelled-orders removal.
        $sql = "
            SELECT
                tl.id,
                tl.name  AS team_leader_name,
                tl.email,
                tl.phone,
                s.name   AS service_name,
                COALESCE(emp_agg.team_size,    0) AS team_size,
                COALESCE(os_agg.jobs_total,    0) AS jobs_total,
                COALESCE(pay_agg.cash,       0.0) AS cash,
                COALESCE(pay_agg.card,       0.0) AS card,
                COALESCE(pay_agg.online,     0.0) AS online,
                COALESCE(pay_agg.paid,       0.0) AS paid,
                COALESCE(unp_agg.unpaid,     0.0) AS unpaid,
                COALESCE(pay_agg.paid,   0.0) + COALESCE(unp_agg.unpaid, 0.0) AS revenue
            FROM users tl
            LEFT JOIN services s ON s.id = tl.service_id

            -- Active employees per TL (one row per TL)
            LEFT JOIN (
                SELECT team_leader_id, COUNT(*) AS team_size
                FROM employees
                WHERE is_active = 1
                GROUP BY team_leader_id
            ) emp_agg ON emp_agg.team_leader_id = tl.id

            -- Job aggregates per TL.
            --
            -- Effective TL of an order_services row = the provider
            -- (TL who accepted) if set, otherwise the team leader of
            -- the employee the customer picked when booking. This is
            -- how customer-self orders get attributed: the customer
            -- chose Mohammed -> Mohammed-s TL (Ahmed) owns the job in
            -- reports. The handover flow uses a stricter rule
            -- (provider_id only), so cross-side payments do not leak
            -- into the TL handover totals -- see HandoverController.
            LEFT JOIN (
                SELECT
                    COALESCE(os.provider_id, e.team_leader_id) AS effective_tl_id,
                    COUNT(*) AS jobs_total
                FROM order_services os
                JOIN orders o ON o.id = os.order_id
                LEFT JOIN employees e ON e.id = os.employee_id
                WHERE DATE(os.created_at) BETWEEN ? AND ?
                  AND o.order_number IS NOT NULL
                  AND os.status != 'cancelled'
                  AND COALESCE(os.provider_id, e.team_leader_id) IS NOT NULL
                GROUP BY effective_tl_id
            ) os_agg ON os_agg.effective_tl_id = tl.id

            -- Payment aggregates per TL. Same effective-TL rule:
            -- customer-self orders with a picked employee attribute to
            -- the employee team leader.
            --
            -- The order→TL mapping is computed first (one row per
            -- order_id, with the effective TL), then joined to
            -- payments. That way an order with a split payment (two
            -- payment rows) doesn't trip up the attribution.
            LEFT JOIN (
                SELECT
                    map.effective_tl_id,
                    COALESCE(SUM(CASE WHEN p.payment_method = 'cash'   THEN p.amount END), 0) AS cash,
                    COALESCE(SUM(CASE WHEN p.payment_method = 'card'   THEN p.amount END), 0) AS card,
                    COALESCE(SUM(CASE WHEN p.payment_method = 'online' THEN p.amount END), 0) AS online,
                    COALESCE(SUM(p.amount), 0) AS paid
                FROM payments p
                JOIN orders o ON o.id = p.order_id
                JOIN (
                    -- One row per order with the effective TL. If an
                    -- order has multiple order_services rows mapping
                    -- to different TLs, we take MAX (deterministic;
                    -- shouldn't realistically happen since a customer
                    -- order has one service).
                    SELECT
                        os.order_id,
                        MAX(COALESCE(os.provider_id, e.team_leader_id)) AS effective_tl_id
                    FROM order_services os
                    LEFT JOIN employees e ON e.id = os.employee_id
                    WHERE COALESCE(os.provider_id, e.team_leader_id) IS NOT NULL
                    GROUP BY os.order_id
                ) map ON map.order_id = p.order_id
                WHERE p.status = 'success'
                  AND DATE(p.created_at) BETWEEN ? AND ?
                  AND o.order_number IS NOT NULL
                GROUP BY map.effective_tl_id
            ) pay_agg ON pay_agg.effective_tl_id = tl.id

            -- Unpaid amount per TL: balance due on jobs the TL owns.
            -- Owns again uses the effective-TL rule. Aggregates at
            -- the order level (not per-os) so split orders are not
            -- double-counted.
            LEFT JOIN (
                SELECT
                    map.effective_tl_id,
                    COALESCE(SUM(o.total_amount - o.paid_amount), 0) AS unpaid
                FROM orders o
                JOIN (
                    SELECT
                        os.order_id,
                        MAX(COALESCE(os.provider_id, e.team_leader_id)) AS effective_tl_id
                    FROM order_services os
                    LEFT JOIN employees e ON e.id = os.employee_id
                    WHERE os.status IN ('accepted','in_progress','pending')
                      AND COALESCE(os.provider_id, e.team_leader_id) IS NOT NULL
                    GROUP BY os.order_id
                ) map ON map.order_id = o.id
                WHERE o.payment_status IN ('unpaid','partial')
                  AND o.order_number IS NOT NULL
                  AND DATE(o.created_at) BETWEEN ? AND ?
                GROUP BY map.effective_tl_id
            ) unp_agg ON unp_agg.effective_tl_id = tl.id

            WHERE tl.role = 'team_leader'
            ORDER BY revenue DESC, tl.name ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$from, $to, $from, $to, $from, $to]);
        $rows = $stmt->fetchAll();

        $totals = [
            'team_leaders' => count($rows),
            'jobs_total'   => 0,
            'cash'         => 0.0,
            'card'         => 0.0,
            'online'       => 0.0,
            'paid'         => 0.0,
            'unpaid'       => 0.0,
            'revenue'      => 0.0,
        ];
        foreach ($rows as $r) {
            $totals['jobs_total'] += (int)$r['jobs_total'];
            $totals['cash']       += (float)$r['cash'];
            $totals['card']       += (float)$r['card'];
            $totals['online']     += (float)$r['online'];
            $totals['paid']       += (float)$r['paid'];
            $totals['unpaid']     += (float)$r['unpaid'];
            $totals['revenue']    += (float)$r['revenue'];
        }

        if (($_GET['format'] ?? '') === 'csv') {
            $this->csvHeader("by_team_leader_{$from}_to_{$to}.csv");
            $out = fopen('php://output', 'w');
            $this->csvRow($out, ['Team Leader Performance Report', $from . ' to ' . $to]);
            $this->csvRow($out, ['Generated', date('Y-m-d H:i:s')]);
            $this->csvRow($out, []);
            $this->csvRow($out, [
                'Name', 'Email', 'Phone', 'Service', 'Team Size',
                'Jobs',
                'Cash (' . APP_CURRENCY . ')',
                'Card (' . APP_CURRENCY . ')',
                'Online (' . APP_CURRENCY . ')',
                'Paid (' . APP_CURRENCY . ')',
                'Unpaid (' . APP_CURRENCY . ')',
                'Revenue (' . APP_CURRENCY . ')',
            ]);
            foreach ($rows as $r) {
                $this->csvRow($out, [
                    $r['team_leader_name'],
                    $r['email'],
                    $r['phone'],
                    $r['service_name'],
                    $r['team_size'],
                    $r['jobs_total'],
                    number_format((float)$r['cash'],    2, '.', ''),
                    number_format((float)$r['card'],    2, '.', ''),
                    number_format((float)$r['online'],  2, '.', ''),
                    number_format((float)$r['paid'],    2, '.', ''),
                    number_format((float)$r['unpaid'],  2, '.', ''),
                    number_format((float)$r['revenue'], 2, '.', ''),
                ]);
            }
            $this->csvRow($out, []);
            $this->csvRow($out, [
                'TOTALS', '', '', '', '',
                $totals['jobs_total'],
                number_format($totals['cash'],    2, '.', ''),
                number_format($totals['card'],    2, '.', ''),
                number_format($totals['online'],  2, '.', ''),
                number_format($totals['paid'],    2, '.', ''),
                number_format($totals['unpaid'],  2, '.', ''),
                number_format($totals['revenue'], 2, '.', ''),
            ]);
            fclose($out);
            exit;
        }

        Response::success([
            'from'     => $from,
            'to'       => $to,
            'currency' => APP_CURRENCY,
            'totals'   => $totals,
            'rows'     => $rows,
        ]);
    }

    public function byService(array $params = []): void
    {
        AuthMiddleware::require(['admin']);
        $pdo  = Database::getInstance();
        $from = (string)($_GET['from'] ?? date('Y-m-01'));
        $to   = (string)($_GET['to']   ?? date('Y-m-d'));

        // Same cartesian-product trap as byTeamLeader: joining
        // order_services AND payments at the same level multiplies
        // each row by the other's child count whenever a service has
        // an order with split payments (multiple payment rows per
        // order_service). The fix is to pre-aggregate each side and
        // merge.
        $sql = "
            SELECT
                s.id,
                s.name AS service_name,
                s.base_price,
                COALESCE(os_agg.jobs_total,     0) AS jobs_total,
                COALESCE(os_agg.jobs_completed, 0) AS jobs_completed,
                COALESCE(os_agg.jobs_cancelled, 0) AS jobs_cancelled,
                COALESCE(os_agg.revenue,      0.0) AS revenue,
                COALESCE(pay_agg.cash,        0.0) AS cash,
                COALESCE(pay_agg.card,        0.0) AS card,
                COALESCE(pay_agg.online,      0.0) AS online
            FROM services s

            -- Job aggregates per service (one row per service).
            -- Excludes unnumbered customer carts (pre-payment) and
            -- cancelled rows (consistent with byTL/byEmployee).
            LEFT JOIN (
                SELECT
                    os.service_id,
                    COUNT(*)                                                   AS jobs_total,
                    SUM(CASE WHEN os.status = 'completed' THEN 1 ELSE 0 END)   AS jobs_completed,
                    SUM(CASE WHEN os.status = 'cancelled' THEN 1 ELSE 0 END)   AS jobs_cancelled,
                    COALESCE(SUM(os.price), 0)                                 AS revenue
                FROM order_services os
                JOIN orders o ON o.id = os.order_id
                WHERE DATE(os.created_at) BETWEEN ? AND ?
                  AND o.order_number IS NOT NULL
                GROUP BY os.service_id
            ) os_agg ON os_agg.service_id = s.id

            -- Payment aggregates per service. Same dedup-via-subquery
            -- trick we use in byTeamLeader: collapse the
            -- order→service mapping to one row per order before
            -- joining payments, so a payment shared between two
            -- order_services of the same order doesn't double-count.
            LEFT JOIN (
                SELECT
                    os.service_id,
                    COALESCE(SUM(CASE WHEN p.payment_method = 'cash'   THEN p.amount END), 0) AS cash,
                    COALESCE(SUM(CASE WHEN p.payment_method = 'card'   THEN p.amount END), 0) AS card,
                    COALESCE(SUM(CASE WHEN p.payment_method = 'online' THEN p.amount END), 0) AS online
                FROM payments p
                JOIN orders o ON o.id = p.order_id
                JOIN (
                    SELECT order_id, MAX(service_id) AS service_id
                    FROM order_services
                    GROUP BY order_id
                ) os ON os.order_id = p.order_id
                WHERE p.status = 'success'
                  AND DATE(p.created_at) BETWEEN ? AND ?
                  AND o.order_number IS NOT NULL
                GROUP BY os.service_id
            ) pay_agg ON pay_agg.service_id = s.id

            ORDER BY revenue DESC, s.name ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$from, $to, $from, $to]);
        $rows = $stmt->fetchAll();

        $totals = [
            'services'       => count($rows),
            'jobs_total'     => 0,
            'jobs_completed' => 0,
            'revenue'        => 0.0,
            'cash'           => 0.0,
            'card'           => 0.0,
            'online'         => 0.0,
        ];
        foreach ($rows as $r) {
            $totals['jobs_total']     += (int)$r['jobs_total'];
            $totals['jobs_completed'] += (int)$r['jobs_completed'];
            $totals['revenue']        += (float)$r['revenue'];
            $totals['cash']           += (float)$r['cash'];
            $totals['card']           += (float)$r['card'];
            $totals['online']         += (float)$r['online'];
        }

        if (($_GET['format'] ?? '') === 'csv') {
            $this->csvHeader("by_service_{$from}_to_{$to}.csv");
            $out = fopen('php://output', 'w');
            $this->csvRow($out, ['Service Performance Report', $from . ' to ' . $to]);
            $this->csvRow($out, ['Generated', date('Y-m-d H:i:s')]);
            $this->csvRow($out, []);
            $this->csvRow($out, [
                'Service', 'Base Price (' . APP_CURRENCY . ')',
                'Jobs Total', 'Completed', 'Cancelled',
                'Revenue (' . APP_CURRENCY . ')',
                'Cash (' . APP_CURRENCY . ')',
                'Card (' . APP_CURRENCY . ')',
                'Online (' . APP_CURRENCY . ')',
            ]);
            foreach ($rows as $r) {
                $this->csvRow($out, [
                    $r['service_name'],
                    number_format((float)$r['base_price'], 2, '.', ''),
                    $r['jobs_total'],
                    $r['jobs_completed'],
                    $r['jobs_cancelled'],
                    number_format((float)$r['revenue'], 2, '.', ''),
                    number_format((float)$r['cash'],    2, '.', ''),
                    number_format((float)$r['card'],    2, '.', ''),
                    number_format((float)$r['online'],  2, '.', ''),
                ]);
            }
            $this->csvRow($out, []);
            $this->csvRow($out, [
                'TOTALS', '',
                $totals['jobs_total'],
                $totals['jobs_completed'],
                '',
                number_format($totals['revenue'], 2, '.', ''),
                number_format($totals['cash'],    2, '.', ''),
                number_format($totals['card'],    2, '.', ''),
                number_format($totals['online'],  2, '.', ''),
            ]);
            fclose($out);
            exit;
        }

        Response::success([
            'from'     => $from,
            'to'       => $to,
            'currency' => APP_CURRENCY,
            'totals'   => $totals,
            'rows'     => $rows,
        ]);
    }

    // =================================================================
    // CSV helpers
    // =================================================================
    private function csvHeader(string $filename): void
    {
        $filename = preg_replace('/[^A-Za-z0-9_.\-]/', '_', $filename);
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store, max-age=0');
            // UTF-8 BOM so Excel opens our °/é/× correctly
            echo "\xEF\xBB\xBF";
        }
    }

    /**
     * Write one CSV row.
     *
     * PHP 8.4 deprecated the implicit $escape default for fputcsv — every
     * call now has to pass all 5 positional args, or PHP raises an E_DEPRECATED.
     * We always pass escape="" so the obscure backslash-escape behaviour
     * (which broke spreadsheet apps anyway) is disabled.
     *
     * @param resource $handle
     * @param array<int|string,mixed> $row
     */
    private function csvRow($handle, array $row): void
    {
        fputcsv($handle, $row, ',', '"', '');
    }
}
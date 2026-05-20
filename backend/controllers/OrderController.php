<?php
// backend/controllers/OrderController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\{Response, Request};
use App\Middleware\AuthMiddleware;

/**
 * Order list & detail.
 *
 *   GET   /api/orders                  index()    — admin list (TL sees only own)
 *   GET   /api/orders/{id}             show()     — full detail (services + payments)
 *   GET   /api/orders/summary          summary()  — counts + AED totals for stat cards
 *   PATCH /api/orders/{id}/cancel      cancel()   — admin cancels an order
 *
 * Filters supported by index():
 *   status, payment_status, service_id, team_leader_id, employee_id,
 *   from, to, q (plate/phone/customer/order_number), page, limit
 */
class OrderController
{
    // GET /api/orders
    public function index(array $params = []): void
    {
        $user = AuthMiddleware::require(['admin', 'team_leader']);
        $pdo  = Database::getInstance();

        // Baseline filter: hide customer-self orders that haven't been
        // paid yet. Those are pre-checkout cart records with
        // order_number = NULL and no real existence in the operator's
        // workflow. They become "real" (numbered) only when payment
        // succeeds. TL-created orders always have a number from
        // creation, so this filter doesn't touch them.
        $where = ['o.order_number IS NOT NULL'];
        $bind  = [];

        // TLs only see orders that include their service AND were touched by them
        if ($user['role'] === 'team_leader') {
            $where[] = 'EXISTS (
                SELECT 1 FROM order_services os
                WHERE os.order_id = o.id
                  AND os.service_id = ?
                  AND os.provider_id = ?
            )';
            $bind[] = $user['service_id'];
            $bind[] = $user['id'];
        }

        if (!empty($_GET['payment_status'])
            && in_array($_GET['payment_status'], ['unpaid','partial','paid'], true)) {
            $where[] = 'o.payment_status = ?';
            $bind[]  = (string)$_GET['payment_status'];
        }

        if (!empty($_GET['service_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM order_services os WHERE os.order_id = o.id AND os.service_id = ?)';
            $bind[]  = (string)$_GET['service_id'];
        }

        if (!empty($_GET['team_leader_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM order_services os WHERE os.order_id = o.id AND os.provider_id = ?)';
            $bind[]  = (string)$_GET['team_leader_id'];
        }

        if (!empty($_GET['employee_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM order_services os WHERE os.order_id = o.id AND os.employee_id = ?)';
            $bind[]  = (string)$_GET['employee_id'];
        }

        if (!empty($_GET['source'])
            && in_array($_GET['source'], ['team_leader','customer_qr','customer_web'], true)) {
            $where[] = 'o.source = ?';
            $bind[]  = (string)$_GET['source'];
        }

        if (!empty($_GET['from'])) {
            $where[] = 'DATE(o.created_at) >= ?';
            $bind[]  = (string)$_GET['from'];
        }
        if (!empty($_GET['to'])) {
            $where[] = 'DATE(o.created_at) <= ?';
            $bind[]  = (string)$_GET['to'];
        }
        if (!empty($_GET['date'])) {
            $where[] = 'DATE(o.created_at) = ?';
            $bind[]  = (string)$_GET['date'];
        }

        if (!empty($_GET['q'])) {
            $q = '%' . trim((string)$_GET['q']) . '%';
            $where[] = '(o.vehicle_plate LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ? OR CAST(o.order_number AS CHAR) LIKE ?)';
            array_push($bind, $q, $q, $q, $q);
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(200, max(10, (int)($_GET['limit'] ?? 25)));
        $offset = ($page - 1) * $limit;

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o $whereSQL");
        $countStmt->execute($bind);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT o.id, o.order_number, o.vehicle_plate, o.vehicle_make, o.vehicle_model,
                   o.customer_name, o.customer_phone, o.customer_email,
                   o.total_amount, o.paid_amount, o.payment_status, o.source,
                   o.notes, o.tracking_token, o.created_at,
                   creator.name AS created_by_name,
                   pl.name AS location_name,
                   (SELECT GROUP_CONCAT(s.name SEPARATOR ', ')
                      FROM order_services os JOIN services s ON s.id = os.service_id
                     WHERE os.order_id = o.id) AS service_names,
                   (SELECT GROUP_CONCAT(DISTINCT os.daily_serial)
                      FROM order_services os WHERE os.order_id = o.id) AS daily_serials,
                   (SELECT GROUP_CONCAT(DISTINCT tl.name SEPARATOR ', ')
                      FROM order_services os
                      LEFT JOIN users tl ON tl.id = os.provider_id
                     WHERE os.order_id = o.id AND tl.name IS NOT NULL) AS team_leader_names,
                   (SELECT GROUP_CONCAT(DISTINCT os.status)
                      FROM order_services os WHERE os.order_id = o.id) AS service_statuses
              FROM orders o
              LEFT JOIN users creator         ON creator.id = o.created_by
              LEFT JOIN parking_locations pl  ON pl.id      = o.parking_location_id
              $whereSQL
             ORDER BY o.created_at DESC
             LIMIT ? OFFSET ?
        ";
        $stmt = $pdo->prepare($sql);
        $i = 1;
        foreach ($bind as $b) $stmt->bindValue($i++, $b);
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

    // GET /api/orders/{id}
    public function show(array $params): void
    {
        $user = AuthMiddleware::require(['admin', 'team_leader']);
        $pdo  = Database::getInstance();

        $stmt = $pdo->prepare(
            'SELECT o.*, pl.name AS location_name, pl.terminal AS location_terminal,
                    creator.name AS created_by_name
             FROM orders o
             LEFT JOIN parking_locations pl ON pl.id = o.parking_location_id
             LEFT JOIN users creator        ON creator.id = o.created_by
             WHERE o.id = ?'
        );
        $stmt->execute([$params['id']]);
        $order = $stmt->fetch();

        if (!$order) Response::error('Order not found', 404);

        // TL gate: at least one order_service must belong to this TL
        if ($user['role'] === 'team_leader') {
            $check = $pdo->prepare(
                'SELECT 1 FROM order_services WHERE order_id = ? AND provider_id = ? LIMIT 1'
            );
            $check->execute([$params['id'], $user['id']]);
            if (!$check->fetch()) Response::error('Forbidden', 403);
        }

        $svc = $pdo->prepare(
            'SELECT os.id, os.service_id, s.name AS service_name,
                    os.daily_serial, os.serial_date, os.status, os.price,
                    os.location_details, os.image_path,
                    os.assigned_at, os.accepted_at, os.started_at, os.completed_at,
                    os.provider_id, tl.name AS team_leader_name,
                    os.employee_id, e.name AS employee_name, e.employee_code
             FROM order_services os
             JOIN services s ON s.id = os.service_id
             LEFT JOIN users     tl ON tl.id = os.provider_id
             LEFT JOIN employees e  ON e.id  = os.employee_id
             WHERE os.order_id = ?
             ORDER BY os.created_at ASC'
        );
        $svc->execute([$params['id']]);
        $order['services'] = $svc->fetchAll();

        $pay = $pdo->prepare(
            'SELECT id, payment_method, amount, transaction_ref, status, created_at
             FROM payments WHERE order_id = ? ORDER BY created_at DESC'
        );
        $pay->execute([$params['id']]);
        $order['payments'] = $pay->fetchAll();

        Response::success($order);
    }

    // GET /api/orders/summary
    public function summary(array $params = []): void
    {
        AuthMiddleware::require(['admin']);
        $pdo = Database::getInstance();

        // Build the same WHERE clause as index() so summary respects filters.
        // Always-on filter: unnumbered customer carts are excluded (they
        // represent pre-payment intent, not real orders).
        $where = ['o.order_number IS NOT NULL'];
        $bind  = [];

        if (!empty($_GET['from'])) { $where[] = 'DATE(o.created_at) >= ?'; $bind[] = $_GET['from']; }
        if (!empty($_GET['to']))   { $where[] = 'DATE(o.created_at) <= ?'; $bind[] = $_GET['to']; }
        if (!empty($_GET['date'])) { $where[] = 'DATE(o.created_at) = ?'; $bind[] = $_GET['date']; }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total_orders,
                COALESCE(SUM(o.total_amount),0)                   AS gross_amount,
                COALESCE(SUM(o.paid_amount),0)                    AS amount_paid,
                COALESCE(SUM(o.total_amount - o.paid_amount),0)   AS amount_unpaid,
                SUM(CASE WHEN o.payment_status='paid'    THEN 1 ELSE 0 END) AS paid_orders,
                SUM(CASE WHEN o.payment_status='unpaid'  THEN 1 ELSE 0 END) AS unpaid_orders,
                SUM(CASE WHEN o.payment_status='partial' THEN 1 ELSE 0 END) AS partial_orders
            FROM orders o
            $whereSQL
        ");
        $stmt->execute($bind);
        Response::success(array_merge(['currency' => APP_CURRENCY], $stmt->fetch()));
    }

    // PATCH /api/orders/{id}/cancel
    public function cancel(array $params): void
    {
        AuthMiddleware::require(['admin']);
        $pdo = Database::getInstance();

        Database::transaction(function (\PDO $pdo) use ($params) {
            // Cancel all non-completed services
            $pdo->prepare(
                'UPDATE order_services
                    SET status = "cancelled",
                        cancelled_at = UTC_TIMESTAMP(),
                        version = version + 1,
                        updated_at = UTC_TIMESTAMP()
                  WHERE order_id = ?
                    AND status NOT IN ("completed","cancelled")'
            )->execute([$params['id']]);
        });

        Response::success(null, 'Order cancelled');
    }
}
<?php
// backend/controllers/OrderController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\{Response, Request};
use App\Middleware\AuthMiddleware;
use App\Helpers\AssignmentEngine;

class OrderController
{
    // GET /api/orders
    public function index(): void
    {
        $user = AuthMiddleware::require(['admin', 'agent']);
        $pdo  = Database::getInstance();

        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where  = [];
        $params = [];

        if ($user['role'] === 'agent') {
            $where[]  = 'o.created_by = ?';
            $params[] = $user['id'];
        }
        if (!empty($_GET['payment_status'])) {
            $where[]  = 'o.payment_status = ?';
            $params[] = $_GET['payment_status'];
        }
        if (!empty($_GET['date'])) {
            $where[]  = 'DATE(o.created_at) = ?';
            $params[] = $_GET['date'];
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare("
            SELECT o.*,
                   u.name AS agent_name,
                   COUNT(os.id) AS service_count
            FROM orders o
            LEFT JOIN users u ON u.id = o.created_by
            LEFT JOIN order_services os ON os.order_id = o.id
            {$whereSQL}
            GROUP BY o.id
            ORDER BY o.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $orders = $stmt->fetchAll();

        Response::json(['success' => true, 'data' => $orders, 'page' => $page]);
    }

    // GET /api/orders/{id}
    public function show(array $params): void
    {
        $user = AuthMiddleware::require(['admin', 'agent', 'provider', 'customer']);
        $pdo  = Database::getInstance();

        $stmt = $pdo->prepare('
            SELECT o.*,
                   u.name AS agent_name,
                   (SELECT JSON_ARRAYAGG(
                       JSON_OBJECT(
                           "id", os.id,
                           "service_id", os.service_id,
                           "service_name", s.name,
                           "provider_id", os.provider_id,
                           "provider_name", p.name,
                           "status", os.status,
                           "price", os.price,
                           "location_details", os.location_details,
                           "version", os.version,
                           "started_at", os.started_at,
                           "completed_at", os.completed_at
                       )
                   ) FROM order_services os
                   JOIN services s ON s.id = os.service_id
                   LEFT JOIN users p ON p.id = os.provider_id
                   WHERE os.order_id = o.id) AS services,
                   (SELECT JSON_ARRAYAGG(
                       JSON_OBJECT(
                           "id", py.id,
                           "method", py.payment_method,
                           "amount", py.amount,
                           "status", py.status,
                           "created_at", py.created_at
                       )
                   ) FROM payments py WHERE py.order_id = o.id AND py.status = "success") AS payments
            FROM orders o
            LEFT JOIN users u ON u.id = o.created_by
            WHERE o.id = ?
        ');
        $stmt->execute([$params['id']]);
        $order = $stmt->fetch();

        if (!$order) {
            Response::error('Order not found', 404);
        }

        // Decode JSON fields
        $order['services'] = json_decode($order['services'] ?? '[]', true);
        $order['payments'] = json_decode($order['payments'] ?? '[]', true);

        Response::success($order);
    }

    // PATCH /api/orders/{id}/services/{svcId}/assign
    public function manualAssign(array $params): void
    {
        $user = AuthMiddleware::require(['admin', 'agent']);
        $req  = new Request();
        $providerId = $req->input('provider_id');

        if (!$providerId) {
            Response::error('provider_id required', 422);
        }

        $engine = new AssignmentEngine();
        $result = $engine->manualAssign($params['svcId'], $providerId);

        $result
            ? Response::success(null, 'Assigned')
            : Response::error('Assignment failed', 422);
    }
}

// ============================================================
// backend/controllers/PaymentController.php
// ============================================================

class PaymentController
{
    // GET /api/orders/{id}/payments
    public function index(array $params): void
    {
        AuthMiddleware::require(['admin', 'agent']);
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'SELECT p.*, u.name AS recorded_by_name
             FROM payments p
             LEFT JOIN users u ON u.id = p.recorded_by
             WHERE p.order_id = ?
             ORDER BY p.created_at ASC'
        );
        $stmt->execute([$params['id']]);
        Response::success($stmt->fetchAll());
    }

    // POST /api/orders/{id}/payments
    public function store(array $params): void
    {
        $user = AuthMiddleware::require(['admin', 'agent']);
        $req  = new Request();

        $uuidRef = $req->input('uuid_ref') ?? $this->generateUUID();
        $amount  = (float)$req->input('amount', 0);
        $method  = $req->input('payment_method', 'cash');

        if ($amount <= 0) {
            Response::error('Amount must be positive', 422);
        }
        if (!in_array($method, ['cash', 'card', 'online'], true)) {
            Response::error('Invalid payment method', 422);
        }

        Database::transaction(function ($pdo) use ($params, $req, $user, $uuidRef, $amount, $method) {
            // Dedup check
            $dup = $pdo->prepare('SELECT id FROM payments WHERE uuid_ref = ?');
            $dup->execute([$uuidRef]);
            if ($dup->fetch()) {
                Response::success(['note' => 'already_recorded'], 'Payment already recorded');
            }

            // Verify order exists
            $order = $pdo->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE');
            $order->execute([$params['id']]);
            if (!$order->fetch()) {
                Response::error('Order not found', 404);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO payments
                 (id, order_id, payment_method, amount, transaction_ref, uuid_ref, status, recorded_by, created_at)
                 VALUES (UUID(), ?, ?, ?, ?, ?, "success", ?, UTC_TIMESTAMP())'
            );
            $stmt->execute([
                $params['id'],
                $method,
                round($amount, 2),
                $req->input('transaction_ref'),
                $uuidRef,
                $user['id'],
            ]);

            Response::success(['uuid_ref' => $uuidRef], 'Payment recorded', 201);
        });
    }

    private function generateUUID(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

// ============================================================
// backend/controllers/ReportController.php
// ============================================================

class ReportController
{
    // GET /api/reports/daily?date=YYYY-MM-DD
    public function daily(): void
    {
        AuthMiddleware::require(['admin', 'agent']);
        $pdo  = Database::getInstance();
        $date = $_GET['date'] ?? date('Y-m-d');

        $stmt = $pdo->prepare('
            SELECT
                COUNT(DISTINCT o.id)          AS order_count,
                SUM(p.amount)                 AS total_revenue,
                SUM(CASE WHEN p.payment_method = "cash"   THEN p.amount ELSE 0 END) AS cash,
                SUM(CASE WHEN p.payment_method = "card"   THEN p.amount ELSE 0 END) AS card,
                SUM(CASE WHEN p.payment_method = "online" THEN p.amount ELSE 0 END) AS online,
                COUNT(DISTINCT CASE WHEN o.payment_status = "unpaid" THEN o.id END) AS unpaid_orders
            FROM orders o
            LEFT JOIN payments p ON p.order_id = o.id AND p.status = "success"
            WHERE DATE(o.created_at) = ?
        ');
        $stmt->execute([$date]);
        $summary = $stmt->fetch();

        // Service breakdown
        $stmt2 = $pdo->prepare('
            SELECT s.name, COUNT(os.id) AS count, SUM(os.price) AS revenue
            FROM order_services os
            JOIN services s ON s.id = os.service_id
            JOIN orders o ON o.id = os.order_id
            WHERE DATE(o.created_at) = ?
            GROUP BY s.id, s.name
            ORDER BY revenue DESC
        ');
        $stmt2->execute([$date]);
        $services = $stmt2->fetchAll();

        Response::success(['summary' => $summary, 'services' => $services, 'date' => $date]);
    }

    // GET /api/reports/agent/{id}?from=&to=
    public function agentReport(array $params): void
    {
        $user = AuthMiddleware::require(['admin', 'agent']);
        $pdo  = Database::getInstance();

        $agentId = $params['id'];
        if ($user['role'] === 'agent' && $user['id'] !== $agentId) {
            Response::error('Forbidden', 403);
        }

        $from = $_GET['from'] ?? date('Y-m-01');
        $to   = $_GET['to']   ?? date('Y-m-d');

        $stmt = $pdo->prepare('
            SELECT
                COUNT(DISTINCT o.id)  AS order_count,
                SUM(p.amount)         AS total_sales,
                SUM(CASE WHEN p.payment_method = "cash"   THEN p.amount ELSE 0 END) AS cash,
                SUM(CASE WHEN p.payment_method = "card"   THEN p.amount ELSE 0 END) AS card,
                SUM(CASE WHEN p.payment_method = "online" THEN p.amount ELSE 0 END) AS online
            FROM orders o
            LEFT JOIN payments p ON p.order_id = o.id AND p.status = "success"
            WHERE o.created_by = ?
              AND DATE(o.created_at) BETWEEN ? AND ?
        ');
        $stmt->execute([$agentId, $from, $to]);
        Response::success($stmt->fetch());
    }
}

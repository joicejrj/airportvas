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
    // GET /api/orders?status=in_progress&payment_status=paid&date=2026-05-12&q=KL07&from=2026-05-01&to=2026-05-12&source=agent
	public function index(): void
	{
		$user = AuthMiddleware::require(['admin', 'agent']);
		
		// Opportunistic timeout sweep
    	\App\Controllers\ProviderController::maybeProcessTimeouts();
		
		$pdo  = Database::getInstance();

		$page   = max(1, (int)($_GET['page'] ?? 1));
		$limit  = min(100, max(10, (int)($_GET['limit'] ?? 30)));
		$offset = ($page - 1) * $limit;

		// Derived status subquery — define BEFORE we reference it in filters
		$derivedStatusExpr = "(
			SELECT CASE
				WHEN COUNT(*) = 0 THEN 'pending'
				WHEN SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) = COUNT(*) THEN 'completed'
				WHEN SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) = COUNT(*) THEN 'cancelled'
				WHEN SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) > 0 THEN 'in_progress'
				WHEN SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) > 0 THEN 'accepted'
				WHEN SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) > 0 THEN 'assigned'
				ELSE 'pending'
			END
			FROM order_services WHERE order_id = o.id
		)";

		$where  = [];
		$params = [];

		// Agents only see their own orders
		if ($user['role'] === 'agent') {
			$where[]  = 'o.created_by = ?';
			$params[] = $user['id'];
		}

		// Payment status filter
		if (!empty($_GET['payment_status'])) {
			$valid = ['unpaid', 'partial', 'paid'];
			if (in_array($_GET['payment_status'], $valid, true)) {
				$where[]  = 'o.payment_status = ?';
				$params[] = $_GET['payment_status'];
			}
		}

		// Date single
		if (!empty($_GET['date'])) {
			$where[]  = 'DATE(o.created_at) = ?';
			$params[] = $_GET['date'];
		}

		// Date range
		if (!empty($_GET['from'])) {
			$where[]  = 'DATE(o.created_at) >= ?';
			$params[] = $_GET['from'];
		}
		if (!empty($_GET['to'])) {
			$where[]  = 'DATE(o.created_at) <= ?';
			$params[] = $_GET['to'];
		}

		// Source
		if (!empty($_GET['source'])) {
			$valid = ['agent', 'customer_qr', 'customer_web'];
			if (in_array($_GET['source'], $valid, true)) {
				$where[]  = 'o.source = ?';
				$params[] = $_GET['source'];
			}
		}
		
		// Created by (agent filter)
		if (!empty($_GET['created_by']) && $user['role'] === 'admin') {
			$where[]  = 'o.created_by = ?';
			$params[] = $_GET['created_by'];
		}
		if (!empty($_GET['no_agent'])) {
			$where[] = 'o.created_by IS NULL';
		}

		// Search: plate / customer name / phone
		if (!empty($_GET['q'])) {
			$where[]  = '(o.vehicle_plate LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)';
			$q = '%' . $_GET['q'] . '%';
			$params[] = $q;
			$params[] = $q;
			$params[] = $q;
		}

		// Order status filter (derived from order_services)
		if (!empty($_GET['status'])) {
			if ($_GET['status'] === 'active') {
				// "Active" = anything except completed & cancelled
				$where[] = "{$derivedStatusExpr} NOT IN ('completed','cancelled')";
			} else {
				$valid = ['pending', 'assigned', 'accepted', 'in_progress', 'completed', 'cancelled'];
				if (in_array($_GET['status'], $valid, true)) {
					$where[]  = "{$derivedStatusExpr} = ?";
					$params[] = $_GET['status'];
				}
			}
		}

		$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

		$stmt = $pdo->prepare("
		SELECT
			o.id,
			o.order_number,
			o.vehicle_plate,
			o.customer_name,
			o.customer_phone,
			o.customer_email,
			o.total_amount,
			o.paid_amount,
			o.payment_status,
			o.was_paid,
			o.notes,
			o.source,
			o.created_at,
			(SELECT name FROM users WHERE id = o.created_by) AS agent_name,
			(SELECT COUNT(*) FROM order_services WHERE order_id = o.id) AS service_count,
			{$derivedStatusExpr} AS order_status,
			(
				SELECT GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ')
				FROM order_services os
				JOIN users u ON u.id = os.provider_id
				WHERE os.order_id = o.id
				  AND os.provider_id IS NOT NULL
			) AS provider_names,
			(
				SELECT image_path FROM order_services
				WHERE order_id = o.id AND image_path IS NOT NULL AND image_path != ''
				ORDER BY created_at ASC
				LIMIT 1
			) AS image_path
		FROM orders o
		{$whereSQL}
		ORDER BY o.created_at DESC
		LIMIT {$limit} OFFSET {$offset}
	");
		$stmt->execute($params);
		
		// Total count (use same WHERE as the data query)
		$countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o {$whereSQL}");
		$countStmt->execute($params);
		$total = (int)$countStmt->fetchColumn();

		Response::json([
			'success'  => true,
			'data'     => $stmt->fetchAll(),
			'page'     => $page,
			'limit'    => $limit,
			'total'    => $total,
			'pages'    => (int)ceil($total / $limit),
			'has_more' => ($offset + $limit) < $total,
		]);
	}
	
	// GET /api/orders/summary — top-of-page stat cards
	public function summary(): void
	{
		$user = AuthMiddleware::require(['admin', 'agent']);
		$pdo  = Database::getInstance();

		$where  = [];
		$params = [];

		if ($user['role'] === 'agent') {
			$where[]  = 'o.created_by = ?';
			$params[] = $user['id'];
		}
		if (!empty($_GET['created_by']) && $user['role'] === 'admin') {
			$where[]  = 'o.created_by = ?';
			$params[] = $_GET['created_by'];
		}
		if (!empty($_GET['no_agent'])) {
			$where[] = 'o.created_by IS NULL';
		}
		if (!empty($_GET['payment_status']) && in_array($_GET['payment_status'], ['unpaid','partial','paid'], true)) {
			$where[]  = 'o.payment_status = ?';
			$params[] = $_GET['payment_status'];
		}
		if (!empty($_GET['source']) && in_array($_GET['source'], ['agent','customer_qr','customer_web'], true)) {
			$where[]  = 'o.source = ?';
			$params[] = $_GET['source'];
		}
		if (!empty($_GET['date'])) {
			$where[]  = 'DATE(o.created_at) = ?';
			$params[] = $_GET['date'];
		}
		if (!empty($_GET['from'])) {
			$where[]  = 'DATE(o.created_at) >= ?';
			$params[] = $_GET['from'];
		}
		if (!empty($_GET['to'])) {
			$where[]  = 'DATE(o.created_at) <= ?';
			$params[] = $_GET['to'];
		}
		if (!empty($_GET['q'])) {
			$where[]  = '(o.vehicle_plate LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)';
			$q = '%' . $_GET['q'] . '%';
			array_push($params, $q, $q, $q);
		}

		$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

		// ── Order counts + money ─────────────────────────────────
		$stmt = $pdo->prepare("
			SELECT
				COUNT(*)                                            AS total_orders,
				COALESCE(SUM(o.total_amount), 0)                    AS gross_amount,
				COALESCE(SUM(o.paid_amount), 0)                     AS amount_paid,
				COALESCE(SUM(o.total_amount - o.paid_amount), 0)    AS amount_unpaid,
				SUM(CASE WHEN o.payment_status = 'paid'    THEN 1 ELSE 0 END) AS paid_orders,
				SUM(CASE WHEN o.payment_status = 'unpaid'  THEN 1 ELSE 0 END) AS unpaid_orders,
				SUM(CASE WHEN o.payment_status = 'partial' THEN 1 ELSE 0 END) AS partial_orders
			FROM orders o
			{$whereSQL}
		");
		$stmt->execute($params);
		$money = $stmt->fetch();

		// ── Order status counts (derived) ─────────────────────────
		$statusStmt = $pdo->prepare("
			SELECT
				SUM(CASE WHEN sub.derived = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
				SUM(CASE WHEN sub.derived = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders,
				SUM(CASE WHEN sub.derived IN ('pending','assigned','accepted','in_progress') THEN 1 ELSE 0 END) AS active_orders
			FROM (
				SELECT
					o.id,
					(
						SELECT CASE
							WHEN COUNT(*) = 0 THEN 'pending'
							WHEN SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) = COUNT(*) THEN 'completed'
							WHEN SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) = COUNT(*) THEN 'cancelled'
							WHEN SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) > 0 THEN 'in_progress'
							WHEN SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) > 0 THEN 'accepted'
							WHEN SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) > 0 THEN 'assigned'
							ELSE 'pending'
						END
						FROM order_services WHERE order_id = o.id
					) AS derived
				FROM orders o
				{$whereSQL}
			) AS sub
		");
		$statusStmt->execute($params);
		$statuses = $statusStmt->fetch();

		// ── Service type counts ───────────────────────────────────
		$svcStmt = $pdo->prepare("
			SELECT
				SUM(CASE WHEN s.name = 'Porter' THEN 1 ELSE 0 END) AS porter_count,
				SUM(CASE WHEN s.name = 'Porter' THEN 0 ELSE 1 END) AS carwash_count
			FROM order_services os
			JOIN services s ON s.id = os.service_id
			JOIN orders   o ON o.id = os.order_id
			{$whereSQL}
		");
		$svcStmt->execute($params);
		$services = $svcStmt->fetch();

		Response::success([
			'total_orders'      => (int)$money['total_orders'],
			'paid_orders'       => (int)$money['paid_orders'],
			'unpaid_orders'     => (int)$money['unpaid_orders'],
			'partial_orders'    => (int)$money['partial_orders'],
			'active_orders'     => (int)$statuses['active_orders'],
			'completed_orders'  => (int)$statuses['completed_orders'],
			'cancelled_orders'  => (int)$statuses['cancelled_orders'],
			'gross_amount'      => (float)$money['gross_amount'],
			'amount_paid'       => (float)$money['amount_paid'],
			'amount_unpaid'     => (float)$money['amount_unpaid'],
			'carwash_count'     => (int)$services['carwash_count'],
			'porter_count'      => (int)$services['porter_count'],
		]);
	}

    // GET /api/orders/{id}
    public function show(array $params): void
	{
		$user = AuthMiddleware::require(['admin', 'agent']);
		$pdo  = Database::getInstance();

		$stmt = $pdo->prepare("
			SELECT
				o.*,
				(SELECT name FROM users WHERE id = o.created_by) AS agent_name,
				(
					SELECT CASE
						WHEN COUNT(*) = 0 THEN 'pending'
						WHEN SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) = COUNT(*) THEN 'completed'
						WHEN SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) = COUNT(*) THEN 'cancelled'
						WHEN SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) > 0 THEN 'in_progress'
						WHEN SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) > 0 THEN 'accepted'
						WHEN SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) > 0 THEN 'assigned'
						ELSE 'pending'
					END
					FROM order_services WHERE order_id = o.id
				) AS order_status
			FROM orders o
			WHERE o.id = ?
		");
		$stmt->execute([$params['id']]);
		$order = $stmt->fetch();

		if (!$order) {
			Response::error('Order not found', 404);
		}

		// Agents see only their own orders
		if ($user['role'] === 'agent' && $order['created_by'] !== $user['id']) {
			Response::error('Forbidden', 403);
		}

		// Attach services with provider name
		$svc = $pdo->prepare(
			'SELECT os.id, os.service_id, s.name AS service_name, os.status,
					os.price, os.location_details, os.image_path,
					os.assigned_at, os.started_at, os.completed_at,
					os.provider_id,
					(SELECT name FROM users WHERE id = os.provider_id) AS provider_name
			 FROM order_services os
			 JOIN services s ON s.id = os.service_id
			 WHERE os.order_id = ?
			 ORDER BY os.created_at ASC'
		);
		$svc->execute([$params['id']]);
		$order['services'] = $svc->fetchAll();

		// Payments
		$pay = $pdo->prepare(
			'SELECT id, payment_method, amount, transaction_ref, status, created_at
			 FROM payments WHERE order_id = ? ORDER BY created_at DESC'
		);
		$pay->execute([$params['id']]);
		$order['payments'] = $pay->fetchAll();

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

		// Pre-check the order's payment state for a clear error message
		$pdo = Database::getInstance();
		$check = $pdo->prepare(
			'SELECT o.payment_status
			 FROM orders o
			 JOIN order_services os ON os.order_id = o.id
			 WHERE os.id = ?'
		);
		$check->execute([$params['svcId']]);
		$payStatus = $check->fetchColumn();

		if ($payStatus === false) {
			Response::error('Service not found', 404);
		}
		if ($payStatus !== 'paid') {
			Response::error('Cannot assign — order is ' . $payStatus . ' (must be paid)', 422);
		}

		$engine = new AssignmentEngine();
		$result = $engine->manualAssign($params['svcId'], $providerId);

		$result
			? Response::success(null, 'Assigned')
			: Response::error('Assignment failed (no eligible provider, or provider not configured for this service)', 422);
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

		$pdo = Database::getInstance();

		// Dedup check (outside transaction)
		$dup = $pdo->prepare('SELECT id FROM payments WHERE uuid_ref = ?');
		$dup->execute([$uuidRef]);
		if ($dup->fetch()) {
			Response::success(['note' => 'already_recorded'], 'Payment already recorded');
		}

		$orderFound          = false;
		$transitionedToPaid  = false;
		$orderId             = $params['id'];

		Database::transaction(function ($pdo) use ($orderId, $req, $user, $uuidRef, $amount, $method, &$orderFound, &$transitionedToPaid) {
			// Lock the order + capture state BEFORE the insert
			$order = $pdo->prepare('SELECT id, payment_status FROM orders WHERE id = ? FOR UPDATE');
			$order->execute([$orderId]);
			$before = $order->fetch();
			if (!$before) return;
			$orderFound   = true;
			$beforeStatus = $before['payment_status'];

			// Insert payment (triggers will recompute paid_amount + payment_status)
			$pdo->prepare(
				'INSERT INTO payments
				 (id, order_id, payment_method, amount, transaction_ref, uuid_ref, status, recorded_by, created_at)
				 VALUES (UUID(), ?, ?, ?, ?, ?, "success", ?, UTC_TIMESTAMP())'
			)->execute([
				$orderId,
				$method,
				round($amount, 2),
				$req->input('transaction_ref'),
				$uuidRef,
				$user['id'],
			]);

			// Re-read AFTER triggers
			$order->execute([$orderId]);
			$afterStatus = $order->fetch()['payment_status'];

			if ($beforeStatus !== 'paid' && $afterStatus === 'paid') {
				$pdo->prepare('UPDATE orders SET was_paid = 1 WHERE id = ?')->execute([$orderId]);
				$transitionedToPaid = true;
			}
		});

		if (!$orderFound) {
			Response::error('Order not found', 404);
		}

		// Fire assignment OUTSIDE the transaction so the engine sees committed state
		if ($transitionedToPaid) {
			try {
				(new \App\Helpers\AssignmentEngine())->assignAllPendingForOrder($orderId);
			} catch (\Throwable $e) {
				error_log('Auto-assign after payment failed: ' . $e->getMessage());
			}
		}

		Response::success(['uuid_ref' => $uuidRef], 'Payment recorded', 201);
	}
	
	// POST /api/orders/{id}/payments/{paymentId}/reverse
	public function reverse(array $params): void
	{
		$user = AuthMiddleware::require(['admin']);

		$orderId   = $params['id'];
		$paymentId = $params['paymentId'];

		$pdo = Database::getInstance();
		$transitionedFromPaid = false;

		Database::transaction(function ($pdo) use ($orderId, $paymentId, &$transitionedFromPaid) {
			// Lock order
			$order = $pdo->prepare('SELECT id, payment_status FROM orders WHERE id = ? FOR UPDATE');
			$order->execute([$orderId]);
			$before = $order->fetch();
			if (!$before) Response::error('Order not found', 404);
			$beforeStatus = $before['payment_status'];

			// Lock payment
			$pay = $pdo->prepare(
				'SELECT id, status FROM payments WHERE id = ? AND order_id = ? FOR UPDATE'
			);
			$pay->execute([$paymentId, $orderId]);
			$payRow = $pay->fetch();
			if (!$payRow)                          Response::error('Payment not found', 404);
			if ($payRow['status'] !== 'success')   Response::error('Only successful payments can be reversed', 422);

			// Mark payment failed — triggers recompute order paid_amount + status
			$pdo->prepare(
				'UPDATE payments SET status = "failed" WHERE id = ?'
			)->execute([$paymentId]);

			// Re-read
			$order->execute([$orderId]);
			$afterStatus = $order->fetch()['payment_status'];

			if ($beforeStatus === 'paid' && $afterStatus !== 'paid') {
				$transitionedFromPaid = true;
			}
		});

		// Cancel unfinished services OUTSIDE the transaction (it manages its own locks + notifications)
		$cancelled = 0;
		if ($transitionedFromPaid) {
			try {
				$cancelled = (new \App\Helpers\AssignmentEngine())
					->cancelUnfinishedForOrder($orderId, 'Payment reversed');
			} catch (\Throwable $e) {
				error_log('Cancel-on-reverse failed: ' . $e->getMessage());
			}
		}

		Response::success(
			['cancelled_services' => $cancelled, 'reverted_from_paid' => $transitionedFromPaid],
			'Payment reversed'
		);
	}
	
	// GET /api/payments  — admin list with filters, range, pagination
	public function adminList(): void
	{
		AuthMiddleware::require(['admin', 'agent']);
		$pdo = Database::getInstance();

		$page    = max(1, (int)($_GET['page'] ?? 1));
		$perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
		$offset  = ($page - 1) * $perPage;

		$dateFrom = $_GET['date_from'] ?? date('Y-m-d');
		$dateTo   = $_GET['date_to']   ?? $dateFrom;
		$method   = $_GET['method']    ?? '';
		$status   = $_GET['status']    ?? '';
		$search   = trim((string)($_GET['q'] ?? ''));

		// Validate dates (YYYY-MM-DD)
		foreach (['dateFrom' => $dateFrom, 'dateTo' => $dateTo] as $k => $v) {
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
				Response::error("Invalid {$k}", 422);
			}
		}
		if ($dateFrom > $dateTo) {
			[$dateFrom, $dateTo] = [$dateTo, $dateFrom];
		}

		$where  = ['DATE(p.created_at) BETWEEN ? AND ?'];
		$params = [$dateFrom, $dateTo];

		if ($method !== '' && in_array($method, ['cash','card','online'], true)) {
			$where[]  = 'p.payment_method = ?';
			$params[] = $method;
		}
		if ($status !== '' && in_array($status, ['pending','success','failed'], true)) {
			$where[]  = 'p.status = ?';
			$params[] = $status;
		}
		if ($search !== '') {
			$where[]  = '(o.vehicle_plate LIKE ? OR p.transaction_ref LIKE ? OR o.order_number LIKE ?)';
			$like     = '%' . $search . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$whereSQL = 'WHERE ' . implode(' AND ', $where);

		// Total count
		$countStmt = $pdo->prepare("
			SELECT COUNT(*) FROM payments p
			LEFT JOIN orders o ON o.id = p.order_id
			{$whereSQL}
		");
		$countStmt->execute($params);
		$total = (int)$countStmt->fetchColumn();

		// Page rows
		$listStmt = $pdo->prepare("
			SELECT p.id, p.order_id, p.payment_method, p.amount,
				   p.transaction_ref, p.status, p.created_at,
				   o.vehicle_plate, o.total_amount, o.paid_amount, o.order_number,
				   u.name AS recorded_by_name
			FROM payments p
			LEFT JOIN orders o ON o.id = p.order_id
			LEFT JOIN users  u ON u.id = p.recorded_by
			{$whereSQL}
			ORDER BY p.created_at DESC
			LIMIT {$perPage} OFFSET {$offset}
		");
		$listStmt->execute($params);
		$items = $listStmt->fetchAll();

		// Totals for the same filter (used for the 3 stat cards)
		$sumStmt = $pdo->prepare("
			SELECT
				COALESCE(SUM(CASE WHEN p.payment_method='cash'   AND p.status='success' THEN p.amount END),0) AS cash,
				COALESCE(SUM(CASE WHEN p.payment_method='card'   AND p.status='success' THEN p.amount END),0) AS card,
				COALESCE(SUM(CASE WHEN p.payment_method='online' AND p.status='success' THEN p.amount END),0) AS online
			FROM payments p
			LEFT JOIN orders o ON o.id = p.order_id
			{$whereSQL}
		");
		$sumStmt->execute($params);
		$totals = $sumStmt->fetch();

		Response::json([
			'success' => true,
			'data'    => $items,
			'meta'    => [
				'page'        => $page,
				'per_page'    => $perPage,
				'total'       => $total,
				'total_pages' => (int)ceil($total / $perPage),
				'date_from'   => $dateFrom,
				'date_to'     => $dateTo,
				'totals'      => $totals,
			],
		]);
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

		// ── Headline summary for the requested date ──
		$stmt = $pdo->prepare('
			SELECT
				COUNT(DISTINCT o.id)          AS order_count,
				COALESCE(SUM(p.amount), 0)    AS total_revenue,
				COALESCE(SUM(CASE WHEN p.payment_method = "cash"   THEN p.amount END), 0) AS cash,
				COALESCE(SUM(CASE WHEN p.payment_method = "card"   THEN p.amount END), 0) AS card,
				COALESCE(SUM(CASE WHEN p.payment_method = "online" THEN p.amount END), 0) AS online,
				COUNT(DISTINCT CASE WHEN o.payment_status IN ("unpaid","partial") THEN o.id END) AS unpaid_orders
			FROM orders o
			LEFT JOIN payments p ON p.order_id = o.id AND p.status = "success"
			WHERE DATE(o.created_at) = ?
		');
		$stmt->execute([$date]);
		$summary = $stmt->fetch();

		// ── Yesterday's order count (for the "vs yesterday" delta) ──
		$yest = $pdo->prepare('
			SELECT COUNT(*) AS c FROM orders WHERE DATE(created_at) = DATE_SUB(?, INTERVAL 1 DAY)
		');
		$yest->execute([$date]);
		$summary['yesterday_order_count'] = (int)$yest->fetchColumn();

		// ── Active jobs across all providers (status snapshot — not date-bound) ──
		$active = $pdo->query('
			SELECT COUNT(*) FROM order_services
			WHERE status IN ("assigned","accepted","in_progress")
		');
		$summary['active_jobs'] = (int)$active->fetchColumn();

		// ── Service breakdown for the date ──
		$stmt2 = $pdo->prepare('
			SELECT s.name, COUNT(os.id) AS count, COALESCE(SUM(os.price), 0) AS revenue
			FROM order_services os
			JOIN services s ON s.id = os.service_id
			JOIN orders   o ON o.id = os.order_id
			WHERE DATE(o.created_at) = ?
			GROUP BY s.id, s.name
			ORDER BY revenue DESC
		');
		$stmt2->execute([$date]);
		$services = $stmt2->fetchAll();

		// ── 7-day trend ending on requested date (for the chart) ──
		$trend = $pdo->prepare('
			SELECT
				DATE(o.created_at)          AS day,
				COUNT(DISTINCT o.id)        AS orders,
				COALESCE(SUM(p.amount), 0)  AS revenue
			FROM orders o
			LEFT JOIN payments p ON p.order_id = o.id AND p.status = "success"
			WHERE DATE(o.created_at) BETWEEN DATE_SUB(?, INTERVAL 6 DAY) AND ?
			GROUP BY DATE(o.created_at)
			ORDER BY day ASC
		');
		$trend->execute([$date, $date]);
		$trendRows = $trend->fetchAll();

		// Fill missing days with zeros so the chart always has 7 bars
		$byDay = [];
		foreach ($trendRows as $r) {
			$byDay[$r['day']] = ['orders' => (int)$r['orders'], 'revenue' => (float)$r['revenue']];
		}
		$week = [];
		for ($i = 6; $i >= 0; $i--) {
			$d = date('Y-m-d', strtotime("{$date} -{$i} day"));
			$week[] = [
				'day'     => $d,
				'label'   => date('D', strtotime($d)),
				'orders'  => $byDay[$d]['orders']  ?? 0,
				'revenue' => $byDay[$d]['revenue'] ?? 0.0,
			];
		}

		Response::success([
			'summary'  => $summary,
			'services' => $services,
			'trend'    => $week,
			'date'     => $date,
		]);
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

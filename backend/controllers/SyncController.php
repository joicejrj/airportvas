<?php
// backend/controllers/SyncController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Helpers\OrderNumber;

/**
 * POST /api/sync
 *
 * Accepts a batch of offline actions from PWA clients.
 * Each action is processed inside a DB transaction.
 * Returns: { success: [...], failed: [...], conflicts: [...] }
 *
 * Action types:
 *   order:create     — create new order + services in one shot
 *   order:update     — update order fields
 *   service:status   — transition an order_service status
 *   payment:create   — record a payment (idempotent by uuid_ref)
 */
class SyncController
{
    public function handle(): void
    {
        $user = AuthMiddleware::require(['admin', 'agent', 'provider']);

        $raw     = file_get_contents('php://input');
        $payload = json_decode($raw, true);

        if (!isset($payload['actions']) || !is_array($payload['actions'])) {
            Response::error('actions array required', 422);
        }

        $actions = array_slice($payload['actions'], 0, SYNC_MAX_BATCH_SIZE);
        $results = ['success' => [], 'failed' => [], 'conflicts' => []];

        foreach ($actions as $action) {
            try {
                $this->processAction($action, $user, $results);
            } catch (\Throwable $e) {
                error_log('Sync action error: ' . $e->getMessage());
                $results['failed'][] = [
                    'action_id' => $action['id'] ?? null,
                    'reason'    => 'Internal error',
                ];
            }
        }

        Response::json([
            'success'   => true,
            'synced_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'results'   => $results,
        ]);
    }

    private function processAction(array $action, array $user, array &$results): void
    {
        $actionId   = $action['id'] ?? null;
        $entityType = $action['entity_type'] ?? '';
        $actionType = $action['action_type'] ?? '';
        $payload    = $action['payload'] ?? [];

        if (!$actionId || !$entityType || !$actionType) {
            $results['failed'][] = ['action_id' => $actionId, 'reason' => 'Missing required fields'];
            return;
        }

        Database::transaction(function ($pdo) use ($action, $user, $actionId, $entityType, $actionType, $payload, &$results) {

            // ── Log this sync attempt ──────────────────────────────────
            $logStmt = $pdo->prepare(
                'INSERT INTO sync_log (id, client_id, action_id, entity_type, entity_id, action_type, status, processed_at)
                 VALUES (UUID(), ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
            );

            $outcome    = null;
            $conflictData = null;
            $entityId   = $payload['id'] ?? null;

            // ── Dispatch ───────────────────────────────────────────────
            try {
                match ("{$entityType}:{$actionType}") {
                    'order:create'   => $this->createOrder($pdo, $payload, $user, $actionId, $results),
                    'order:update'   => $this->updateOrder($pdo, $payload, $user, $actionId, $results),
                    'service:status' => $this->changeServiceStatus($pdo, $payload, $user, $actionId, $results),
                    'payment:create' => $this->recordPayment($pdo, $payload, $user, $actionId, $results),
                    default          => $results['failed'][] = ['action_id' => $actionId, 'reason' => 'Unknown action'],
                };
                $outcome = 'success';
            } catch (ConflictException $ce) {
                $outcome      = 'conflict';
                $conflictData = $ce->getData();
                $results['conflicts'][] = [
                    'action_id'     => $actionId,
                    'reason'        => $ce->getMessage(),
                    'server_state'  => $conflictData,
                ];
            }

            $logStmt->execute([
                $user['id'],
                $actionId,
                $entityType,
                $entityId,
                $actionType,
                $outcome ?? 'failed',
            ]);
        });
    }

    // ── ORDER CREATE ───────────────────────────────────────────────────────
	private function createOrder(
		\PDO $pdo,
		array $payload,
		array $user,
		string $actionId,
		array &$results
	): void {
		// Validate required fields
		if (empty($payload['vehicle_plate']) || empty($payload['services'])) {
			$results['failed'][] = ['action_id' => $actionId, 'reason' => 'vehicle_plate and services required'];
			return;
		}

		$orderId    = $payload['id'] ?? $this->newUUID($pdo);
		$totalPrice = 0.0;

		// Validate services exist + calc total
		$serviceIds = array_column($payload['services'], 'service_id');
		if (empty($serviceIds)) {
			$results['failed'][] = ['action_id' => $actionId, 'reason' => 'No services provided'];
			return;
		}

		$inClause = implode(',', array_fill(0, count($serviceIds), '?'));
		// NOTE: services table uses `price` (matches CustomerController). 
		// If yours is `base_price`, change both occurrences below.
		$stmt = $pdo->prepare("SELECT id, price FROM services WHERE id IN ({$inClause}) AND is_active = 1");
		$stmt->execute($serviceIds);
		$serviceMap = [];
		while ($row = $stmt->fetch()) {
			$serviceMap[$row['id']] = (float)$row['price'];
		}

		foreach ($payload['services'] as $svc) {
			if (!isset($serviceMap[$svc['service_id']])) {
				$results['failed'][] = ['action_id' => $actionId, 'reason' => "Invalid service: {$svc['service_id']}"];
				return;
			}
			$totalPrice += $svc['price'] ?? $serviceMap[$svc['service_id']];
		}

		// Reserve the next sequential order number (locks counters row)
		$orderNumber = OrderNumber::next($pdo);

		// Insert order
		$stmt = $pdo->prepare(
			'INSERT INTO orders
			 (id, order_number, customer_id, customer_name, customer_phone, customer_email,
			  vehicle_plate, vehicle_make, vehicle_model, notes,
			  total_amount, paid_amount, payment_status, created_by, source, created_at, updated_at)
			 VALUES (?,?,?,?,?,?,?,?,?,?,?,0,"unpaid",?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
		);
		$stmt->execute([
			$orderId,
			$orderNumber,
			$payload['customer_id']    ?? null,
			$payload['customer_name']  ?? null,
			$payload['customer_phone'] ?? null,
			$payload['customer_email'] ?? null,
			$payload['vehicle_plate'],
			$payload['vehicle_make']   ?? null,
			$payload['vehicle_model']  ?? null,
			$payload['notes']          ?? null,
			round($totalPrice, 2),
			$user['id'],
			$payload['source'] ?? 'agent',
		]);

		// Insert order_services
		$svcStmt = $pdo->prepare(
			'INSERT INTO order_services
			 (id, order_id, service_id, status, price, location_details, location_id, created_at, updated_at)
			 VALUES (?,?,?,"pending",?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
		);

		$createdServiceIds = [];
		foreach ($payload['services'] as $svc) {
			$svcId = $svc['id'] ?? $this->newUUID($pdo);
			$svcStmt->execute([
				$svcId,
				$orderId,
				$svc['service_id'],
				$svc['price'] ?? $serviceMap[$svc['service_id']],
				$svc['location_details'] ?? null,
				$svc['location_id']      ?? null,
			]);
			$createdServiceIds[] = $svcId;
		}

		$results['success'][] = [
			'action_id'    => $actionId,
			'entity_type'  => 'order',
			'server_id'    => $orderId,
			'order_number' => $orderNumber,    // ← client uses this to update its cache
			'service_ids'  => $createdServiceIds,
		];

		// Trigger auto-assignment (non-blocking — run after commit via queue or inline)
		$this->scheduleAutoAssignment($pdo, $orderId);
	}

    // ── ORDER UPDATE ───────────────────────────────────────────────────────
    private function updateOrder(
        \PDO $pdo,
        array $payload,
        array $user,
        string $actionId,
        array &$results
    ): void {
        $orderId = $payload['id'] ?? null;
        if (!$orderId) {
            $results['failed'][] = ['action_id' => $actionId, 'reason' => 'order id required'];
            return;
        }

        // Optimistic check — no version on orders, use updated_at
        $clientTs = $payload['client_updated_at'] ?? null;
        if ($clientTs) {
            $stmt = $pdo->prepare('SELECT updated_at FROM orders WHERE id = ?');
            $stmt->execute([$orderId]);
            $server = $stmt->fetchColumn();
            if ($server && strtotime($server) > strtotime($clientTs)) {
                throw new ConflictException('Order modified on server', ['updated_at' => $server]);
            }
        }

        // Only allow safe fields to update
        $allowed = ['notes', 'vehicle_make', 'vehicle_model', 'customer_phone'];
        $sets    = [];
        $vals    = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $payload)) {
                $sets[] = "{$field} = ?";
                $vals[] = $payload[$field];
            }
        }
        if (empty($sets)) {
            $results['failed'][] = ['action_id' => $actionId, 'reason' => 'No updatable fields'];
            return;
        }
        $vals[] = $orderId;
        $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        $results['success'][] = ['action_id' => $actionId, 'entity_type' => 'order', 'server_id' => $orderId];
    }

    // ── SERVICE STATUS CHANGE ──────────────────────────────────────────────
    private function changeServiceStatus(
        \PDO $pdo,
        array $payload,
        array $user,
        string $actionId,
        array &$results
    ): void {
        $svcId    = $payload['id'] ?? null;
        $newStatus = $payload['status'] ?? null;

        if (!$svcId || !$newStatus) {
            $results['failed'][] = ['action_id' => $actionId, 'reason' => 'id and status required'];
            return;
        }

        // Lock row
        $stmt = $pdo->prepare(
            'SELECT id, status, provider_id, version FROM order_services WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$svcId]);
        $current = $stmt->fetch();

        if (!$current) {
            $results['failed'][] = ['action_id' => $actionId, 'reason' => 'Service not found'];
            return;
        }

        // Version conflict detection
        $clientVersion = $payload['version'] ?? null;
        if ($clientVersion !== null && (int)$clientVersion !== (int)$current['version']) {
            throw new ConflictException('Version conflict', [
                'server_status'  => $current['status'],
                'server_version' => $current['version'],
            ]);
        }

        // Validate transition
        if (!$this->isValidTransition($current['status'], $newStatus, $user['role'])) {
            $results['failed'][] = [
                'action_id' => $actionId,
                'reason'    => "Invalid status transition {$current['status']} → {$newStatus}",
            ];
            return;
        }

        // Provider can only update their own services
        if ($user['role'] === 'provider' && $current['provider_id'] !== $user['id']) {
            $results['failed'][] = ['action_id' => $actionId, 'reason' => 'Not your job'];
            return;
        }

        $timestampField = match ($newStatus) {
            'accepted'    => 'accepted_at',
            'in_progress' => 'started_at',
            'completed'   => 'completed_at',
            'cancelled'   => 'cancelled_at',
            default       => null,
        };

        $sql = 'UPDATE order_services SET status = ?, version = version + 1, updated_at = UTC_TIMESTAMP()';
        $params = [$newStatus];
        if ($timestampField) {
            $sql .= ", {$timestampField} = UTC_TIMESTAMP()";
        }
        if ($newStatus === 'rejected') {
            $sql .= ', assign_attempts = assign_attempts + 1';
        }
        $sql .= ' WHERE id = ?';
        $params[] = $svcId;

        $pdo->prepare($sql)->execute($params);
        $results['success'][] = ['action_id' => $actionId, 'entity_type' => 'service', 'server_id' => $svcId];

        // Auto-reassign if rejected
        if ($newStatus === 'rejected') {
            $this->triggerReassignment($pdo, $svcId);
        }
    }

    // ── PAYMENT RECORD ─────────────────────────────────────────────────────
    private function recordPayment(
        \PDO $pdo,
        array $payload,
        array $user,
        string $actionId,
        array &$results
    ): void {
        $uuidRef = $payload['uuid_ref'] ?? null;
        if (!$uuidRef || empty($payload['order_id']) || empty($payload['amount'])) {
            $results['failed'][] = ['action_id' => $actionId, 'reason' => 'order_id, amount, uuid_ref required'];
            return;
        }

        // Idempotency check
        $dup = $pdo->prepare('SELECT id, status FROM payments WHERE uuid_ref = ?');
        $dup->execute([$uuidRef]);
        if ($existing = $dup->fetch()) {
            // Already recorded — return success (idempotent)
            $results['success'][] = [
                'action_id'   => $actionId,
                'entity_type' => 'payment',
                'server_id'   => $existing['id'],
                'note'        => 'already_recorded',
            ];
            return;
        }

        // Verify order exists
        $order = $pdo->prepare('SELECT id, total_amount FROM orders WHERE id = ? FOR UPDATE');
        $order->execute([$payload['order_id']]);
        if (!$order->fetch()) {
            $results['failed'][] = ['action_id' => $actionId, 'reason' => 'Order not found'];
            return;
        }

        // Generate UUID up-front so we can return it
		$paymentId = $this->newUUID($pdo);

		$stmt = $pdo->prepare(
			'INSERT INTO payments
			 (id, order_id, payment_method, amount, transaction_ref, uuid_ref, status, recorded_by, created_at)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
		);
		$stmt->execute([
			$paymentId,
			$payload['order_id'],
			$payload['payment_method'] ?? 'cash',
			round((float)$payload['amount'], 2),
			$payload['transaction_ref'] ?? null,
			$uuidRef,
			$payload['status']          ?? 'success',
			$user['id'],
		]);

		$results['success'][] = [
			'action_id'   => $actionId,
			'entity_type' => 'payment',
			'server_id'   => $paymentId,
		];
		
		// If this payment took the order to 'paid', kick off assignment
		$check = $pdo->prepare('SELECT payment_status FROM orders WHERE id = ?');
		$check->execute([$payload['order_id']]);
		if ($check->fetchColumn() === 'paid') {
			$pdo->prepare('UPDATE orders SET was_paid = 1 WHERE id = ? AND was_paid = 0')
				->execute([$payload['order_id']]);
			(new \App\Helpers\AssignmentEngine($pdo))->assignAllPendingForOrder($payload['order_id']);
		}
    }

    // ── AUTO-ASSIGNMENT SCHEDULER ──────────────────────────────────────────
    private function scheduleAutoAssignment(\PDO $pdo, string $orderId): void
    {
		// Only assign if the order is fully paid (payment may have come in the same sync batch)
		$check = $pdo->prepare('SELECT payment_status FROM orders WHERE id = ?');
		$check->execute([$orderId]);
		if ($check->fetchColumn() !== 'paid') {
			return; // Engine will pick it up when the payment lands
		}
		
        // Pull all pending services and assign immediately
        $stmt = $pdo->prepare('SELECT id FROM order_services WHERE order_id = ? AND status = "pending"');
        $stmt->execute([$orderId]);
        $engine = new \App\Helpers\AssignmentEngine($pdo);
        while ($row = $stmt->fetch()) {
            $engine->assign($row['id']);
        }
    }

    private function triggerReassignment(\PDO $pdo, string $serviceId): void
    {
        $engine = new \App\Helpers\AssignmentEngine($pdo);
        $engine->reassign($serviceId);
    }

    private function isValidTransition(string $from, string $to, string $role): bool
    {
        $transitions = [
            'pending'     => ['assigned', 'cancelled'],
            'assigned'    => ['accepted', 'rejected', 'cancelled'],
            'accepted'    => ['in_progress', 'cancelled'],
            'in_progress' => ['completed', 'cancelled'],
            'completed'   => [],
            'cancelled'   => [],
            'rejected'    => ['pending'],  // after reassignment
        ];

        // Role-specific restrictions
        if ($role === 'provider') {
            // Providers can only accept, reject, start, complete
            $providerAllowed = ['accepted', 'rejected', 'in_progress', 'completed'];
            if (!in_array($to, $providerAllowed, true)) {
                return false;
            }
        }

        return in_array($to, $transitions[$from] ?? [], true);
    }

    private function newUUID(\PDO $pdo): string
    {
        return $pdo->query('SELECT UUID()')->fetchColumn();
    }
}

// ── Custom exception for conflict handling ─────────────────────────────────
class ConflictException extends \RuntimeException
{
    public function __construct(string $message, private array $data = [])
    {
        parent::__construct($message);
    }

    public function getData(): array
    {
        return $this->data;
    }
}

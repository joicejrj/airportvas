<?php
// backend/helpers/AssignmentEngine.php

declare(strict_types=1);

namespace App\Helpers;

use App\Config\Database;

/**
 * Hybrid assignment engine.
 *
 * Auto-assign: finds least-loaded available provider, assigns,
 *              notifies, starts accept timeout.
 * Reassign:    called when a provider rejects or timeout expires.
 * Manual:      admin/agent can force a specific provider.
 */
class AssignmentEngine
{
    private \PDO $pdo;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    /**
	 * Auto-assign a pending order_service to the best available provider.
	 * Refuses if the order is not fully paid.
	 */
	public function assign(string $serviceId): bool
	{
		$svc = $this->lockService($serviceId);
		if (!$svc || $svc['status'] !== 'pending') return false;

		if (!$this->isOrderPaid($svc['order_id'])) {
			error_log("AssignmentEngine: blocked assign — order {$svc['order_id']} not paid");
			return false;
		}

		$provider = $this->findBestProvider($svc['service_id']);
		if (!$provider) {
			error_log("AssignmentEngine: No available provider for service {$serviceId}");
			return false;
		}
		return $this->doAssign($serviceId, $provider['id'], $svc);
	}

	public function reassign(string $serviceId, ?string $excludeProviderId = null): bool
	{
		$svc = $this->lockService($serviceId);
		if (!$svc) return false;

		if (!$this->isOrderPaid($svc['order_id'])) {
			error_log("AssignmentEngine: blocked reassign — order {$svc['order_id']} not paid");
			return false;
		}

		if ((int)$svc['assign_attempts'] >= ASSIGNMENT_MAX_ATTEMPTS) {
			$this->escalate($serviceId, $svc);
			return false;
		}

		$provider = $this->findBestProvider($svc['service_id'], $excludeProviderId ?? $svc['provider_id']);
		if (!$provider) {
			$this->escalate($serviceId, $svc);
			return false;
		}

		$this->pdo->prepare(
			'UPDATE order_services SET status = "pending", provider_id = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?'
		)->execute([$serviceId]);

		return $this->doAssign($serviceId, $provider['id'], $svc);
	}

	/**
	 * Called immediately after an order transitions to payment_status = 'paid'.
	 * Picks up every still-pending service in the order and assigns it.
	 * Returns number of services assigned.
	 */
	public function assignAllPendingForOrder(string $orderId): int
	{
		if (!$this->isOrderPaid($orderId)) return 0;

		$stmt = $this->pdo->prepare(
			'SELECT id FROM order_services
			 WHERE order_id = ? AND status = "pending"'
		);
		$stmt->execute([$orderId]);

		$count = 0;
		while ($row = $stmt->fetch()) {
			if ($this->assign($row['id'])) $count++;
		}
		return $count;
	}

	/**
	 * Called when an order's payment is reversed/refunded.
	 * Cancels every service not yet completed.
	 * Returns number of services cancelled.
	 */
	public function cancelUnfinishedForOrder(string $orderId, string $reason = 'Payment reversed'): int
	{
		// Find affected providers FIRST so we can decrement their stats
		$stmt = $this->pdo->prepare(
			'SELECT id, provider_id, status FROM order_services
			 WHERE order_id = ?
			   AND status IN ("pending","assigned","accepted","in_progress")
			 FOR UPDATE'
		);
		$stmt->execute([$orderId]);
		$affected = $stmt->fetchAll();

		if (!$affected) return 0;

		$upd = $this->pdo->prepare(
			'UPDATE order_services
			 SET status = "cancelled",
				 cancel_reason = ?,
				 cancelled_at = UTC_TIMESTAMP(),
				 version = version + 1,
				 updated_at = UTC_TIMESTAMP()
			 WHERE id = ?'
		);

		$statsDec = $this->pdo->prepare(
			'UPDATE provider_stats
			 SET active_jobs_count = GREATEST(0, active_jobs_count - 1)
			 WHERE provider_id = ?'
		);

		$notifier = new NotificationService($this->pdo);
		$count = 0;

		foreach ($affected as $row) {
			$upd->execute([$reason, $row['id']]);

			if ($row['provider_id'] && in_array($row['status'], ['assigned','accepted','in_progress'], true)) {
				$statsDec->execute([$row['provider_id']]);
				$notifier->notify($row['provider_id'], 'job_cancelled', [
					'title'      => 'Job cancelled',
					'body'       => "Order payment was reversed — job cancelled.",
					'service_id' => $row['id'],
					'order_id'   => $orderId,
				]);
			}
			$count++;
		}
		return $count;
	}

	/** Returns true only if the order is fully paid. */
	private function isOrderPaid(string $orderId): bool
	{
		$stmt = $this->pdo->prepare('SELECT payment_status FROM orders WHERE id = ?');
		$stmt->execute([$orderId]);
		$row = $stmt->fetch();
		return $row && $row['payment_status'] === 'paid';
	}

    /**
     * Manual override by admin/agent.
     *
     * Unlike assign(), this works regardless of current row state — admin
     * may be reassigning an already-assigned service to a different provider.
     */
	public function manualAssign(string $serviceId, string $providerId): bool
	{
		$svc = $this->lockService($serviceId);
		if (!$svc) {
			return false;
		}

		// Payment gate
		if (!$this->isOrderPaid($svc['order_id'])) {
			error_log("AssignmentEngine: blocked manualAssign — order {$svc['order_id']} not paid");
			return false;
		}

		// Verify provider exists and is active
		$stmt = $this->pdo->prepare(
			'SELECT id FROM users WHERE id = ? AND role = "provider" AND is_active = 1'
		);
		$stmt->execute([$providerId]);
		if (!$stmt->fetch()) {
			return false;
		}

		return $this->doAssign($serviceId, $providerId, $svc);
	}

    /**
     * Scan for services that have timed out waiting for acceptance.
     * Call from cron or opportunistically.
     */
    public function processTimeouts(): int
    {
        $timeoutAt = date('Y-m-d H:i:s', time() - (ASSIGNMENT_ACCEPT_TIMEOUT_MINUTES * 60));

        $stmt = $this->pdo->prepare(
            'SELECT id, provider_id FROM order_services
             WHERE status = "assigned"
               AND assigned_at IS NOT NULL
               AND assigned_at < ?
             ORDER BY assigned_at ASC
             LIMIT 50'
        );
        $stmt->execute([$timeoutAt]);
        $rows = $stmt->fetchAll();

        if (!$rows) return 0;

        $count = 0;
        foreach ($rows as $row) {
            try {
                // Even if reassign() returned false (escalated), we still acted on the row.
                // Count it as "processed".
                $this->reassign($row['id'], $row['provider_id']);
                $count++;
            } catch (\Throwable $e) {
                error_log("[engine] reassign failed for {$row['id']}: " . $e->getMessage());
            }
        }
        return $count;
    }

    // ── PRIVATE ──────────────────────────────────────────────────────────

    /**
     * Wraps a closure in a transaction if one isn't already in flight.
     * Ensures FOR UPDATE locks in lockService() actually hold.
     */
    private function wrapTransaction(callable $fn): bool
    {
        if ($this->pdo->inTransaction()) {
            // Already in a transaction (called from a controller) — just run it.
            return (bool)$fn();
        }

        $this->pdo->beginTransaction();
        try {
            $result = (bool)$fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            error_log('[engine] transaction rolled back: ' . $e->getMessage());
            return false;
        }
    }

    private function doAssign(string $serviceId, string $providerId, array $svc): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE order_services
             SET status      = "assigned",
                 provider_id = ?,
                 assigned_at = UTC_TIMESTAMP(),
                 version     = version + 1,
                 updated_at  = UTC_TIMESTAMP()
             WHERE id = ? AND status = "pending"'
        );
        $stmt->execute([$providerId, $serviceId]);

        if ($stmt->rowCount() === 0) {
            error_log("doAssign: row {$serviceId} not in pending state, skipped");
            return false;
        }

        // Send push notification to provider (best-effort)
        try {
            $notifier = new NotificationService($this->pdo);
            $notifier->notify($providerId, 'new_assignment', [
                'title'      => 'New job assigned',
                'body'       => 'You have a new service job. Tap to accept.',
                'service_id' => $serviceId,
                'order_id'   => $svc['order_id'],
            ]);
        } catch (\Throwable $e) {
            error_log('Notify failed (assignment): ' . $e->getMessage());
        }

        return true;
    }

    /**
     * Find least-loaded provider for a service.
     * Filters by provider_services capability and is_active.
     * Sort: active_jobs_count ASC, last_assigned_at ASC (round-robin tiebreaker).
     */
    private function findBestProvider(string $serviceId, ?string $excludeId = null): ?array
    {
        $sql = '
            SELECT u.id, COALESCE(ps.active_jobs_count, 0) AS active_jobs
            FROM users u
            JOIN provider_services psvc ON psvc.provider_id = u.id
            LEFT JOIN provider_stats  ps ON ps.provider_id  = u.id
            WHERE u.role = "provider"
              AND u.is_active = 1
              AND psvc.service_id = ?
        ';
        $params = [$serviceId];

        if ($excludeId) {
            $sql    .= ' AND u.id != ?';
            $params[] = $excludeId;
        }

        $sql .= ' ORDER BY active_jobs ASC,
                  COALESCE(ps.last_assigned_at, "2000-01-01") ASC
                  LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    private function lockService(string $serviceId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, order_id, service_id, status, provider_id, assign_attempts
             FROM order_services
             WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$serviceId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Reset row to pending and notify all admins.
     * Caller is responsible for already decrementing the old provider's
     * active_jobs_count (reassign does this).
     */
    private function escalate(string $serviceId, array $svc): void
    {
        error_log("ESCALATE: Service {$serviceId} (order {$svc['order_id']}) needs manual assignment");

        // Reset the row to pending with no provider. assign_attempts stays at MAX
        // so admins can filter stuck jobs (WHERE assign_attempts >= ASSIGNMENT_MAX_ATTEMPTS).
        $this->pdo->prepare(
            'UPDATE order_services
             SET status = "pending",
                 provider_id = NULL,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = ?'
        )->execute([$serviceId]);

        // Notify all active admins (best-effort)
        try {
            $notifier = new NotificationService($this->pdo);
            $stmt = $this->pdo->prepare(
                'SELECT id FROM users WHERE role = "admin" AND is_active = 1'
            );
            $stmt->execute();
            while ($admin = $stmt->fetch()) {
                $notifier->notify($admin['id'], 'assignment_escalation', [
                    'title'      => '⚠️ Job needs manual assignment',
                    'body'       => 'A job could not be auto-assigned after ' . ASSIGNMENT_MAX_ATTEMPTS . ' attempts. Please assign manually.',
                    'service_id' => $serviceId,
                    'order_id'   => $svc['order_id'],
                ]);
            }
        } catch (\Throwable $e) {
            error_log('Notify failed (escalation): ' . $e->getMessage());
        }
    }
}
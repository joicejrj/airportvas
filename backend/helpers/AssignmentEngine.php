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
     */
    public function assign(string $serviceId): bool
    {
        // Lock the service row
        $svc = $this->lockService($serviceId);
        if (!$svc || $svc['status'] !== 'pending') {
            return false;
        }

        $provider = $this->findBestProvider($svc['service_id']);
        if (!$provider) {
            error_log("AssignmentEngine: No available provider for service {$serviceId}");
            return false;
        }

        return $this->doAssign($serviceId, $provider['id'], $svc);
    }

    /**
     * Reassign after rejection or timeout.
     */
    public function reassign(string $serviceId, ?string $excludeProviderId = null): bool
    {
        $svc = $this->lockService($serviceId);
        if (!$svc) {
            return false;
        }

        // Check max attempts
        if ((int)$svc['assign_attempts'] >= ASSIGNMENT_MAX_ATTEMPTS) {
            $this->escalate($serviceId, $svc);
            return false;
        }

        $provider = $this->findBestProvider($svc['service_id'], $excludeProviderId ?? $svc['provider_id']);
        if (!$provider) {
            $this->escalate($serviceId, $svc);
            return false;
        }

        // Reset to pending first, then assign
        $this->pdo->prepare(
            'UPDATE order_services SET status = "pending", provider_id = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?'
        )->execute([$serviceId]);

        return $this->doAssign($serviceId, $provider['id'], $svc);
    }

    /**
     * Manual override by admin/agent.
     */
    public function manualAssign(string $serviceId, string $providerId): bool
    {
        $svc = $this->lockService($serviceId);
        if (!$svc) {
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
     * Call from a cron job every minute.
     */
    public function processTimeouts(): int
    {
        $timeoutAt = date('Y-m-d H:i:s', time() - (ASSIGNMENT_ACCEPT_TIMEOUT_MINUTES * 60));
        $stmt = $this->pdo->prepare(
            'SELECT id, provider_id FROM order_services
             WHERE status = "assigned"
               AND assigned_at < ?
             LIMIT 50'
        );
        $stmt->execute([$timeoutAt]);

        $count = 0;
        while ($row = $stmt->fetch()) {
            $this->reassign($row['id'], $row['provider_id']);
            $count++;
        }
        return $count;
    }

    // ── PRIVATE ──────────────────────────────────────────────────────────

    private function doAssign(string $serviceId, string $providerId, array $svc): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE order_services
             SET status = "assigned",
                 provider_id  = ?,
                 assigned_at  = UTC_TIMESTAMP(),
                 version      = version + 1,
                 updated_at   = UTC_TIMESTAMP()
             WHERE id = ? AND status IN ("pending")'
        );
        $stmt->execute([$providerId, $serviceId]);

        if ($stmt->rowCount() === 0) {
            return false;  // Race condition — another process got it
        }

        // Send push notification to provider
        $notifier = new NotificationService($this->pdo);
        $notifier->notify($providerId, 'new_assignment', [
            'title'      => 'New job assigned',
            'body'       => 'You have a new service job. Tap to accept.',
            'service_id' => $serviceId,
            'order_id'   => $svc['order_id'],
        ]);

        return true;
    }

    /**
     * Find least-loaded provider for a service type.
     * Sort: active_jobs ASC, last_assigned ASC (round-robin tiebreak).
     */
    private function findBestProvider(string $serviceId, ?string $excludeId = null): ?array
    {
        // In a real system you'd filter by provider skills/service types
        // Here we assign any active provider
        $sql = '
            SELECT u.id, COALESCE(ps.active_jobs_count, 0) as active_jobs
            FROM users u
            LEFT JOIN provider_stats ps ON ps.provider_id = u.id
            WHERE u.role = "provider"
              AND u.is_active = 1
        ';
        $params = [];

        if ($excludeId) {
            $sql    .= ' AND u.id != ?';
            $params[] = $excludeId;
        }

        $sql .= ' ORDER BY active_jobs ASC, COALESCE(ps.last_assigned_at, "2000-01-01") ASC LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    private function lockService(string $serviceId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, order_id, service_id, status, provider_id, assign_attempts
             FROM order_services WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$serviceId]);
        return $stmt->fetch() ?: null;
    }

    private function escalate(string $serviceId, array $svc): void
    {
        // Mark as unassignable — notify admins
        error_log("ESCALATE: Service {$serviceId} could not be assigned after max attempts");
        $notifier = new NotificationService($this->pdo);

        // Notify all admins
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE role = "admin" AND is_active = 1');
        $stmt->execute();
        while ($admin = $stmt->fetch()) {
            $notifier->notify($admin['id'], 'assignment_escalation', [
                'title'      => 'Job assignment failed',
                'body'       => "Service {$serviceId} could not be auto-assigned. Manual action required.",
                'service_id' => $serviceId,
            ]);
        }
    }
}

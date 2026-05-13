<?php
// backend/controllers/ProviderController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\{Response, Request};
use App\Middleware\AuthMiddleware;
use App\Helpers\{AssignmentEngine, NotificationService};

class ProviderController
{
    // GET /api/provider/jobs?status=accepted,in_progress&page=1
	public function jobs(): void
	{
		$user = AuthMiddleware::require(['provider', 'admin']);
		
		// Opportunistic timeout sweep — runs at most once every 30 seconds
		// to avoid hammering the DB on every poll
		self::maybeProcessTimeouts();
		
		$pdo  = Database::getInstance();
		$page   = max(1, (int)($_GET['page'] ?? 1));
		$limit  = min(100, max(10, (int)($_GET['limit'] ?? 20)));
		$offset = ($page - 1) * $limit;

		// Which provider's jobs are we listing?
		$targetProviderId = null;
		if ($user['role'] === 'provider') {
			$targetProviderId = $user['id'];
		} else if ($user['role'] === 'admin' && !empty($_GET['provider_id'])) {
			$targetProviderId = $_GET['provider_id'];
		}

		$where  = [];
		$params = [];

		// Status filter (gathered first so we know which date column to apply against)
		$statuses = [];
		if (!empty($_GET['status'])) {
			$raw = array_map('trim', explode(',', $_GET['status']));
			$valid = ['pending','assigned','accepted','in_progress','completed','cancelled','rejected'];
			$statuses = array_values(array_filter($raw, fn($s) => in_array($s, $valid, true)));
		}
		$isHistorical = !empty($statuses) && array_diff($statuses, ['completed','cancelled']) === [];

		if ($targetProviderId) {
			$where[] = 'os.provider_id = ?';
			$params[] = $targetProviderId;

			// Apply capability filter only to ACTIVE jobs.
			// For completed/cancelled, the provider should always see their own historical work,
			// even if their current capability list no longer includes that service.
			if (!$isHistorical) {
				$where[] = '(os.status IN ("completed","cancelled") OR EXISTS (
					SELECT 1 FROM provider_services psvc
					WHERE psvc.provider_id = ? AND psvc.service_id = os.service_id
				))';
				$params[] = $targetProviderId;
			}
		}

		if (!empty($statuses)) {
			$placeholders = implode(',', array_fill(0, count($statuses), '?'));
			$where[]      = "os.status IN ({$placeholders})";
			$params       = array_merge($params, $statuses);
		}

		// Date filter — use completed_at for historical views, created_at otherwise
		$dateCol = $isHistorical ? 'os.completed_at' : 'os.created_at';

		if (!empty($_GET['from'])) {
			$where[]  = "DATE({$dateCol}) >= ?";
			$params[] = $_GET['from'];
		}
		if (!empty($_GET['to'])) {
			$where[]  = "DATE({$dateCol}) <= ?";
			$params[] = $_GET['to'];
		}
		
		if ($user['role'] === 'provider') {
			$where[] = 'o.payment_status = "paid"';
		}

		$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

		// Order by completed_at for historical views, assigned_at otherwise
		$orderBy = $isHistorical
			? 'os.completed_at DESC'
			: "FIELD(os.status, 'assigned','accepted','in_progress','pending','rejected','completed','cancelled'),
			   os.assigned_at DESC,
			   os.created_at DESC";

		$stmt = $pdo->prepare("
			SELECT
				os.id,
				os.order_id,
				os.service_id,
				s.name           AS service_name,
				s.icon           AS service_icon,
				os.status,
				os.price,
				os.location_details,
				os.image_path,
				os.version,
				os.assign_attempts,
				os.assigned_at,
				os.started_at,
				os.completed_at,
				os.created_at,
				o.order_number,
				o.vehicle_plate,
				o.vehicle_make,
				o.vehicle_model,
				o.customer_name,
				o.customer_phone,
				o.notes          AS order_notes,
				pl.name          AS location_name,
				pl.code          AS location_code,
				u.name           AS provider_name,
				u.id             AS provider_id
			FROM order_services os
			JOIN services s     ON s.id = os.service_id
			JOIN orders o       ON o.id = os.order_id
			LEFT JOIN parking_locations pl ON pl.id = os.location_id
			LEFT JOIN users u   ON u.id = os.provider_id
			{$whereSQL}
			ORDER BY {$orderBy}
			LIMIT {$limit} OFFSET {$offset}
		");
		$stmt->execute($params);

		Response::json([
			'success' => true,
			'data'    => $stmt->fetchAll(),
			'page'    => $page,
		]);
	}
	
	// GET /api/admin/assignment-stats
	public function assignmentStats(): void
	{
		AuthMiddleware::require(['admin', 'agent']);
		$pdo = Database::getInstance();

		$timeoutMin = defined('ASSIGNMENT_ACCEPT_TIMEOUT_MINUTES')
			? ASSIGNMENT_ACCEPT_TIMEOUT_MINUTES : 5;

		$stmt = $pdo->prepare("
			SELECT
				COALESCE(SUM(CASE WHEN status = 'pending'  THEN 1 END), 0) AS pending,
				COALESCE(SUM(CASE WHEN status = 'assigned' THEN 1 END), 0) AS awaiting,
				COALESCE(SUM(CASE WHEN status = 'assigned'
								  AND assigned_at < (UTC_TIMESTAMP() - INTERVAL ? MINUTE)
								  THEN 1 END), 0) AS timed_out
			FROM order_services
		");
		$stmt->execute([$timeoutMin]);
		Response::success($stmt->fetch());
	}
	
	/**
	 * Runs the timeout sweep at most once every TIMEOUT_CHECK_INTERVAL seconds
	 * across the entire process. Uses a file-based lock so concurrent requests
	 * don't all run it.
	 */
	public static function maybeProcessTimeouts(): void
	{
		static $ranThisRequest = false;
		if ($ranThisRequest) return;
		$ranThisRequest = true;

		$lockFile = sys_get_temp_dir() . '/airpark_timeout_lock';
		$interval = 30; // seconds between sweeps

		// Open (or create) the lock file
		$fp = @fopen($lockFile, 'c');
		if (!$fp) return;

		// Try to acquire — bail immediately if another process is sweeping
		if (!flock($fp, LOCK_EX | LOCK_NB)) {
			fclose($fp);
			return;
		}

		try {
			// ── Interval check INSIDE the lock (race-free) ──
			$stat = fstat($fp);
			$age  = time() - ($stat['mtime'] ?? 0);
			if ($age < $interval) {
				return;
			}

			// Run the sweep
			$engine = new \App\Helpers\AssignmentEngine();
			$count  = $engine->processTimeouts();

			if ($count > 0) {
				error_log("[timeout-sweep] Reassigned {$count} stale jobs");
			}

			// Mark completion AFTER the work, so the interval reflects "since last finished"
			touch($lockFile);

		} catch (\Throwable $e) {
			error_log('[timeout-sweep] Error: ' . $e->getMessage());
			// Touch even on error so we don't immediately retry a broken sweep
			@touch($lockFile);
		} finally {
			flock($fp, LOCK_UN);
			fclose($fp);
		}
	}

    // PATCH /api/provider/jobs/{svcId}/accept
    public function accept(array $params): void
    {
        $user  = AuthMiddleware::require(['provider']);
        $svcId = $params['svcId'];

        $orderId = null;
        $result  = ['ok' => false, 'reason' => null];

        Database::transaction(function ($pdo) use ($svcId, $user, &$orderId, &$result) {
            $os = $this->lockAndFetch($pdo, $svcId, $user['id']);
            if (!$os) {
                $result['reason'] = 'Job not found or not assigned to you';
                return;
            }
			
			// Defensive: order must still be paid
			$o = $pdo->prepare('SELECT payment_status FROM orders WHERE id = ?');
			$o->execute([$os['order_id']]);
			if ($o->fetchColumn() !== 'paid') {
				Response::error('Order is not paid — cannot proceed', 422);
			}
			
            if ($os['status'] !== 'assigned') {
                $result['reason'] = "Cannot accept a job in status: {$os['status']}";
                return;
            }

            $pdo->prepare(
                'UPDATE order_services
                 SET status = "accepted", version = version + 1, updated_at = UTC_TIMESTAMP()
                 WHERE id = ?'
            )->execute([$svcId]);

            // Bump active jobs counter + last_assigned timestamp (load balancing)
            $pdo->prepare(
                'INSERT INTO provider_stats (provider_id, active_jobs_count, last_assigned_at, total_completed, updated_at)
                 VALUES (?, 1, UTC_TIMESTAMP(), 0, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                   active_jobs_count = active_jobs_count + 1,
                   last_assigned_at  = UTC_TIMESTAMP(),
                   updated_at        = UTC_TIMESTAMP()'
            )->execute([$user['id']]);

            $orderId      = $os['order_id'];
            $result['ok'] = true;
        });

        if (!$result['ok']) {
            Response::error($result['reason'] ?? 'Could not accept', 422);
        }

        // Notify after commit
        try {
            (new NotificationService())->dispatch(
                $orderId,
                'job_accepted',
                "Job accepted by provider"
            );
        } catch (\Throwable $e) {
            error_log('accept notify failed: ' . $e->getMessage());
        }

        Response::success(['status' => 'accepted'], 'Job accepted');
    }

    // PATCH /api/provider/jobs/{svcId}/reject
    public function reject(array $params): void
    {
        $user  = AuthMiddleware::require(['provider']);
        $svcId = $params['svcId'];

        $result = ['ok' => false, 'reason' => null, 'order_id' => null];

        Database::transaction(function ($pdo) use ($svcId, $user, &$result) {
            $os = $this->lockAndFetch($pdo, $svcId, $user['id']);
            if (!$os) {
                $result['reason'] = 'Job not found or not assigned to you';
                return;
            }
			
			// Defensive: order must still be paid
			$o = $pdo->prepare('SELECT payment_status FROM orders WHERE id = ?');
			$o->execute([$os['order_id']]);
			if ($o->fetchColumn() !== 'paid') {
				Response::error('Order is not paid — cannot proceed', 422);
			}
			
            if (!in_array($os['status'], ['assigned', 'accepted'], true)) {
                $result['reason'] = "Cannot reject a job in status: {$os['status']}";
                return;
            }

            // Reset to pending (so the assignment engine can pick it up again)
            $pdo->prepare(
                'UPDATE order_services
                 SET status = "pending", provider_id = NULL,
                     version = version + 1, assign_attempts = assign_attempts + 1,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = ?'
            )->execute([$svcId]);

            // Decrement active jobs for this provider only if it had been accepted
            if ($os['status'] === 'accepted') {
                $pdo->prepare(
                    'UPDATE provider_stats
                     SET active_jobs_count = GREATEST(0, active_jobs_count - 1),
                         updated_at        = UTC_TIMESTAMP()
                     WHERE provider_id = ?'
                )->execute([$user['id']]);
            }

            $result['ok']       = true;
            $result['order_id'] = $os['order_id'];
        });

        if (!$result['ok']) {
            Response::error($result['reason'] ?? 'Could not reject', 422);
        }

        // Re-assign after commit (so the new provider sees a committed row)
        try {
            $engine = new AssignmentEngine();
            $engine->assign($svcId);
        } catch (\Throwable $e) {
            error_log('reassign after reject failed: ' . $e->getMessage());
        }

        Response::success(null, 'Job rejected and reassigned');
    }

    // PATCH /api/provider/jobs/{svcId}/start
    public function start(array $params): void
    {
        $user  = AuthMiddleware::require(['provider']);
        $svcId = $params['svcId'];

        $result = ['ok' => false, 'reason' => null, 'order_id' => null];

        Database::transaction(function ($pdo) use ($svcId, $user, &$result) {
            $os = $this->lockAndFetch($pdo, $svcId, $user['id']);
            if (!$os) {
                $result['reason'] = 'Job not found or not assigned to you';
                return;
            }
			
			// Defensive: order must still be paid
			$o = $pdo->prepare('SELECT payment_status FROM orders WHERE id = ?');
			$o->execute([$os['order_id']]);
			if ($o->fetchColumn() !== 'paid') {
				Response::error('Order is not paid — cannot proceed', 422);
			}
			
            if ($os['status'] !== 'accepted') {
                $result['reason'] = "Must accept job before starting. Current: {$os['status']}";
                return;
            }

            $pdo->prepare(
                'UPDATE order_services
                 SET status = "in_progress", started_at = UTC_TIMESTAMP(),
                     version = version + 1, updated_at = UTC_TIMESTAMP()
                 WHERE id = ?'
            )->execute([$svcId]);

            $result['ok']       = true;
            $result['order_id'] = $os['order_id'];
        });

        if (!$result['ok']) {
            Response::error($result['reason'] ?? 'Could not start', 422);
        }

        try {
            (new NotificationService())->dispatch(
                $result['order_id'],
                'job_started',
                "Service started for order #{$result['order_id']}"
            );
        } catch (\Throwable $e) {
            error_log('start notify failed: ' . $e->getMessage());
        }

        Response::success(['status' => 'in_progress', 'started_at' => gmdate('c')], 'Job started');
    }

    // PATCH /api/provider/jobs/{svcId}/complete
    public function complete(array $params): void
    {
        $user  = AuthMiddleware::require(['provider']);
        $svcId = $params['svcId'];
        $req   = new Request();

        $imagePath = $req->input('image_path');
        $result    = ['ok' => false, 'reason' => null, 'order_id' => null];

        Database::transaction(function ($pdo) use ($svcId, $user, $imagePath, &$result) {
            $os = $this->lockAndFetch($pdo, $svcId, $user['id']);
            if (!$os) {
                $result['reason'] = 'Job not found or not assigned to you';
                return;
            }
            if ($os['status'] !== 'in_progress') {
                $result['reason'] = "Job must be in_progress to complete. Current: {$os['status']}";
                return;
            }

            $pdo->prepare(
                'UPDATE order_services
                 SET status = "completed",
                     completed_at = UTC_TIMESTAMP(),
                     image_path   = COALESCE(?, image_path),
                     version = version + 1,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = ?'
            )->execute([$imagePath, $svcId]);

            // Update provider stats: decrement active, increment total_completed
            $pdo->prepare(
                'INSERT INTO provider_stats (provider_id, active_jobs_count, last_assigned_at, total_completed, updated_at)
                 VALUES (?, 0, NULL, 1, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                   active_jobs_count = GREATEST(0, active_jobs_count - 1),
                   total_completed   = total_completed + 1,
                   updated_at        = UTC_TIMESTAMP()'
            )->execute([$user['id']]);

            $result['ok']       = true;
            $result['order_id'] = $os['order_id'];
        });

        if (!$result['ok']) {
            Response::error($result['reason'] ?? 'Could not complete', 422);
        }

        try {
            (new NotificationService())->dispatch(
                $result['order_id'],
                'job_completed',
                "Service completed for order #{$result['order_id']}"
            );
        } catch (\Throwable $e) {
            error_log('complete notify failed: ' . $e->getMessage());
        }

        Response::success([
            'status'       => 'completed',
            'completed_at' => gmdate('c'),
        ], 'Job completed');
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * Row-lock the order_service and validate it belongs to this provider.
     * Returns null if not found / not theirs.
     */
    private function lockAndFetch(\PDO $pdo, string $svcId, string $providerId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT os.*, o.id AS order_id
             FROM order_services os
             JOIN orders o ON o.id = os.order_id
             WHERE os.id = ? AND os.provider_id = ?
             FOR UPDATE'
        );
        $stmt->execute([$svcId, $providerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
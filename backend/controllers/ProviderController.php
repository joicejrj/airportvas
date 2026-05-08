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
        $pdo  = Database::getInstance();

        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(50, max(10, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        // Providers only see their own jobs; admin sees all
        $where  = ['os.provider_id = ?'];
        $params = [$user['id']];

        if ($user['role'] === 'admin') {
            $where  = [];
            $params = [];
            if (!empty($_GET['provider_id'])) {
                $where[]  = 'os.provider_id = ?';
                $params[] = $_GET['provider_id'];
            }
        }

        // Status filter (comma-separated)
        if (!empty($_GET['status'])) {
            $statuses = array_map('trim', explode(',', $_GET['status']));
            $valid    = ['pending','assigned','accepted','in_progress','completed','cancelled','rejected'];
            $statuses = array_filter($statuses, fn($s) => in_array($s, $valid, true));
            if ($statuses) {
                $placeholders = implode(',', array_fill(0, count($statuses), '?'));
                $where[]      = "os.status IN ({$placeholders})";
                $params       = array_merge($params, array_values($statuses));
            }
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare("
            SELECT
                os.id,
                os.order_id,
                os.service_id,
                s.name          AS service_name,
                s.icon          AS service_icon,
                os.status,
                os.price,
                os.location_details,
                os.image_path,
                os.version,
                os.assigned_at,
                os.started_at,
                os.completed_at,
                o.vehicle_plate,
                o.vehicle_make,
                o.vehicle_color,
                o.notes         AS order_notes,
                pl.name         AS location_name,
                pl.code         AS location_code
            FROM order_services os
            JOIN services s        ON s.id = os.service_id
            JOIN orders o          ON o.id = os.order_id
            LEFT JOIN parking_locations pl ON pl.id = o.parking_location_id
            {$whereSQL}
            ORDER BY
                FIELD(os.status, 'assigned','accepted','in_progress','pending','completed','cancelled','rejected'),
                os.assigned_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);

        Response::json(['success' => true, 'data' => $stmt->fetchAll(), 'page' => $page]);
    }

    // PATCH /api/provider/jobs/{svcId}/accept
    public function accept(array $params): void
    {
        $user   = AuthMiddleware::require(['provider']);
        $svcId  = $params['svcId'];
        $pdo    = Database::getInstance();

        Database::transaction(function ($pdo) use ($svcId, $user) {
            $os = $this->lockAndFetch($pdo, $svcId, $user['id']);

            if ($os['status'] !== 'assigned') {
                Response::error("Cannot accept a job in status: {$os['status']}", 422);
            }

            $pdo->prepare(
                'UPDATE order_services
                 SET status = "accepted", version = version + 1, updated_at = UTC_TIMESTAMP()
                 WHERE id = ?'
            )->execute([$svcId]);

            // Notify agent/admin of acceptance
            (new NotificationService())->dispatch(
                $os['order_id'],
                'job_accepted',
                "Job #{$svcId} accepted by provider"
            );

            Response::success(['status' => 'accepted'], 'Job accepted');
        });
    }

    // PATCH /api/provider/jobs/{svcId}/reject
    public function reject(array $params): void
    {
        $user  = AuthMiddleware::require(['provider']);
        $svcId = $params['svcId'];
        $req   = new Request();

        Database::transaction(function ($pdo) use ($svcId, $user, $req) {
            $os = $this->lockAndFetch($pdo, $svcId, $user['id']);

            if (!in_array($os['status'], ['assigned', 'accepted'], true)) {
                Response::error("Cannot reject a job in status: {$os['status']}", 422);
            }

            $pdo->prepare(
                'UPDATE order_services
                 SET status = "rejected", provider_id = NULL,
                     version = version + 1, updated_at = UTC_TIMESTAMP()
                 WHERE id = ?'
            )->execute([$svcId]);

            // Decrement stats for this provider
            $pdo->prepare(
                'UPDATE provider_stats
                 SET active_jobs_count = GREATEST(0, active_jobs_count - 1)
                 WHERE provider_id = ?'
            )->execute([$user['id']]);

            // Trigger re-assignment
            $engine = new AssignmentEngine();
            $engine->assign($svcId, excludeProviders: [$user['id']]);

            Response::success(null, 'Job rejected; reassigned');
        });
    }

    // PATCH /api/provider/jobs/{svcId}/start
    public function start(array $params): void
    {
        $user  = AuthMiddleware::require(['provider']);
        $svcId = $params['svcId'];

        Database::transaction(function ($pdo) use ($svcId, $user) {
            $os = $this->lockAndFetch($pdo, $svcId, $user['id']);

            if ($os['status'] !== 'accepted') {
                Response::error("Must accept job before starting. Current: {$os['status']}", 422);
            }

            $pdo->prepare(
                'UPDATE order_services
                 SET status = "in_progress", started_at = UTC_TIMESTAMP(),
                     version = version + 1, updated_at = UTC_TIMESTAMP()
                 WHERE id = ?'
            )->execute([$svcId]);

            (new NotificationService())->dispatch(
                $os['order_id'],
                'job_started',
                "Service started for order #{$os['order_id']}"
            );

            Response::success(['status' => 'in_progress', 'started_at' => gmdate('c')], 'Job started');
        });
    }

    // PATCH /api/provider/jobs/{svcId}/complete
    public function complete(array $params): void
    {
        $user  = AuthMiddleware::require(['provider']);
        $svcId = $params['svcId'];
        $req   = new Request();

        Database::transaction(function ($pdo) use ($svcId, $user, $req) {
            $os = $this->lockAndFetch($pdo, $svcId, $user['id']);

            if ($os['status'] !== 'in_progress') {
                Response::error("Job must be in_progress to complete. Current: {$os['status']}", 422);
            }

            $imagePath = $req->input('image_path'); // optional completion photo

            $pdo->prepare(
                'UPDATE order_services
                 SET status = "completed",
                     completed_at = UTC_TIMESTAMP(),
                     image_path   = COALESCE(?, image_path),
                     version = version + 1,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = ?'
            )->execute([$imagePath, $svcId]);

            (new NotificationService())->dispatch(
                $os['order_id'],
                'job_completed',
                "Service completed for order #{$os['order_id']}"
            );

            // Check if ALL services on this order are now complete
            $this->maybeMarkOrderComplete($pdo, $os['order_id']);

            Response::success([
                'status'       => 'completed',
                'completed_at' => gmdate('c'),
            ], 'Job completed');
        });
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * Row-lock the order_service and validate it belongs to this provider.
     */
    private function lockAndFetch(\PDO $pdo, string $svcId, string $providerId): array
    {
        $stmt = $pdo->prepare(
            'SELECT os.*, o.id AS order_id
             FROM order_services os
             JOIN orders o ON o.id = os.order_id
             WHERE os.id = ?
             FOR UPDATE'
        );
        $stmt->execute([$svcId]);
        $os = $stmt->fetch();

        if (!$os) {
            Response::error('Job not found', 404);
        }
        if ($os['provider_id'] !== $providerId) {
            Response::error('This job is not assigned to you', 403);
        }

        return $os;
    }

    /**
     * If every order_service is completed/cancelled, optionally notify admin.
     */
    private function maybeMarkOrderComplete(\PDO $pdo, string $orderId): void
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS open
             FROM order_services
             WHERE order_id = ?
               AND status NOT IN ('completed','cancelled')"
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();

        if ((int)$row['open'] === 0) {
            (new NotificationService())->dispatch(
                $orderId,
                'order_all_complete',
                "All services on order #{$orderId} are complete"
            );
        }
    }
}

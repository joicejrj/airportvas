<?php
// backend/controllers/SyncController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\{Response, Request};
use App\Middleware\AuthMiddleware;
use App\Helpers\OrderClaim;

/**
 * Offline-sync batch processor for the Team Leader PWA.
 *
 *   POST /api/sync   — accepts a list of queued actions, processes each
 *
 * Request body:
 *   {
 *     "client_id": "uuid-from-pwa",
 *     "actions": [
 *       {
 *         "id": "action-uuid",
 *         "entity_type": "service" | "order" | "payment",
 *         "entity_id":   "...",
 *         "action_type": "accept" | "reject" | "start" | "complete"
 *                      | "direct_book" | "payment_record" | "handover_submit",
 *         "payload":     { ... },
 *         "version":     <int, for optimistic locking>
 *       },
 *       ...
 *     ]
 *   }
 *
 * Response body:
 *   {
 *     "success":  [{ "action_id": "...", "data": ... }, ...],
 *     "failed":   [{ "action_id": "...", "error": "...", "code": "..." }, ...],
 *     "conflict": [{ "action_id": "...", "server_version": N }, ...]
 *   }
 *
 * Each action is processed independently inside its own transaction so
 * a single bad action doesn't roll back the others.
 *
 * Idempotency: sync_log has UNIQUE (client_id, action_id). If the same
 * action arrives twice we return the original outcome without
 * re-executing.
 */
class SyncController
{
    public function sync(array $params = []): void
    {
        $user = AuthMiddleware::require(['team_leader']);
        $req  = new Request();

        $clientId = (string)$req->input('client_id', '');
        $actions  = $req->input('actions', []);

        if (!$clientId || !is_array($actions)) {
            Response::error('client_id and actions[] are required', 422);
        }
        if (count($actions) > 100) {
            Response::error('Too many actions in one batch (max 100)', 422);
        }

        $pdo = Database::getInstance();
        $success = $failed = $conflict = [];

        foreach ($actions as $action) {
            $actionId   = (string)($action['id']          ?? '');
            $entityType = (string)($action['entity_type'] ?? '');
            $entityId   = (string)($action['entity_id']   ?? '');
            $actionType = (string)($action['action_type'] ?? '');
            $payload    = $action['payload'] ?? [];
            if (!is_array($payload)) $payload = [];

            if (!$actionId || !$actionType) {
                $failed[] = [
                    'action_id' => $actionId,
                    'error'     => 'Malformed action',
                    'code'      => 'malformed',
                ];
                continue;
            }

            // Idempotency check
            $dup = $pdo->prepare(
                'SELECT status FROM sync_log WHERE client_id = ? AND action_id = ? LIMIT 1'
            );
            $dup->execute([$clientId, $actionId]);
            $prev = $dup->fetchColumn();
            if ($prev) {
                $success[] = ['action_id' => $actionId, 'data' => null, 'duplicate' => true];
                continue;
            }

            try {
                $result = $this->processOne(
                    $user, $entityType, $entityId, $actionType, $payload
                );

                // Log success
                $pdo->prepare(
                    'INSERT INTO sync_log (id, client_id, action_id, entity_type, entity_id,
                                            action_type, status, processed_at)
                     VALUES (UUID(), ?, ?, ?, ?, ?, "success", UTC_TIMESTAMP())'
                )->execute([$clientId, $actionId, $entityType, $entityId, $actionType]);

                $success[] = ['action_id' => $actionId, 'data' => $result];
            } catch (ConflictException $e) {
                $pdo->prepare(
                    'INSERT INTO sync_log (id, client_id, action_id, entity_type, entity_id,
                                            action_type, status, conflict_data, processed_at)
                     VALUES (UUID(), ?, ?, ?, ?, ?, "conflict", ?, UTC_TIMESTAMP())'
                )->execute([
                    $clientId, $actionId, $entityType, $entityId, $actionType,
                    json_encode($e->getData()),
                ]);
                $conflict[] = array_merge(['action_id' => $actionId], $e->getData());
            } catch (\Throwable $e) {
                $pdo->prepare(
                    'INSERT INTO sync_log (id, client_id, action_id, entity_type, entity_id,
                                            action_type, status, processed_at)
                     VALUES (UUID(), ?, ?, ?, ?, ?, "failed", UTC_TIMESTAMP())'
                )->execute([$clientId, $actionId, $entityType, $entityId, $actionType]);

                $failed[] = [
                    'action_id' => $actionId,
                    'error'     => $e->getMessage(),
                    'code'      => 'failed',
                ];
            }
        }

        Response::success([
            'success'  => $success,
            'failed'   => $failed,
            'conflict' => $conflict,
        ]);
    }

    /**
     * Dispatch a single action to the right handler.
     *
     * Throws ConflictException for race losses (e.g. another TL already
     * claimed the order); throws normal \Throwable for other failures.
     */
    private function processOne(
        array $user, string $entityType, string $entityId,
        string $actionType, array $payload
    ): ?array {
        $claim = new OrderClaim();

        switch ($actionType) {

            case 'accept':
                $r = $claim->accept($entityId, $user['id']);
                if (!$r['ok']) {
                    if ($r['code'] === OrderClaim::ALREADY_TAKEN) {
                        throw new ConflictException('Already taken', ['code' => $r['code']]);
                    }
                    throw new \RuntimeException($r['message']);
                }
                return null;

            case 'reject':
                $r = $claim->reject($entityId, $user['id']);
                if (!$r['ok']) throw new \RuntimeException($r['message']);
                return null;

            case 'start':
                $this->transitionStatus($entityId, $user['id'], 'accepted', 'in_progress', 'started_at');
                return null;

            case 'complete':
                $this->transitionStatus($entityId, $user['id'], 'in_progress', 'completed', 'completed_at');
                return null;

            case 'direct_book':
                // If the payload still carries a base64 data URL (because the
                // upload couldn't happen at submit-time — i.e. offline submit),
                // decode it to a real file now and replace image_path with the URL.
                $payload['image_path'] = \App\Helpers\ImageStore::normalize(
                    $payload['image_path'] ?? null, 'order'
                );
                $r = $claim->directBook($user['id'], $payload);
                if (!$r['ok']) throw new \RuntimeException($r['message']);
                return $r['data'];

            default:
                throw new \RuntimeException("Unknown action_type: $actionType");
        }
    }

    private function transitionStatus(
        string $svcId, string $tlId,
        string $fromStatus, string $toStatus, string $tsColumn
    ): void {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "UPDATE order_services
                SET status = ?, $tsColumn = UTC_TIMESTAMP(),
                    version = version + 1, updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND provider_id = ? AND status = ?"
        );
        $stmt->execute([$toStatus, $svcId, $tlId, $fromStatus]);

        if ($stmt->rowCount() === 0) {
            $check = $pdo->prepare('SELECT status, provider_id, version FROM order_services WHERE id = ?');
            $check->execute([$svcId]);
            $row = $check->fetch();
            if (!$row) throw new \RuntimeException('Job not found');
            if ($row['provider_id'] !== $tlId) {
                throw new ConflictException('Not your job', [
                    'code'           => 'wrong_provider',
                    'server_version' => (int)$row['version'],
                ]);
            }
            throw new ConflictException('Wrong status', [
                'code'           => 'wrong_status',
                'server_status'  => $row['status'],
                'server_version' => (int)$row['version'],
            ]);
        }
    }
}

// Local exception for sync conflicts so SyncController::sync() can split
// them from generic failures.
class ConflictException extends \RuntimeException
{
    private array $data;

    public function __construct(string $message, array $data = [])
    {
        parent::__construct($message);
        $this->data = $data;
    }

    public function getData(): array
    {
        return $this->data;
    }
}

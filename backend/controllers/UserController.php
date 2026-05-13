<?php
// backend/controllers/UserController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\{Response, Request};
use App\Middleware\AuthMiddleware;

/**
 * User management — admin only.
 *
 * GET    /api/users                       – list (filter by role, search by name/email)
 * GET    /api/users/{id}                  – single user (with services for providers)
 * POST   /api/users                       – create user (providers must include services[])
 * PUT    /api/users/{id}                  – update user (incl. password reset, service list)
 * DELETE /api/users/{id}                  – soft-delete (is_active = 0)
 *
 * GET    /api/providers                   – provider list with stats + their services
 * GET    /api/providers/for-service/{svcId} – providers eligible for an order_service
 */
class UserController
{
    // GET /api/users?role=agent&search=ahmed
    public function index(): void
    {
        AuthMiddleware::require(['admin']);
        $pdo = Database::getInstance();

        $where  = [];
        $params = [];

        if (!empty($_GET['role'])) {
            $where[]  = 'role = ?';
            $params[] = $_GET['role'];
        }
        if (!empty($_GET['search'])) {
            $where[]  = '(name LIKE ? OR email LIKE ?)';
            $params[] = '%' . $_GET['search'] . '%';
            $params[] = '%' . $_GET['search'] . '%';
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare("
            SELECT id, name, email, phone, role, is_active, created_at
            FROM users {$whereSQL}
            ORDER BY created_at DESC
        ");
        $stmt->execute($params);
        Response::success($stmt->fetchAll());
    }

    // GET /api/users/{id}
    public function show(array $params): void
    {
        AuthMiddleware::require(['admin']);
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT id, name, email, phone, role, is_active, created_at, updated_at
             FROM users WHERE id = ?'
        );
        $stmt->execute([$params['id']]);
        $user = $stmt->fetch();
        if (!$user) {
            Response::error('User not found', 404);
        }

        // Attach service list for providers
        if ($user['role'] === 'provider') {
            $svc = $pdo->prepare(
                'SELECT s.id, s.name
                 FROM provider_services ps
                 JOIN services s ON s.id = ps.service_id
                 WHERE ps.provider_id = ?'
            );
            $svc->execute([$user['id']]);
            $user['services'] = $svc->fetchAll();
        }

        Response::success($user);
    }

    // POST /api/users
    public function store(): void
    {
        AuthMiddleware::require(['admin']);
        $req = new Request();

        $name       = trim((string)$req->input('name', ''));
        $email      = strtolower(trim((string)$req->input('email', '')));
        $phone      = trim((string)$req->input('phone', ''));
        $role       = (string)$req->input('role', 'agent');
        $password   = (string)$req->input('password', '');
        $serviceIds = $req->input('services');  // array of service UUIDs (providers only)

        if (!$name || !$email) {
            Response::error('Name and email are required', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email address', 422);
        }
        if (!in_array($role, ['admin', 'agent', 'provider', 'customer'], true)) {
            Response::error('Invalid role', 422);
        }
        if (strlen($password) < 8) {
            Response::error('Password must be at least 8 characters', 422);
        }
        if ($role === 'provider' && (empty($serviceIds) || !is_array($serviceIds))) {
            Response::error('Select at least one service for the provider', 422);
        }

        $pdo = Database::getInstance();
        $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $dup->execute([$email]);
        if ($dup->fetch()) {
            Response::error('An account with this email already exists', 409);
        }

        // Validate every selected service exists and is active
        if ($role === 'provider') {
            $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
            $check = $pdo->prepare(
                "SELECT id FROM services WHERE id IN ({$placeholders}) AND is_active = 1"
            );
            $check->execute($serviceIds);
            if (count($check->fetchAll()) !== count($serviceIds)) {
                Response::error('One or more selected services are invalid or inactive', 422);
            }
        }

        $hash      = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $newUserId = null;

        Database::transaction(function ($pdo) use (
            $name, $email, $phone, $hash, $role, $serviceIds, &$newUserId
        ) {
            // Insert user
            $pdo->prepare(
                'INSERT INTO users (id, name, email, phone, password_hash, role, is_active, created_at, updated_at)
                 VALUES (UUID(), ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            )->execute([$name, $email, $phone ?: null, $hash, $role]);

            // Resolve the new id (UUID was generated by MySQL)
            $idStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $idStmt->execute([$email]);
            $row = $idStmt->fetch();
            $newUserId = $row['id'] ?? null;

            if ($role === 'provider' && $newUserId) {
                // Seed provider_stats
                $pdo->prepare(
                    'INSERT IGNORE INTO provider_stats (provider_id, active_jobs_count, total_completed, updated_at)
                     VALUES (?, 0, 0, UTC_TIMESTAMP())'
                )->execute([$newUserId]);

                // Attach services
                $ins = $pdo->prepare(
                    'INSERT INTO provider_services (provider_id, service_id, created_at)
                     VALUES (?, ?, UTC_TIMESTAMP())'
                );
                foreach ($serviceIds as $sid) {
                    $ins->execute([$newUserId, $sid]);
                }
            }
        });

        Response::json([
            'success' => true,
            'message' => 'User created',
            'id'      => $newUserId,
        ], 201);
    }

    // PUT /api/users/{id}
    public function update(array $params): void
    {
        AuthMiddleware::require(['admin']);
        $req = new Request();
        $pdo = Database::getInstance();

        $exists = $pdo->prepare('SELECT id, role FROM users WHERE id = ?');
        $exists->execute([$params['id']]);
        $existingUser = $exists->fetch();
        if (!$existingUser) {
            Response::error('User not found', 404);
        }

        $fields = [];
        $values = [];

        foreach (['name', 'phone'] as $col) {
            if (($v = $req->input($col)) !== null) {
                $fields[] = "{$col} = ?";
                $values[] = trim((string)$v) ?: null;
            }
        }

        if (($email = $req->input('email')) !== null) {
            $email = strtolower(trim((string)$email));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Response::error('Invalid email address', 422);
            }
            $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
            $dup->execute([$email, $params['id']]);
            if ($dup->fetch()) {
                Response::error('An account with this email already exists', 409);
            }
            $fields[] = 'email = ?';
            $values[] = $email;
        }

        $newRole = $req->input('role');
        if ($newRole !== null) {
            if (!in_array($newRole, ['admin', 'agent', 'provider', 'customer'], true)) {
                Response::error('Invalid role', 422);
            }
            $fields[] = 'role = ?';
            $values[] = $newRole;
        }

        if (($active = $req->input('is_active')) !== null) {
            $fields[] = 'is_active = ?';
            $values[] = (int)(bool)$active;
        }

        $password = (string)$req->input('password', '');
        if ($password !== '') {
            if (strlen($password) < 8) {
                Response::error('Password must be at least 8 characters', 422);
            }
            $fields[] = 'password_hash = ?';
            $values[] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        }

        // Service-id list — only meaningful for providers
        $serviceIds    = $req->input('services');
        $effectiveRole = $newRole ?: $existingUser['role'];

        if ($serviceIds !== null && $effectiveRole === 'provider') {
            if (!is_array($serviceIds) || empty($serviceIds)) {
                Response::error('Select at least one service for the provider', 422);
            }
            // Validate
            $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
            $check = $pdo->prepare(
                "SELECT id FROM services WHERE id IN ({$placeholders}) AND is_active = 1"
            );
            $check->execute($serviceIds);
            if (count($check->fetchAll()) !== count($serviceIds)) {
                Response::error('One or more selected services are invalid or inactive', 422);
            }
        } elseif ($newRole === 'provider'
            && $existingUser['role'] !== 'provider'
            && empty($serviceIds)) {
            Response::error('Select at least one service when promoting to provider', 422);
        }

        if (!$fields && $serviceIds === null) {
            Response::error('No fields to update', 422);
        }

        Database::transaction(function ($pdo) use (
            $params, $fields, $values, $serviceIds, $effectiveRole
        ) {
            if ($fields) {
                $fields[] = 'updated_at = UTC_TIMESTAMP()';
                $values[] = $params['id'];
                $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')
                    ->execute($values);
            }

            // Seed provider_stats if user was just promoted to provider
            if ($effectiveRole === 'provider') {
                $pdo->prepare(
                    'INSERT IGNORE INTO provider_stats (provider_id, active_jobs_count, total_completed, updated_at)
                     VALUES (?, 0, 0, UTC_TIMESTAMP())'
                )->execute([$params['id']]);
            }

            // If user is (or stays) a provider AND services were provided → replace mapping
            if ($effectiveRole === 'provider' && $serviceIds !== null) {
                $pdo->prepare('DELETE FROM provider_services WHERE provider_id = ?')
                    ->execute([$params['id']]);

                $ins = $pdo->prepare(
                    'INSERT INTO provider_services (provider_id, service_id, created_at)
                     VALUES (?, ?, UTC_TIMESTAMP())'
                );
                foreach ($serviceIds as $sid) {
                    $ins->execute([$params['id'], $sid]);
                }
            }

            // If user is no longer a provider → clear their service mapping
            if ($effectiveRole !== 'provider') {
                $pdo->prepare('DELETE FROM provider_services WHERE provider_id = ?')
                    ->execute([$params['id']]);
            }
        });

        Response::success(null, 'User updated');
    }

    // DELETE /api/users/{id}  — soft delete (is_active = 0)
    public function destroy(array $params): void
    {
        $admin = AuthMiddleware::require(['admin']);
        if ($admin['id'] === $params['id']) {
            Response::error('Cannot deactivate your own account', 422);
        }
        $pdo = Database::getInstance();
        $pdo->prepare('UPDATE users SET is_active = 0, updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$params['id']]);
        Response::success(null, 'User deactivated');
    }

    // GET /api/providers — provider list with stats + their services
    public function providers(): void
    {
        AuthMiddleware::require(['admin']);
        $pdo = Database::getInstance();

        $stmt = $pdo->query("
            SELECT u.id, u.name, u.email, u.phone, u.is_active, u.created_at,
                   COALESCE(ps.active_jobs_count, 0) AS active_jobs,
                   COALESCE(ps.total_completed,  0) AS total_completed,
                   ps.last_assigned_at,
                   (
                     SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ')
                     FROM provider_services psvc
                     JOIN services s ON s.id = psvc.service_id
                     WHERE psvc.provider_id = u.id
                   ) AS service_names,
                   (
                     SELECT GROUP_CONCAT(psvc.service_id)
                     FROM provider_services psvc
                     WHERE psvc.provider_id = u.id
                   ) AS service_ids
            FROM users u
            LEFT JOIN provider_stats ps ON ps.provider_id = u.id
            WHERE u.role = 'provider'
            ORDER BY u.is_active DESC, ps.active_jobs_count ASC, u.name ASC
        ");

        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['service_ids'] = $r['service_ids'] ? explode(',', $r['service_ids']) : [];
        }

        Response::success($rows);
    }

    // GET /api/providers/for-service/{svcId}
    // Returns active providers eligible to perform the given order_service.
    public function providersForService(array $params): void
    {
        AuthMiddleware::require(['admin', 'agent']);
        $pdo = Database::getInstance();

        // Resolve the catalogue service id from the order_service
        $stmt = $pdo->prepare("
            SELECT s.id AS service_id, s.name AS service_name
            FROM order_services os
            JOIN services s ON s.id = os.service_id
            WHERE os.id = ?
        ");
        $stmt->execute([$params['svcId']]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Service not found', 404);
        }

        // Providers who have this service in their list, sorted by load
        $list = $pdo->prepare("
            SELECT u.id, u.name, u.email, u.phone,
                   COALESCE(ps.active_jobs_count, 0) AS active_jobs,
                   COALESCE(ps.total_completed,  0) AS total_completed
            FROM users u
            JOIN provider_services psvc ON psvc.provider_id = u.id
            LEFT JOIN provider_stats ps ON ps.provider_id = u.id
            WHERE u.role = 'provider'
              AND u.is_active = 1
              AND psvc.service_id = ?
            ORDER BY ps.active_jobs_count ASC, u.name ASC
        ");
        $list->execute([$row['service_id']]);

        Response::json([
            'success'      => true,
            'service_name' => $row['service_name'],
            'service_id'   => $row['service_id'],
            'data'         => $list->fetchAll(),
        ]);
    }
}
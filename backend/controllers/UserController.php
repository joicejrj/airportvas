<?php
// backend/controllers/UserController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\{Response, Request};
use App\Middleware\AuthMiddleware;

/**
 * User management (admin-only).
 *
 *   GET    /api/users               index()    — list with filters
 *   GET    /api/users/{id}          show()
 *   POST   /api/users               store()    — create admin or team_leader
 *   PUT    /api/users/{id}          update()   — update + password reset
 *   DELETE /api/users/{id}          destroy()  — soft delete
 *   GET    /api/team-leaders        teamLeadersWithStats() — admin list with stats
 *
 * Rules:
 *   - team_leader role REQUIRES service_id (single service)
 *   - admin role has service_id = NULL
 */
class UserController
{
    // GET /api/users?role=team_leader&search=ahmed
    public function index(array $params = []): void
    {
        AuthMiddleware::require(['admin']);
        $pdo = Database::getInstance();

        $where = [];
        $bind  = [];

        if (!empty($_GET['role'])
            && in_array($_GET['role'], ['admin','team_leader','customer'], true)) {
            $where[] = 'u.role = ?';
            $bind[]  = (string)$_GET['role'];
        }
        if (!empty($_GET['service_id'])) {
            $where[] = 'u.service_id = ?';
            $bind[]  = (string)$_GET['service_id'];
        }
        if (isset($_GET['active']) && $_GET['active'] !== '') {
            $where[] = 'u.is_active = ?';
            $bind[]  = (int)$_GET['active'];
        }
        if (!empty($_GET['search'])) {
            $q = '%' . trim((string)$_GET['search']) . '%';
            $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
            array_push($bind, $q, $q, $q);
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "
            SELECT u.id, u.name, u.email, u.phone, u.role, u.service_id,
                   u.is_active, u.created_at, u.updated_at,
                   s.name AS service_name
              FROM users u
              LEFT JOIN services s ON s.id = u.service_id
              $whereSQL
             ORDER BY u.created_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        Response::success($stmt->fetchAll());
    }

    public function show(array $params): void
    {
        AuthMiddleware::require(['admin']);
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT u.id, u.name, u.email, u.phone, u.role, u.service_id,
                    u.is_active, u.created_at, u.updated_at,
                    s.name AS service_name
             FROM users u
             LEFT JOIN services s ON s.id = u.service_id
             WHERE u.id = ?'
        );
        $stmt->execute([$params['id']]);
        $row = $stmt->fetch();
        if (!$row) Response::error('User not found', 404);
        Response::success($row);
    }

    public function store(array $params = []): void
    {
        AuthMiddleware::require(['admin']);
        $req = new Request();

        $name      = trim((string)$req->input('name', ''));
        $email     = strtolower(trim((string)$req->input('email', '')));
        $phone     = trim((string)$req->input('phone', ''));
        $role      = (string)$req->input('role', 'team_leader');
        $password  = (string)$req->input('password', '');
        $serviceId = $req->input('service_id');

        if (!$name)  Response::error('Name is required', 422);
        if (!$email) Response::error('Email is required', 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email address', 422);
        }
        if (!in_array($role, ['admin','team_leader'], true)) {
            Response::error('Role must be admin or team_leader', 422);
        }
        if (strlen($password) < 8) {
            Response::error('Password must be at least 8 characters', 422);
        }
        if ($role === 'team_leader' && !$serviceId) {
            Response::error('Team leaders must have a service_id', 422);
        }
        if ($role === 'admin') $serviceId = null;
        if ($phone !== '' && !preg_match(UAE_PHONE_REGEX, $phone)) {
            Response::error('Phone must be in +971XXXXXXXXX format', 422);
        }

        $pdo = Database::getInstance();

        $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $dup->execute([$email]);
        if ($dup->fetch()) Response::error('Email already in use', 409);

        if ($serviceId) {
            $svc = $pdo->prepare('SELECT id FROM services WHERE id = ? AND is_active = 1');
            $svc->execute([$serviceId]);
            if (!$svc->fetch()) Response::error('Service not found or inactive', 422);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]);
        $id   = $pdo->query('SELECT UUID()')->fetchColumn();

        Database::transaction(function (\PDO $pdo) use ($id, $name, $email, $phone, $hash, $role, $serviceId) {
            $pdo->prepare(
                'INSERT INTO users (id, name, email, phone, password_hash, role, service_id,
                                    is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            )->execute([$id, $name, $email, $phone ?: null, $hash, $role, $serviceId]);

            if ($role === 'team_leader') {
                $pdo->prepare(
                    'INSERT IGNORE INTO team_leader_stats
                     (team_leader_id, active_jobs_count, total_completed, updated_at)
                     VALUES (?, 0, 0, UTC_TIMESTAMP())'
                )->execute([$id]);
            }
        });

        Response::json(['success'=>true, 'message'=>'User created', 'id'=>$id], 201);
    }

    public function update(array $params): void
    {
        AuthMiddleware::require(['admin']);
        $req = new Request();
        $pdo = Database::getInstance();

        $existing = $pdo->prepare('SELECT id, role FROM users WHERE id = ?');
        $existing->execute([$params['id']]);
        $u = $existing->fetch();
        if (!$u) Response::error('User not found', 404);

        $sets = [];
        $vals = [];

        if ($req->has('name')) {
            $name = trim((string)$req->input('name'));
            if (!$name) Response::error('name cannot be empty', 422);
            $sets[] = 'name = ?'; $vals[] = $name;
        }
        if ($req->has('email')) {
            $email = strtolower(trim((string)$req->input('email')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Response::error('Invalid email', 422);
            }
            $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
            $dup->execute([$email, $params['id']]);
            if ($dup->fetch()) Response::error('Email already in use', 409);
            $sets[] = 'email = ?'; $vals[] = $email;
        }
        if ($req->has('phone')) {
            $phone = trim((string)$req->input('phone'));
            if ($phone !== '' && !preg_match(UAE_PHONE_REGEX, $phone)) {
                Response::error('Phone must be in +971XXXXXXXXX format', 422);
            }
            $sets[] = 'phone = ?'; $vals[] = $phone !== '' ? $phone : null;
        }
        if ($req->has('password')) {
            $pw = (string)$req->input('password');
            if (strlen($pw) < 8) Response::error('Password must be 8+ chars', 422);
            $sets[] = 'password_hash = ?';
            $vals[] = password_hash($pw, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]);
        }

        $newRole = $u['role'];
        if ($req->has('role')) {
            $newRole = (string)$req->input('role');
            if (!in_array($newRole, ['admin','team_leader'], true)) {
                Response::error('Invalid role', 422);
            }
            $sets[] = 'role = ?'; $vals[] = $newRole;
        }
        if ($req->has('service_id') || $newRole === 'team_leader') {
            $svcId = $req->has('service_id') ? $req->input('service_id') : null;
            if ($newRole === 'team_leader' && !$svcId) {
                Response::error('Team leaders must have a service_id', 422);
            }
            if ($newRole === 'admin') $svcId = null;
            if ($svcId) {
                $svc = $pdo->prepare('SELECT id FROM services WHERE id = ? AND is_active = 1');
                $svc->execute([$svcId]);
                if (!$svc->fetch()) Response::error('Service not found or inactive', 422);
            }
            $sets[] = 'service_id = ?'; $vals[] = $svcId;
        }
        if ($req->has('is_active')) {
            $sets[] = 'is_active = ?'; $vals[] = (int)(bool)$req->input('is_active');
        }

        if (!$sets) Response::error('No fields to update', 422);

        $sets[] = 'updated_at = UTC_TIMESTAMP()';
        $vals[] = $params['id'];
        $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);

        // Seed stats row if promoted to team_leader
        if ($newRole === 'team_leader') {
            $pdo->prepare(
                'INSERT IGNORE INTO team_leader_stats
                 (team_leader_id, active_jobs_count, total_completed, updated_at)
                 VALUES (?, 0, 0, UTC_TIMESTAMP())'
            )->execute([$params['id']]);
        }

        Response::success(null, 'User updated');
    }

    public function destroy(array $params): void
    {
        $admin = AuthMiddleware::require(['admin']);
        if ($admin['id'] === $params['id']) {
            Response::error('Cannot deactivate your own account', 422);
        }
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('UPDATE users SET is_active = 0, updated_at = UTC_TIMESTAMP() WHERE id = ?');
        $stmt->execute([$params['id']]);
        if ($stmt->rowCount() === 0) Response::error('User not found', 404);
        Response::success(null, 'User deactivated');
    }

    // GET /api/team-leaders — admin list with stats
    public function teamLeadersWithStats(array $params = []): void
    {
        AuthMiddleware::require(['admin']);
        $pdo = Database::getInstance();

        $stmt = $pdo->query("
            SELECT u.id, u.name, u.email, u.phone, u.is_active, u.created_at,
                   u.service_id, s.name AS service_name,
                   COALESCE(tls.active_jobs_count, 0) AS active_jobs,
                   COALESCE(tls.total_completed,  0) AS total_completed,
                   tls.last_assigned_at,
                   (SELECT COUNT(*) FROM employees e
                     WHERE e.team_leader_id = u.id AND e.is_active = 1) AS employee_count
              FROM users u
              LEFT JOIN services          s   ON s.id   = u.service_id
              LEFT JOIN team_leader_stats tls ON tls.team_leader_id = u.id
             WHERE u.role = 'team_leader'
             ORDER BY u.is_active DESC, s.name, u.name
        ");
        Response::success($stmt->fetchAll());
    }
}
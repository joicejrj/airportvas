<?php
// backend/controllers/AuthController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\{Response, Request};
use App\Middleware\AuthMiddleware;

/**
 * Auth endpoints.
 *
 *   POST /api/auth/login          — issue session token
 *   POST /api/auth/logout         — invalidate token
 *   GET  /api/auth/me             — current user (refreshed from DB)
 *   GET  /api/users/team-leaders  — admin only, dropdown source
 */
class AuthController
{
    // POST /api/auth/login
    public function login(): void
    {
        $req      = new Request();
        $email    = strtolower(trim((string)$req->input('email', '')));
        $password = (string)$req->input('password', '');

        if (!$email || !$password) {
            Response::error('Email and password are required', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email address', 422);
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT u.id, u.name, u.email, u.phone, u.password_hash,
                    u.role, u.service_id, u.is_active,
                    s.name       AS service_name,
                    s.base_price AS service_price
             FROM users u
             LEFT JOIN services s ON s.id = u.service_id
             WHERE u.email = ?
             LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Same generic message for "no such user" and "wrong password"
        // so we don't leak which emails exist.
        if (!$user) {
            Response::error('Invalid credentials', 401);
        }
        if (!$user['is_active']) {
            Response::error('Account is deactivated', 403);
        }
        if (!password_verify($password, $user['password_hash'])) {
            Response::error('Invalid credentials', 401);
        }

        // Team leader without a service can't be useful — block before issuing token
        if ($user['role'] === 'team_leader' && !$user['service_id']) {
            Response::error(
                'Your account has no service assigned. Please contact admin.',
                403
            );
        }

        // Optional: refresh the hash if cost changed
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => PASSWORD_COST])) {
            $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]);
            $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute([$newHash, $user['id']]);
        }

        // Create session
        $token     = bin2hex(random_bytes(32));            // 64-char hex
        $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);

        $pdo->prepare(
            'INSERT INTO sessions (token, user_id, ip, user_agent, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([
            $token,
            $user['id'],
            $req->ip(),
            substr($req->userAgent(), 0, 500),
            $expiresAt,
        ]);

        // Strip the hash before returning the user object
        unset($user['password_hash']);

        Response::json([
            'success' => true,
            'token'   => $token,
            'expires' => $expiresAt,
            'user'    => $user,
        ]);
    }

    // POST /api/auth/logout
    public function logout(): void
    {
        $token = AuthMiddleware::extractToken();

        if ($token) {
            $pdo = Database::getInstance();
            $pdo->prepare('DELETE FROM sessions WHERE token = ?')->execute([$token]);
        }

        Response::success(null, 'Logged out');
    }

    // GET /api/auth/me
    public function me(): void
    {
        // Accept any authenticated user (admin or team_leader)
        $authed = AuthMiddleware::require(['admin', 'team_leader']);

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT u.id, u.name, u.email, u.phone, u.role, u.service_id,
                    u.is_active, u.created_at,
                    s.name       AS service_name,
                    s.base_price AS service_price
             FROM users u
             LEFT JOIN services s ON s.id = u.service_id
             WHERE u.id = ?'
        );
        $stmt->execute([$authed['id']]);
        $row = $stmt->fetch();

        if (!$row) {
            Response::error('User not found', 404);
        }

        Response::success($row);
    }

    // GET /api/users/team-leaders
    // Admin-only list used by filter dropdowns in the admin panel.
    public function listTeamLeaders(): void
    {
        AuthMiddleware::require(['admin']);

        $pdo  = Database::getInstance();
        $stmt = $pdo->query(
            'SELECT u.id, u.name, u.email, u.service_id,
                    s.name AS service_name
             FROM users u
             LEFT JOIN services s ON s.id = u.service_id
             WHERE u.role = "team_leader"
               AND u.is_active = 1
             ORDER BY s.name, u.name'
        );

        Response::success($stmt->fetchAll());
    }
}
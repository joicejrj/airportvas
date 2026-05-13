<?php
// backend/controllers/AuthController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\{Response, Request};
use App\Middleware\AuthMiddleware;

class AuthController
{
    // POST /api/auth/login
    public function login(): void
    {
        $req      = new Request();
        $email    = trim((string)$req->input('email', ''));
        $password = (string)$req->input('password', '');

        if (!$email || !$password) {
            Response::error('Email and password are required', 422);
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT id, name, email, password_hash, role, is_active
             FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !$user['is_active']) {
            Response::error('Invalid credentials', 401);
        }

        if (!password_verify($password, $user['password_hash'])) {
            Response::error('Invalid credentials', 401);
        }

        // Generate session token
        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);

        $ins = $pdo->prepare(
            'INSERT INTO sessions (token, user_id, expires_at, created_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP())'
        );
        $ins->execute([$token, $user['id'], $expiresAt]);

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
        $user = AuthMiddleware::require(['admin', 'agent', 'provider']);

        // Return fresh data from DB (avoids stale cache in token)
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT id, name, email, role, phone, created_at FROM users WHERE id = ?'
        );
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();

        if (!$row) {
            Response::error('User not found', 404);
        }

        Response::success($row);
    }
	
	// GET /api/users/agents — list active agents (admin only, used by filters)
	public function listAgents(): void
	{
		AuthMiddleware::require(['admin']);
		$pdo = Database::getInstance();
		$stmt = $pdo->query(
			'SELECT id, name FROM users
			 WHERE role = "agent" AND is_active = 1
			 ORDER BY name ASC'
		);
		Response::success($stmt->fetchAll());
	}
}

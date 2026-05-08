<?php
// backend/middleware/AuthMiddleware.php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\Database;
use App\Core\Response;

class AuthMiddleware
{
    private static ?array $currentUser = null;

    /**
     * Require valid session. Aborts with 401 if not authenticated.
     */
    public static function require(array $allowedRoles = []): array
    {
        $token = self::extractToken();

        if (!$token) {
            Response::error('Unauthenticated', 401);
        }

        $user = self::validateToken($token);

        if (!$user) {
            Response::error('Invalid or expired session', 401);
        }

        if ($allowedRoles && !in_array($user['role'], $allowedRoles, true)) {
            Response::error('Forbidden', 403);
        }

        self::$currentUser = $user;
        return $user;
    }

    public static function current(): ?array
    {
        return self::$currentUser;
    }

    public static function extractToken(): ?string
    {
        // Prefer Authorization: Bearer header
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $header, $m)) {
            return $m[1];
        }
        // Fallback: cookie (for browser-based pages)
        return $_COOKIE['auth_token'] ?? null;
    }

    private static function validateToken(string $token): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT s.token, s.expires_at, u.id, u.name, u.email, u.role, u.is_active
             FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.token = ?
               AND s.expires_at > UTC_TIMESTAMP()
               AND u.is_active = 1'
        );
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }
}

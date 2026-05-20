<?php
// backend/middleware/AuthMiddleware.php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\Database;
use App\Core\Response;

/**
 * Bearer-token auth middleware.
 *
 * Every protected controller method starts with:
 *
 *     $user = AuthMiddleware::require(['admin']);
 *     $user = AuthMiddleware::require(['team_leader']);
 *     $user = AuthMiddleware::require(['admin', 'team_leader']);
 *
 * If the token is missing/invalid/expired, or the user's role isn't in
 * the allowed list, the middleware emits a 401/403 and exits.
 *
 * Returned $user is an assoc array:
 *   { id, name, email, role, service_id, is_active, … }
 */
class AuthMiddleware
{
    /**
     * Require an authenticated user with one of the given roles.
     * Aborts the request with 401/403 on failure.
     *
     * @param string[] $allowedRoles  e.g. ['admin'], ['team_leader'], ['admin','team_leader']
     * @return array  full user row + service_name resolved for TLs
     */
    public static function require($allowedRoles): array
    {
        // Back-compat: v1 code calls require('admin') with a bare string.
        // v2 code calls require(['admin','team_leader']) with an array.
        if (is_string($allowedRoles)) {
            $allowedRoles = [$allowedRoles];
        }
        if (!is_array($allowedRoles)) {
            Response::error('Invalid auth configuration', 500);
        }

        $token = self::extractToken();
        if (!$token) {
            Response::error('Authentication required', 401);
        }

        $pdo  = Database::getInstance();

        // Single JOIN: validate session, fetch user, fetch their service name
        $stmt = $pdo->prepare(
            'SELECT u.id, u.name, u.email, u.phone, u.role, u.service_id, u.is_active,
                    s.name AS service_name,
                    sess.expires_at
             FROM sessions sess
             JOIN users u    ON u.id = sess.user_id
             LEFT JOIN services s ON s.id = u.service_id
             WHERE sess.token = ?
               AND sess.expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('Invalid or expired session', 401);
        }
        if (!$user['is_active']) {
            Response::error('Account is deactivated', 403);
        }
        if (!in_array($user['role'], $allowedRoles, true)) {
            Response::error('Forbidden — insufficient role', 403);
        }

        // Team Leaders MUST have a service. Block them if not.
        if ($user['role'] === 'team_leader' && !$user['service_id']) {
            Response::error(
                'Your account has no service assigned. Please contact admin.',
                403
            );
        }

        return $user;
    }

    /**
     * Optional auth — returns the user if a valid token is present,
     * otherwise null. Does NOT exit on failure. Used by public-ish
     * endpoints that change behaviour for logged-in users.
     */
    public static function optional(): ?array
    {
        $token = self::extractToken();
        if (!$token) return null;

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT u.id, u.name, u.email, u.role, u.service_id, u.is_active,
                    s.name AS service_name
             FROM sessions sess
             JOIN users u    ON u.id = sess.user_id
             LEFT JOIN services s ON s.id = u.service_id
             WHERE sess.token = ?
               AND sess.expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        return ($user && $user['is_active']) ? $user : null;
    }

    /**
     * Extract Bearer token from Authorization header or ?token=... query.
     * Returns null if not present.
     */
    public static function extractToken(): ?string
    {
        // 1. Authorization: Bearer xxx
        $auth = $_SERVER['HTTP_AUTHORIZATION']
             ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']  // some FastCGI setups
             ?? null;

        if (!$auth && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) {
                    $auth = $v;
                    break;
                }
            }
        }

        if ($auth && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }

        // 2. ?token=… fallback (useful for image URLs etc.)
        if (!empty($_GET['token'])) {
            return (string)$_GET['token'];
        }

        return null;
    }
}
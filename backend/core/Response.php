<?php
// backend/core/Response.php

declare(strict_types=1);

namespace App\Core;

/**
 * Centralised JSON response helper.
 *
 * Every controller method calls one of:
 *   Response::success($data, $message)
 *   Response::error($message, $statusCode, $extra)
 *   Response::json($payload, $statusCode)
 *
 * Each of these terminates the request via exit(), so caller code
 * doesn't need to "return" anything afterwards.
 *
 * Response shape (consistent across all endpoints):
 *   Success: { "success": true,  "data": …, "message": "…" }
 *   Error:   { "success": false, "error": "…",  "code": "…" }
 */
class Response
{
    /**
     * Emit a successful response and exit.
     *
     * @param mixed       $data    payload (any JSON-encodable value, or null)
     * @param string|null $message optional human-readable message
     */
    public static function success($data = null, ?string $message = null): void
    {
        $payload = ['success' => true];
        if ($data !== null)    $payload['data']    = $data;
        if ($message !== null) $payload['message'] = $message;
        self::json($payload, 200);
    }

    /**
     * Emit an error response and exit.
     *
     * @param string $message  human-readable message shown to the user
     * @param int    $status   HTTP status code (default 400)
     * @param array  $extra    extra fields merged into the response (e.g. ['code'=>'ALREADY_TAKEN'])
     */
    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        $payload = array_merge([
            'success' => false,
            'error'   => $message,
        ], $extra);
        self::json($payload, $status);
    }

    /**
     * Low-level: send any payload as JSON with the given status code, then exit.
     */
    public static function json($payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            // CORS (allowed origins set in config.php)
            $origin = defined('CORS_ALLOWED_ORIGINS') ? CORS_ALLOWED_ORIGINS : '*';
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }

        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    /**
     * Send an HTML response (used by the public handover-confirm page).
     */
    public static function html(string $html, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo $html;
        exit;
    }

    /**
     * Emit a 204 No Content (used for OPTIONS preflight).
     */
    public static function noContent(): void
    {
        if (!headers_sent()) {
            http_response_code(204);
            $origin = defined('CORS_ALLOWED_ORIGINS') ? CORS_ALLOWED_ORIGINS : '*';
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
            header('Access-Control-Max-Age: 86400');
        }
        exit;
    }
}

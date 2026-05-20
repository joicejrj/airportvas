<?php
// backend/core/Request.php

declare(strict_types=1);

namespace App\Core;

/**
 * Request abstraction.
 *
 * Parses JSON body once on first construction and exposes:
 *   $req->input('key', $default)   read body OR query string
 *   $req->query('key', $default)   read query string only
 *   $req->body()                   raw decoded body array
 *   $req->file('field')            $_FILES['field'] or null
 *   $req->header('Name')           HTTP header lookup
 *   $req->method()                 GET, POST, PUT, PATCH, DELETE
 *   $req->ip()                     client IP (X-Forwarded-For aware)
 *
 * JSON parsing is lazy and tolerant — if the body isn't valid JSON we
 * fall back to $_POST so plain form posts still work.
 */
class Request
{
    private array $body  = [];
    private array $query = [];
    private string $method;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->query  = $_GET;

        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        $raw         = file_get_contents('php://input');

        if ($raw && str_contains(strtolower($contentType), 'application/json')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $this->body = $decoded;
            }
        } elseif (!empty($_POST)) {
            $this->body = $_POST;
        } elseif ($raw && in_array($this->method, ['PUT','PATCH','DELETE'], true)) {
            // Some clients send form-encoded data on PUT/PATCH
            parse_str($raw, $parsed);
            if (is_array($parsed)) $this->body = $parsed;
        }
    }

    /**
     * Read a value: tries body first, then query string.
     *
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, $default = null)
    {
        if (array_key_exists($key, $this->body))  return $this->body[$key];
        if (array_key_exists($key, $this->query)) return $this->query[$key];
        return $default;
    }

    /**
     * Read a value from the query string only.
     */
    public function query(string $key, $default = null)
    {
        return array_key_exists($key, $this->query) ? $this->query[$key] : $default;
    }

    public function body(): array
    {
        return $this->body;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body)
            || array_key_exists($key, $this->query);
    }

    public function file(string $field): ?array
    {
        return $_FILES[$field] ?? null;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) return (string)$_SERVER[$key];

        // Special headers without HTTP_ prefix
        if ($name === 'Content-Type'   && isset($_SERVER['CONTENT_TYPE']))   return $_SERVER['CONTENT_TYPE'];
        if ($name === 'Content-Length' && isset($_SERVER['CONTENT_LENGTH'])) return $_SERVER['CONTENT_LENGTH'];

        // PHP's getallheaders() — not available on all SAPIs
        if (function_exists('getallheaders')) {
            $hdrs = getallheaders();
            foreach ($hdrs as $k => $v) {
                if (strcasecmp($k, $name) === 0) return $v;
            }
        }
        return null;
    }

    public function ip(): string
    {
        // Trust X-Forwarded-For only if you've configured a trusted proxy.
        // For now, prefer REMOTE_ADDR.
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function userAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
}

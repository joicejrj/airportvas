<?php
// backend/core/Response.php

declare(strict_types=1);

namespace App\Core;

class Response
{
    public static function json(mixed $data, int $status = 200): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($status);
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success(mixed $data = null, string $message = 'OK', int $status = 200): void
    {
        self::json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    public static function error(string $message, int $status = 400, mixed $detail = null): void
    {
        self::json(['success' => false, 'error' => $message, 'detail' => $detail], $status);
    }
}

// backend/core/Request.php
// (append to same file or split — kept together for brevity)

class Request
{
    private array $body;

    public function __construct()
    {
        $raw = file_get_contents('php://input');
        $this->body = json_decode($raw ?: '{}', true) ?? [];
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->body;
    }

    public function file(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $header, $m)) {
            return $m[1];
        }
        return null;
    }
}

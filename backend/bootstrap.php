<?php
// backend/bootstrap.php

declare(strict_types=1);

// ─── Constants & config ──────────────────────────────────────────────
require_once __DIR__ . '/config/config.php';

// ─── Composer autoloader (for Stripe SDK and other third-party libs) ─
// Tolerate it being missing so dev environments without `composer install`
// still boot — Stripe-touching endpoints will fail at call time with a
// clear error rather than the whole app refusing to start.
foreach ([
    __DIR__ . '/../vendor/autoload.php',   // common: project_root/vendor
    __DIR__ . '/vendor/autoload.php',      // if vendor lives inside backend/
] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}

// ─── PHP-level settings ──────────────────────────────────────────────
date_default_timezone_set(APP_TIMEZONE);   // Asia/Dubai
mb_internal_encoding('UTF-8');

if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');

// ─── PSR-4-ish autoloader for App\… ──────────────────────────────────
spl_autoload_register(function (string $class): void {
    // Only handle our namespace
    if (strncmp($class, 'App\\', 4) !== 0) return;

    // App\Config\Database  ->  config/Database.php
    // App\Helpers\Mailer   ->  helpers/Mailer.php
    // App\Core\Response    ->  core/Response.php
    // App\Middleware\Auth… ->  middleware/AuthMiddleware.php
    // App\Controllers\…    ->  controllers/…
    $relative = substr($class, 4);                              // strip "App\"
    $parts    = explode('\\', $relative);
    $first    = strtolower(array_shift($parts));                // first segment lowercased
    $tail     = implode(DIRECTORY_SEPARATOR, $parts);
    $path     = __DIR__ . DIRECTORY_SEPARATOR . $first . DIRECTORY_SEPARATOR . $tail . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

// ─── Compatibility shim: getallheaders on nginx + php-fpm ────────────
if (!function_exists('getallheaders')) {
    function getallheaders(): array
    {
        $out = [];
        foreach ($_SERVER as $k => $v) {
            if (strncmp($k, 'HTTP_', 5) === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(
                    str_replace('_', ' ', substr($k, 5))
                )));
                $out[$name] = $v;
            }
        }
        return $out;
    }
}

// ─── Global error & exception handlers ───────────────────────────────
set_exception_handler(function (\Throwable $e): void {
    error_log(sprintf(
        '[uncaught] %s in %s:%d  trace: %s',
        $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()
    ));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'error'   => APP_DEBUG ? $e->getMessage() : 'Internal server error',
    ]);
    exit;
});

set_error_handler(function (int $sev, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $sev)) return false;

    // Tolerate known legacy bugs from v1 code we haven't replaced yet.
    // These will go away once Phase B finishes and old files are deleted.
    if (str_contains($msg, 'in_array(): Argument #2')
        || str_contains($msg, 'array_map(): Argument')
        || str_contains($msg, 'count(): Argument')) {
        error_log("[legacy-suppressed] $file:$line — $msg");
        return true;   // swallow it; request continues
    }

    // Convert remaining PHP errors into exceptions
    throw new \ErrorException($msg, 0, $sev, $file, $line);
});

// ─── Sanity: writable uploads dir ────────────────────────────────────
if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0775, true);
}
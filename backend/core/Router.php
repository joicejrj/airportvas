<?php
// backend/core/Router.php

declare(strict_types=1);

namespace App\Core;

/**
 * Lightweight router.
 *
 * Register routes with method helpers:
 *   $router->get('/api/tl/jobs',                [TLController::class, 'jobs']);
 *   $router->post('/api/tl/orders',             [TLController::class, 'directBook']);
 *   $router->patch('/api/tl/jobs/{svcId}/accept', [TLController::class, 'accept']);
 *
 * Patterns may contain {name} segments — captured values are passed to
 * the controller method as an associative array:
 *   public function accept(array $params): void
 *   { $svcId = $params['svcId']; … }
 *
 * Handles OPTIONS preflight automatically (CORS).
 */
class Router
{
    /** @var array<string, array<string, callable|array>> */
    private array $routes = [];

    public function get(string $path, $handler): void    { $this->add('GET',    $path, $handler); }
    public function post(string $path, $handler): void   { $this->add('POST',   $path, $handler); }
    public function put(string $path, $handler): void    { $this->add('PUT',    $path, $handler); }
    public function patch(string $path, $handler): void  { $this->add('PATCH',  $path, $handler); }
    public function delete(string $path, $handler): void { $this->add('DELETE', $path, $handler); }

    private function add(string $method, string $path, $handler): void
    {
        $this->routes[$method][$path] = $handler;
    }

    /**
     * Dispatch the current request.
     * Sends a 404 if no route matched; 405 if path matched but method didn't.
     */
    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // CORS preflight
        if ($method === 'OPTIONS') {
            Response::noContent();
        }

        $uri  = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = '/' . trim($path, '/');

        // Try the requested method first
        $matched = $this->match($method, $path);
        if ($matched) {
            [$handler, $params] = $matched;
            $this->invoke($handler, $params);
            return;
        }

        // Path exists but wrong method? → 405
        foreach (['GET','POST','PUT','PATCH','DELETE'] as $m) {
            if ($m === $method) continue;
            if ($this->match($m, $path) !== null) {
                Response::error('Method not allowed', 405);
            }
        }

        Response::error('Not found', 404);
    }

    /**
     * Return [handler, params] if a route matches, or null.
     */
    private function match(string $method, string $path): ?array
    {
        if (empty($this->routes[$method])) return null;

        foreach ($this->routes[$method] as $pattern => $handler) {
            // Fast path: exact match
            if ($pattern === $path) {
                return [$handler, []];
            }
            // Pattern contains {name}
            if (str_contains($pattern, '{')) {
                $regex = '#^' . preg_replace_callback(
                    '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
                    fn($m) => '(?P<' . $m[1] . '>[^/]+)',
                    $pattern
                ) . '$#';
                if (preg_match($regex, $path, $matches)) {
                    // Keep only the named captures
                    $params = array_filter(
                        $matches,
                        fn($k) => is_string($k),
                        ARRAY_FILTER_USE_KEY
                    );
                    return [$handler, $params];
                }
            }
        }
        return null;
    }

    /**
     * Call the handler — supports [ClassName::class, 'method'] or a closure.
     */
    private function invoke($handler, array $params): void
    {
        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $instance = new $class();
            $instance->$method($params);
            return;
        }
        if (is_callable($handler)) {
            $handler($params);
            return;
        }
        Response::error('Invalid route handler', 500);
    }
}

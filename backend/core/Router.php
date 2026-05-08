<?php
// backend/core/Router.php

declare(strict_types=1);

namespace App\Core;

class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable|array $handler): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $this->compilePattern($pattern),
            'handler' => $handler,
        ];
    }

    public function get(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable|array $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function patch(string $pattern, callable|array $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, callable|array $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Strip /api prefix if routed via nginx alias
        $uri = preg_replace('#^/api#', '', $uri) ?: '/';

        // OPTIONS pre-flight
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['pattern'], $uri, $matches)) {
                $params = array_filter(
                    $matches,
                    fn($k) => !is_int($k),
                    ARRAY_FILTER_USE_KEY
                );
                $this->invoke($route['handler'], $params);
                return;
            }
        }

        Response::json(['error' => 'Not found'], 404);
    }

    private function compilePattern(string $pattern): string
    {
        // Strip /api prefix from pattern for matching
        $pattern = preg_replace('#^/api#', '', $pattern) ?: '/';
        $escaped = preg_quote($pattern, '#');
        $regex   = preg_replace('#\\\{([a-zA-Z_]+)\\\}#', '(?P<$1>[^/]+)', $escaped);
        return '#^' . $regex . '$#';
    }

    private function invoke(callable|array $handler, array $params): void
    {
        if (is_callable($handler)) {
            $handler($params);
            return;
        }
        [$class, $method] = $handler;
        (new $class())->$method($params);
    }
}

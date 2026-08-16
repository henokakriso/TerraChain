<?php
declare(strict_types=1);

final class Router
{
    /** @var array<string, array{handler: callable, middleware: array}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, array $middleware = []): void
    {
        $this->routes[strtoupper($method) . ' ' . $pattern] = [
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function get(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    public function put(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('PUT', $pattern, $handler, $middleware);
    }

    public function delete(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('DELETE', $pattern, $handler, $middleware);
    }

    public function dispatch(string $method, string $path): void
    {
        foreach ($this->routes as $routeKey => $route) {
            [$routeMethod, $pattern] = explode(' ', $routeKey, 2);
            if ($routeMethod !== strtoupper($method)) {
                continue;
            }
            $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern);
            if (preg_match('#^' . $regex . '$#', $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                foreach ($route['middleware'] as $mw) {
                    $mw($path, $params);
                }
                $handler = $route['handler'];
                $handler($params);
                return;
            }
        }
        Response::notFound('API endpoint not found: ' . $path);
    }
}

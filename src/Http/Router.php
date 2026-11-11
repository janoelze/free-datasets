<?php

declare(strict_types=1);

namespace Datasets\Http;

/**
 * Tiny path router. Patterns use {name} placeholders matching one path segment:
 *   $router->get('/api/v1/datasets/{slug}', $handler);
 * GET routes implicitly answer HEAD. match() reports 405 candidates via $allowed.
 */
final class Router
{
    /** @var list<array{methods: list<string>, regex: string, pattern: string, handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): self
    {
        return $this->add(['GET', 'HEAD'], $pattern, $handler);
    }

    /** @param list<string> $methods */
    public function add(array $methods, string $pattern, callable $handler): self
    {
        $regex = preg_replace_callback(
            '/\\\\\{([a-z_][a-z0-9_]*)\\\\\}/i',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            preg_quote($pattern, '#')
        );
        $this->routes[] = ['methods' => $methods, 'regex' => '#^' . $regex . '$#', 'pattern' => $pattern, 'handler' => $handler];

        return $this;
    }

    /**
     * @param list<string> $allowed receives the methods allowed for the path when it exists
     *                              (always includes OPTIONS)
     * @return array{handler: callable, params: array<string, string>, pattern: string}|null
     *         null when no route matches this method (check $allowed for 404 vs 405)
     */
    public function match(string $method, string $path, array &$allowed = []): ?array
    {
        $allowed = [];
        $found = null;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m) !== 1) {
                continue;
            }
            $allowed = array_merge($allowed, $route['methods']);
            if ($found === null && in_array($method, $route['methods'], true)) {
                $params = [];
                foreach ($m as $k => $v) {
                    if (is_string($k)) {
                        $params[$k] = rawurldecode($v);
                    }
                }
                $found = ['handler' => $route['handler'], 'params' => $params, 'pattern' => $route['pattern']];
            }
        }
        if ($allowed !== []) {
            $allowed[] = 'OPTIONS';
            $allowed = array_values(array_unique($allowed));
        }

        return $found;
    }
}

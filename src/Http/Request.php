<?php

declare(strict_types=1);

namespace Datasets\Http;

use Datasets\Config;
use Datasets\Url;

/** An incoming request, with the base path already stripped from $path. */
final class Request
{
    /**
     * @param string                     $method  upper-case
     * @param string                     $path    app path ("/", "/api/v1/datasets"), percent-encoded as received
     * @param array<string, list<string>> $query  parsed query string; repeated keys keep every value
     * @param array<string, string>      $headers lower-case names
     * @param string                     $basePath "" or "/free-datasets"
     * @param string                     $publicUrl absolute app root URL without trailing slash
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly string $basePath = '',
        public readonly string $publicUrl = 'http://localhost',
        public readonly string $rawQuery = '',
        public readonly string $remoteAddr = '',
        private readonly ?string $rawBody = null,
    ) {
    }

    /** @param array<string, mixed>|null $server defaults to $_SERVER */
    public static function fromGlobals(?array $server = null): self
    {
        $server ??= $_SERVER;
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $rawPath = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $rawQuery = (string) ($server['QUERY_STRING'] ?? (parse_url($uri, PHP_URL_QUERY) ?? ''));

        $basePath = self::detectBasePath($server);
        $path = $rawPath;
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath));
        }
        if ($path === '' || $path === false) {
            $path = '/';
        }

        $headers = [];
        foreach ($server as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        // CGI puts these two outside HTTP_*.
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($server[$key]) && is_string($server[$key]) && $server[$key] !== '') {
                $headers[$name] = $server[$key];
            }
        }

        $publicUrl = Config::env('APP_PUBLIC_URL');
        if ($publicUrl === null) {
            $https = (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off')
                || strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
                || strtolower((string) ($server['REQUEST_SCHEME'] ?? '')) === 'https'
                || (string) ($server['SERVER_PORT'] ?? '') === '443';
            $host = (string) ($server['HTTP_HOST'] ?? '');
            if (preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$|^\[[0-9A-Fa-f:.]+\](:\d{1,5})?$/', $host) !== 1) {
                $host = (string) ($server['SERVER_NAME'] ?? 'localhost');
            }
            $publicUrl = ($https ? 'https' : 'http') . '://' . strtolower($host) . $basePath;
        }

        return new self(
            method: strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')),
            path: $path,
            query: self::parseQuery($rawQuery),
            headers: $headers,
            basePath: $basePath,
            publicUrl: rtrim($publicUrl, '/'),
            rawQuery: $rawQuery,
            remoteAddr: (string) ($server['REMOTE_ADDR'] ?? ''),
        );
    }

    /**
     * Request body, read at most $max bytes (read $max + 1 to detect an oversized body).
     * Tests pass the body to the constructor; otherwise it comes from php://input.
     */
    public function body(int $max): string
    {
        if ($this->rawBody !== null) {
            return substr($this->rawBody, 0, $max);
        }
        $body = @file_get_contents('php://input', false, null, 0, $max);

        return is_string($body) ? $body : '';
    }

    /**
     * Base path: APP_BASE_PATH if set, "" under the built-in dev server, otherwise the
     * directory of SCRIPT_NAME (Apache: "/free-datasets/index.php" -> "/free-datasets").
     *
     * @param array<string, mixed> $server
     */
    public static function detectBasePath(array $server): string
    {
        $override = Config::env('APP_BASE_PATH');
        if ($override !== null) {
            return Url::normaliseBasePath($override);
        }
        if (PHP_SAPI === 'cli-server') {
            return '';
        }
        $script = (string) ($server['SCRIPT_NAME'] ?? '');
        if ($script === '' || !str_ends_with($script, '.php')) {
            return '';
        }

        return Url::normaliseBasePath(str_replace('\\', '/', dirname($script)));
    }

    /**
     * Parse a query string keeping repeated keys ("a=1&a=2" => ["a" => ["1", "2"]]).
     * "a[]=1" is treated like "a=1".
     *
     * @return array<string, list<string>>
     */
    public static function parseQuery(string $qs): array
    {
        $out = [];
        if ($qs === '') {
            return $out;
        }
        foreach (explode('&', $qs) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $k = urldecode($k);
            $v = urldecode($v);
            if (str_ends_with($k, '[]')) {
                $k = substr($k, 0, -2);
            }
            if ($k === '') {
                continue;
            }
            $out[$k][] = $v;
        }

        return $out;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Last value of a query parameter. */
    public function queryValue(string $name): ?string
    {
        $values = $this->query[$name] ?? [];

        return $values === [] ? null : $values[count($values) - 1];
    }

    public function isApi(): bool
    {
        return $this->path === '/api/v1' || str_starts_with($this->path, '/api/v1/');
    }

    /** Request target as the client sent it, for problem "instance" fields. */
    public function target(): string
    {
        return $this->basePath . $this->path . ($this->rawQuery !== '' ? '?' . $this->rawQuery : '');
    }

    public function url(): Url
    {
        return new Url($this->basePath, $this->publicUrl);
    }
}

<?php

declare(strict_types=1);

namespace Datasets;

/**
 * Base-path-aware URL builder.
 *
 *   $u = new Url('/datasets', 'https://endtime-instruments.org/datasets', '/path/to/public');
 *   $u->path('/d/open-meteo')           // "/datasets/d/open-meteo"
 *   $u->absolute('/api/v1/datasets')    // "https://endtime-instruments.org/datasets/api/v1/datasets"
 *   $u->asset('assets/style.css')       // "/datasets/assets/style.css?v=1731337200"
 *
 * The global helpers url(), abs_url() and asset() (src/helpers.php) delegate to Url::current().
 */
final class Url
{
    private static ?self $current = null;

    /**
     * @param string $basePath  "" or "/prefix" (no trailing slash)
     * @param string $publicUrl absolute URL of the app root, no trailing slash
     */
    public function __construct(
        public readonly string $basePath,
        public readonly string $publicUrl,
        public readonly ?string $publicDir = null,
    ) {
    }

    public static function setCurrent(self $url): void
    {
        self::$current = $url;
    }

    public static function current(): self
    {
        return self::$current ??= new self('', 'http://localhost');
    }

    /** Normalise a base path: "" or "/a/b" (leading slash, no trailing slash). */
    public static function normaliseBasePath(string $path): string
    {
        $path = trim($path);
        $path = '/' . trim($path, '/');

        return $path === '/' ? '' : $path;
    }

    /**
     * Root-relative URL for an app path, e.g. path('/d/x', ['tab' => 'api']).
     *
     * @param array<string, scalar|list<scalar>|null> $query
     */
    public function path(string $path = '/', array $query = []): string
    {
        $path = '/' . ltrim($path, '/');
        $out = $this->basePath . $path;

        return $out . self::queryString($query);
    }

    /**
     * Absolute public URL for an app path.
     *
     * @param array<string, scalar|list<scalar>|null> $query
     */
    public function absolute(string $path = '/', array $query = []): string
    {
        $path = '/' . ltrim($path, '/');

        return $this->publicUrl . ($path === '/' ? '/' : $path) . self::queryString($query);
    }

    /** Root-relative URL of a file in public/, cache-busted with its mtime when it exists. */
    public function asset(string $file): string
    {
        $file = ltrim($file, '/');
        $url = $this->basePath . '/' . $file;
        if ($this->publicDir !== null) {
            $mtime = @filemtime($this->publicDir . '/' . $file);
            if ($mtime !== false) {
                $url .= '?v=' . $mtime;
            }
        }

        return $url;
    }

    /**
     * Build "?a=1&b=2" (empty string when nothing to add). Null values are skipped,
     * list values are joined with commas.
     *
     * @param array<string, scalar|list<scalar>|null> $query
     */
    public static function queryString(array $query): string
    {
        $parts = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === [] || $value === '') {
                continue;
            }
            if (is_array($value)) {
                $value = implode(',', array_map('strval', $value));
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $parts[] = rawurlencode((string) $key) . '=' . str_replace('%2C', ',', rawurlencode((string) $value));
        }

        return $parts === [] ? '' : '?' . implode('&', $parts);
    }
}

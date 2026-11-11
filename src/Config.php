<?php

declare(strict_types=1);

namespace Datasets;

/**
 * Filesystem locations and public-URL settings, resolved from the environment.
 *
 * Environment variables (read from getenv(), $_SERVER and Apache's REDIRECT_ copies):
 *   APP_DATASETS_DIR  directory holding <slug>.json files (default: <root>/datasets)
 *   APP_CACHE_FILE    compiled catalogue (default: <root>/var/catalog.json)
 *   APP_BASE_PATH     URL path prefix the app is mounted under, e.g. "/free-datasets" (default: detected from SCRIPT_NAME)
 *   APP_PUBLIC_URL    absolute public URL of the app root, e.g. "https://endtime-instruments.org/free-datasets"
 *                     (default: request scheme + host + base path)
 *   APP_VAR_DIR       writable runtime state: explorer rate-limit counters and response cache
 *                     (default: <root>/var; outside the web root)
 */
final class Config
{
    public function __construct(
        public readonly string $rootDir,
        public readonly string $datasetsDir,
        public readonly string $schemaDir,
        public readonly string $templatesDir,
        public readonly string $publicDir,
        public readonly string $cacheFile,
        public readonly string $varDir = '',
    ) {
    }

    /** Paths only (for CLI scripts and tests). */
    public static function fromEnvironment(?string $rootDir = null): self
    {
        $root = $rootDir ?? dirname(__DIR__);

        return new self(
            rootDir: $root,
            datasetsDir: rtrim(self::env('APP_DATASETS_DIR') ?? $root . '/datasets', '/'),
            schemaDir: $root . '/schema',
            templatesDir: rtrim(self::env('APP_TEMPLATES_DIR') ?? $root . '/templates', '/'),
            publicDir: $root . '/public',
            cacheFile: self::env('APP_CACHE_FILE') ?? $root . '/var/catalog.json',
            varDir: rtrim(self::env('APP_VAR_DIR') ?? $root . '/var', '/'),
        );
    }

    /** Read a setting from the process environment or the web server's variables. */
    public static function env(string $name): ?string
    {
        foreach ([$name, 'REDIRECT_' . $name, 'REDIRECT_REDIRECT_' . $name] as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace Datasets\Http;

use Datasets\Config;
use Datasets\Url;

/**
 * Static-file support for PHP's built-in server (`php -S host:port -t public public/index.php`).
 * Apache serves files itself (see public/.htaccess); this is only used when PHP_SAPI is cli-server.
 */
final class DevServer
{
    private const TYPES = [
        'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8', 'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'txt' => 'text/plain; charset=utf-8',
        'xml' => 'application/xml', 'webmanifest' => 'application/manifest+json', 'html' => 'text/html; charset=utf-8',
    ];

    /**
     * Decide how to handle the current request under the built-in server.
     * Returns true when the built-in server should serve the file itself (router returns false).
     * When the app runs under APP_BASE_PATH, files below that prefix are streamed directly and
     * the process exits. Otherwise returns false and the app handles the request.
     */
    public static function handleStatic(string $publicDir): bool
    {
        $path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
        $file = self::resolve($publicDir, $path);
        if ($file !== null) {
            return true;
        }
        $base = Config::env('APP_BASE_PATH');
        if ($base !== null) {
            $base = Url::normaliseBasePath($base);
            if ($base !== '' && str_starts_with($path, $base . '/')) {
                $file = self::resolve($publicDir, substr($path, strlen($base)));
                if ($file !== null) {
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    header('Content-Type: ' . (self::TYPES[$ext] ?? 'application/octet-stream'));
                    header('Content-Length: ' . (string) filesize($file));
                    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
                        readfile($file);
                    }
                    exit;
                }
            }
        }

        return false;
    }

    /** A servable regular file inside public/ (no PHP, no dotfiles, no traversal), or null. */
    public static function resolve(string $publicDir, string $path): ?string
    {
        if ($path === '' || $path === '/' || str_contains($path, "\0")) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment !== '' && $segment[0] === '.') {
                return null;
            }
        }
        if (preg_match('/\.php$/i', $path) === 1) {
            return null;
        }
        $root = realpath($publicDir);
        $file = realpath($publicDir . $path);
        if ($root === false || $file === false || !is_file($file) || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $file;
    }
}

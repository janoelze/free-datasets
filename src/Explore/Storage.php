<?php

declare(strict_types=1);

namespace Datasets\Explore;

/**
 * Runtime state of the explorer under var/ (outside the web root): directories are created on
 * demand, readable by the owner only. Also the short-lived response cache.
 */
final class Storage
{
    public const CACHE_TTL = 30;

    public const CACHE_MAX_FILES = 64;

    public const CACHE_MAX_BYTES = 16 * 1024 * 1024;

    public function __construct(private readonly string $cacheDir)
    {
    }

    public static function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new Refusal(503, 'Explorer unavailable', 'The explorer cannot store its state on this server.');
        }
        @chmod($dir, 0700);
    }

    /** @return array<string, mixed>|null a cached result younger than CACHE_TTL */
    public function get(string $key): ?array
    {
        $file = $this->file($key);
        $mtime = @filemtime($file);
        if ($mtime === false || $mtime < time() - self::CACHE_TTL) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $result */
    public function put(string $key, array $result): void
    {
        self::ensureDir($this->cacheDir);
        $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return;
        }
        $tmp = $this->file($key) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            return;
        }
        @chmod($tmp, 0600);
        @rename($tmp, $this->file($key));
        $this->prune();
    }

    /** Drop expired entries, then the oldest until the count and size caps hold. */
    public function prune(): void
    {
        $files = [];
        foreach (glob($this->cacheDir . '/*.json') ?: [] as $f) {
            $mtime = @filemtime($f);
            if ($mtime === false) {
                continue;
            }
            if ($mtime < time() - self::CACHE_TTL) {
                @unlink($f);
                continue;
            }
            $files[$f] = [$mtime, (int) @filesize($f)];
        }
        foreach (glob($this->cacheDir . '/*.tmp') ?: [] as $f) {
            if ((int) @filemtime($f) < time() - 60) {
                @unlink($f);
            }
        }
        uasort($files, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $bytes = array_sum(array_column($files, 1));
        $count = count($files);
        foreach ($files as $f => [, $size]) {
            if ($count <= self::CACHE_MAX_FILES && $bytes <= self::CACHE_MAX_BYTES) {
                break;
            }
            @unlink($f);
            $count--;
            $bytes -= $size;
        }
    }

    private function file(string $key): string
    {
        return $this->cacheDir . '/' . hash('sha256', $key) . '.json';
    }
}

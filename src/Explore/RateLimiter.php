<?php

declare(strict_types=1);

namespace Datasets\Explore;

/**
 * Fixed-window rate limits for the explorer proxy, stored as tiny counter files under
 * var/ratelimit/ and updated under flock. Limits: per client per minute and per day, plus a
 * global per-minute ceiling across all clients. Clients are identified by an HMAC of their
 * address (IPv6 by its /64, which one subscriber usually controls) with a random per-install
 * salt, so no raw addresses are stored.
 */
final class RateLimiter
{
    public function __construct(
        private readonly string $dir,
        private readonly int $perMinute = 20,
        private readonly int $perDay = 200,
        private readonly int $globalPerMinute = 300,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * Count one request from $ip, or refuse with 429 (and Retry-After) when a limit is reached.
     *
     * @throws Refusal
     */
    public function hit(string $ip): void
    {
        Storage::ensureDir($this->dir);
        $now = $this->clock !== null ? (int) ($this->clock)() : time();
        $minute = intdiv($now, 60);
        $day = gmdate('Ymd', $now);
        $client = substr(hash_hmac('sha256', self::clientKey($ip), $this->salt()), 0, 24);

        $counters = [
            ['g-' . $minute, $this->globalPerMinute, 60 - $now % 60, 'The explorer is busy right now (site-wide limit). Try again in a minute.'],
            ['d-' . $day . '-' . $client, $this->perDay, 86400 - $now % 86400, sprintf('You have reached the explorer limit of %d requests per day.', $this->perDay)],
            ['m-' . $minute . '-' . $client, $this->perMinute, 60 - $now % 60, sprintf('You have reached the explorer limit of %d requests per minute.', $this->perMinute)],
        ];

        $handles = [];
        try {
            foreach ($counters as $i => [$name]) {
                $path = $this->dir . '/' . $name;
                $fh = @fopen($path, 'c+');
                if ($fh === false || !flock($fh, LOCK_EX)) {
                    throw new Refusal(503, 'Explorer unavailable', 'The rate limiter could not record this request.');
                }
                if (fstat($fh)['size'] === 0) {
                    @chmod($path, 0600);
                }
                $handles[$i] = $fh;
            }
            $counts = [];
            foreach ($counters as $i => [, $limit, $retry, $message]) {
                $counts[$i] = (int) stream_get_contents($handles[$i], -1, 0);
                if ($counts[$i] >= $limit) {
                    throw new Refusal(429, 'Too many requests', $message, ['Retry-After' => (string) max(1, $retry)]);
                }
            }
            foreach ($handles as $i => $fh) {
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, (string) ($counts[$i] + 1));
                fflush($fh);
            }
        } finally {
            foreach ($handles as $fh) {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }

        if (random_int(1, 50) === 1) {
            $this->cleanup($minute, $day);
        }
    }

    /** Delete counters of past windows. */
    public function cleanup(int $minute, string $day): void
    {
        foreach (glob($this->dir . '/[gmd]-*') ?: [] as $file) {
            $parts = explode('-', basename($file));
            $stale = $parts[0] === 'd' ? $parts[1] < $day : (int) $parts[1] < $minute - 1;
            if ($stale) {
                @unlink($file);
            }
        }
    }

    /** Normalised client identity: the IPv4 address, or the /64 of an IPv6 address. */
    public static function clientKey(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return 'unknown';
        }
        if (strlen($bin) === 16) {
            return bin2hex(substr($bin, 0, 8)) . '::/64';
        }

        return (string) inet_ntop($bin);
    }

    private function salt(): string
    {
        $file = $this->dir . '/.salt';
        $salt = @file_get_contents($file);
        if (is_string($salt) && strlen($salt) >= 32) {
            return $salt;
        }
        $fh = @fopen($file, 'x');
        if ($fh !== false) {
            $salt = bin2hex(random_bytes(32));
            fwrite($fh, $salt);
            fclose($fh);
            @chmod($file, 0600);

            return $salt;
        }
        usleep(10000); // another request is creating it

        return (string) @file_get_contents($file);
    }
}

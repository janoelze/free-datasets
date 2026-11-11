<?php

declare(strict_types=1);

namespace Datasets\Explore;

/**
 * The outbound request of the explorer proxy.
 *
 * For every hop: resolve the host, refuse if any address is not public, pin curl to the vetted
 * address (CURLOPT_RESOLVE, so DNS cannot change between check and use), https only, TLS
 * verified, no proxy, no cookies, only the headers Policy::upstreamHeaders() lists. Redirects
 * are followed by hand (at most MAX_REDIRECTS), each target re-validated against the record's
 * allowlist; a redirect elsewhere is returned as the final response. Bodies are capped at
 * MAX_BODY by aborting the transfer, and binary bodies are summarised, not returned.
 */
final class Fetcher
{
    public const MAX_BODY = 512 * 1024;

    public const MAX_HEADER_BYTES = 64 * 1024;

    public const CONNECT_TIMEOUT = 5;

    public const TOTAL_TIMEOUT = 12;

    public const MAX_REDIRECTS = 3;

    /** Response headers passed back to the browser (plus any rate-limit header). */
    public const RETURNED_HEADERS = ['content-type', 'content-length', 'location', 'retry-after', 'cache-control', 'etag', 'last-modified'];

    private const REASONS = [
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content', 206 => 'Partial Content',
        301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified',
        307 => 'Temporary Redirect', 308 => 'Permanent Redirect', 400 => 'Bad Request', 401 => 'Unauthorized',
        403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable',
        408 => 'Request Timeout', 409 => 'Conflict', 410 => 'Gone', 413 => 'Content Too Large',
        415 => 'Unsupported Media Type', 422 => 'Unprocessable Content', 429 => 'Too Many Requests',
        500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway',
        503 => 'Service Unavailable', 504 => 'Gateway Timeout',
    ];

    /**
     * @param (\Closure(string): list<string>)|null $resolver host -> IP addresses (default: DNS A + AAAA)
     * @param bool $testMode accept http:// and loopback addresses (local test upstream only)
     */
    public function __construct(private readonly ?\Closure $resolver = null, private readonly bool $testMode = false)
    {
    }

    /**
     * @param array<string, list<int>> $allowed the record's allowlist, re-checked on every redirect
     * @return array<string, mixed> the proxy's JSON result
     * @throws Refusal
     */
    public function fetch(Target $target, array $allowed, string $method, ?string $body, Policy $policy): array
    {
        $start = microtime(true);
        $redirects = [];
        $note = null;
        $current = $target;
        for ($hop = 0; ; $hop++) {
            $ips = $this->vet($current->host);
            $remaining = self::TOTAL_TIMEOUT - (microtime(true) - $start);
            if ($remaining < 0.5) {
                throw new Refusal(504, 'Upstream timed out', sprintf('No complete response within %d seconds.', self::TOTAL_TIMEOUT));
            }
            $r = $this->request($current, $ips, $method, $body, $policy->upstreamHeaders($method), $remaining);
            $location = $r['headers']['location'] ?? null;
            if (!in_array($r['status'], [301, 302, 303, 307, 308], true) || $location === null) {
                break;
            }
            $next = self::resolveLocation($current, $location);
            try {
                $nextTarget = Target::parse($next, $allowed, $this->testMode);
            } catch (Refusal $e) {
                $note = 'Redirect not followed: ' . $e->getMessage();
                break;
            }
            if ($hop >= self::MAX_REDIRECTS) {
                $note = sprintf('Redirect not followed: stopped after %d redirects.', self::MAX_REDIRECTS);
                break;
            }
            $redirects[] = ['status' => $r['status'], 'url' => $current->url(), 'location' => $nextTarget->url()];
            if ($r['status'] === 303 || ($method === 'POST' && in_array($r['status'], [301, 302], true))) {
                $method = 'GET';
                $body = null;
            }
            $current = $nextTarget;
        }

        $contentType = $r['headers']['content-type'] ?? '';
        $text = self::textBody($r['body'], $contentType, $r['truncated']);
        if ($text === null) {
            $note = trim(($note ?? '') . ' ' . sprintf('Binary response (%s, %s bytes%s) not shown.', $contentType !== '' ? $contentType : 'no content type', number_format(strlen($r['body'])), $r['truncated'] ? ' or more' : ''));
        }

        return [
            'status' => $r['status'],
            'status_text' => $r['reason'] !== '' ? $r['reason'] : (self::REASONS[$r['status']] ?? ''),
            // Bodies are decoded; a Content-Length of the compressed bytes would only mislead.
            'headers' => self::returnedHeaders(isset($r['headers']['content-encoding']) ? array_diff_key($r['headers'], ['content-length' => 1]) : $r['headers']),
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'size' => strlen($r['body']),
            'truncated' => $r['truncated'],
            'body' => $text,
            'content_type' => $contentType,
            'final_url' => $current->url(),
            'redirects' => $redirects,
            'cached' => false,
        ] + ($note !== null ? ['note' => $note] : []);
    }

    /**
     * Resolve the host and return its addresses, all of them vetted (curl is pinned to exactly
     * these and picks between IPv4 and IPv6 itself).
     *
     * @return list<string>
     * @throws Refusal when it does not resolve or ANY address is not public
     */
    public function vet(string $host): array
    {
        $ips = $this->resolver !== null ? ($this->resolver)($host) : self::dns($host);
        if ($ips === []) {
            throw new Refusal(502, 'Could not resolve host', sprintf('%s did not resolve.', $host));
        }
        foreach ($ips as $ip) {
            if (!Target::isPublicIp($ip) && !($this->testMode && Target::isLoopback($ip))) {
                throw new Refusal(403, 'Address not allowed', sprintf('%s resolves to a private, local or reserved address; the explorer does not connect to those.', $host));
            }
        }

        return array_values(array_unique(array_map(static fn (string $ip): string => (string) inet_ntop((string) inet_pton($ip)), $ips)));
    }

    /** @return list<string> */
    public static function dns(string $host): array
    {
        $ips = [];
        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $field) {
            foreach (@dns_get_record($host, $type) ?: [] as $rec) {
                if (isset($rec[$field]) && is_string($rec[$field])) {
                    $ips[] = $rec[$field];
                }
            }
        }
        if ($ips === []) {
            $ips = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($ips));
    }

    /** Absolute URL for a Location header value relative to $base (unsafe values come back unchanged and fail Target::parse). */
    public static function resolveLocation(Target $base, string $location): string
    {
        $location = trim($location);
        if (preg_match('~^[A-Za-z][A-Za-z0-9+.-]*:~', $location) === 1) {
            return $location;
        }
        $origin = substr($base->url(), 0, strlen($base->url()) - strlen($base->pathAndQuery));
        if (str_starts_with($location, '//')) {
            return $base->scheme . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = explode('?', $base->pathAndQuery, 2)[0];
        if (str_starts_with($location, '?')) {
            return $origin . $path . $location;
        }

        return $origin . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
    }

    /**
     * One request, no redirects.
     *
     * @param list<string> $ips vetted addresses to pin the connection to
     * @param list<string> $headers
     * @return array{status: int, reason: string, headers: array<string, string>, body: string, truncated: bool}
     * @throws Refusal
     */
    private function request(Target $t, array $ips, string $method, ?string $body, array $headers, float $timeout): array
    {
        $respHeaders = [];
        $reason = '';
        $headerBytes = 0;
        $buf = '';
        $truncated = false;
        $headersTooBig = false;

        $ch = curl_init();
        $protocols = $this->testMode ? 'http,https' : 'https';
        $opts = [
            CURLOPT_URL => $t->url(),
            CURLOPT_RESOLVE => [$t->host . ':' . $t->port . ':' . implode(',', array_map(static fn (string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip, $ips))],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT_MS => (int) (min(self::CONNECT_TIMEOUT, $timeout) * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '', // accept compression; the cap applies to the decoded bytes
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders, &$reason, &$headerBytes, &$headersTooBig): int {
                $headerBytes += strlen($line);
                if ($headerBytes > self::MAX_HEADER_BYTES) {
                    $headersTooBig = true;

                    return 0;
                }
                if (preg_match('~^HTTP/\S+\s+\d{3}\s*(.*)$~', rtrim($line), $m) === 1) {
                    $respHeaders = []; // a new response (after 100 Continue)
                    $reason = substr(preg_replace('/[^\x20-\x7e]/', '', $m[1]) ?? '', 0, 80);
                } elseif (($p = strpos($line, ':')) !== false) {
                    $name = strtolower(trim(substr($line, 0, $p)));
                    $value = trim(substr($line, $p + 1));
                    $respHeaders[$name] = isset($respHeaders[$name]) ? $respHeaders[$name] . ', ' . $value : $value;
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buf, &$truncated): int {
                $room = self::MAX_BODY - strlen($buf);
                if (strlen($chunk) > $room) {
                    $buf .= substr($chunk, 0, max(0, $room));
                    $truncated = true;

                    return 0; // abort the transfer
                }
                $buf .= $chunk;

                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $opts[CURLOPT_PROTOCOLS_STR] = $protocols;
            $opts[CURLOPT_REDIR_PROTOCOLS_STR] = $protocols;
        } else {
            $opts[CURLOPT_PROTOCOLS] = $this->testMode ? CURLPROTO_HTTPS | CURLPROTO_HTTP : CURLPROTO_HTTPS;
            $opts[CURLOPT_REDIR_PROTOCOLS] = $opts[CURLOPT_PROTOCOLS];
        }
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = (string) $body;
        } else {
            $opts[CURLOPT_HTTPGET] = true;
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($headersTooBig) {
            throw new Refusal(502, 'Upstream response refused', 'The response headers were unreasonably large.');
        }
        if ($errno !== 0 && !($truncated && $errno === CURLE_WRITE_ERROR)) {
            throw self::curlRefusal($errno);
        }
        if ($status < 100) {
            throw new Refusal(502, 'Upstream request failed', 'No valid HTTP response.');
        }

        return ['status' => $status, 'reason' => $reason, 'headers' => $respHeaders, 'body' => $buf, 'truncated' => $truncated];
    }

    private static function curlRefusal(int $errno): Refusal
    {
        return match (true) {
            $errno === CURLE_OPERATION_TIMEDOUT => new Refusal(504, 'Upstream timed out', sprintf('No complete response within %d seconds (connect timeout %d s).', self::TOTAL_TIMEOUT, self::CONNECT_TIMEOUT)),
            $errno === CURLE_COULDNT_CONNECT => new Refusal(502, 'Could not connect', 'The API server refused or dropped the connection.'),
            in_array($errno, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91], true) => new Refusal(502, 'TLS error', 'The secure connection to the API failed: ' . curl_strerror($errno) . '.'),
            in_array($errno, [52, 55, 56, 92], true) => new Refusal(502, 'Upstream connection broke', 'The API closed the connection without a complete response.'),
            $errno === CURLE_UNSUPPORTED_PROTOCOL => new Refusal(400, 'Only https URLs', 'The explorer only requests https:// URLs.'),
            default => new Refusal(502, 'Upstream request failed', (string) curl_strerror($errno) . '.'),
        };
    }

    /** The body as text, or null when it is binary or not UTF-8. */
    public static function textBody(string $body, string $contentType, bool $truncated): ?string
    {
        if (preg_match('~^\s*(image|audio|video|font)/|^\s*application/(octet-stream|pdf|zip|gzip|x-gzip|x-tar|x-protobuf|protobuf|vnd\.apache\.parquet|x-parquet|wasm)\b~i', $contentType) === 1) {
            return null;
        }
        if ($truncated) {
            // The cut may split a multi-byte character.
            for ($i = 0; $i < 3 && $body !== '' && preg_match('//u', $body) !== 1; $i++) {
                $body = substr($body, 0, -1);
            }
        }
        if (str_contains($body, "\0") || preg_match('//u', $body) !== 1) {
            return null;
        }

        return $body;
    }

    /** @param array<string, string> $headers @return array<string, string> */
    public static function returnedHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (in_array($name, self::RETURNED_HEADERS, true) || preg_match('/^(x-ratelimit|ratelimit|x-rate-limit)/', $name) === 1) {
                $out[$name] = substr(preg_replace('/[^\x20-\x7e]/', '', $value) ?? '', 0, 500);
            }
            if (count($out) >= 20) {
                break;
            }
        }
        ksort($out);

        return $out;
    }
}

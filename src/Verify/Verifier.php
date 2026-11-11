<?php

declare(strict_types=1);

namespace Datasets\Verify;

use Datasets\Json;

/**
 * Executes dataset `example` requests. Shared by bin/verify.php, bin/curate-select.php and
 * bin/curate-guard.php so they all send the same request for a record.
 *
 * - 15 s timeout per request, up to 8 requests in parallel.
 * - Sends example.headers, api.required_headers (their example values) and USER_AGENT
 *   (an explicit User-Agent in example.headers wins).
 * - classify(): 2xx = "ok"; a non-2xx status is "expected" for records recorded with
 *   verification.with_key = false whose observed status equals verification.http_status
 *   (keyed APIs checked without a key); anything else is "fail".
 */
final class Verifier
{
    public const USER_AGENT = 'datasets-library-verify/1.0 (+https://endtime-instruments.org/free-datasets/)';

    public const TIMEOUT = 15;

    public const PARALLEL = 8;

    /** @var (callable(string): string)|null */
    private $rewrite;

    /**
     * @param bool $httpsOnly refuse http:// for the request and every redirect (guard mode)
     * @param (callable(string): string)|null $rewrite maps the example URL to the URL actually
     *        requested (tests point records at a local upstream with it)
     */
    public function __construct(
        private readonly bool $httpsOnly = false,
        ?callable $rewrite = null,
        private readonly int $timeout = self::TIMEOUT,
        private readonly int $parallel = self::PARALLEL,
    ) {
        $this->rewrite = $rewrite;
    }

    /**
     * Run the example request of each record.
     *
     * @param array<string, object|null> $records key => record decoded with json_decode($json, false)
     *        (null = unreadable)
     * @return array<string, array{status: int, error: string, duration_ms: int}> same keys, same order;
     *         status 0 when no HTTP response was received
     */
    public function run(array $records): array
    {
        $queue = array_keys($records);
        $running = [];
        $results = [];
        $mh = curl_multi_init();
        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < $this->parallel) {
                $key = array_shift($queue);
                $ch = $this->prepare($records[$key]);
                if (is_string($ch)) {
                    $results[$key] = ['status' => 0, 'error' => $ch, 'duration_ms' => 0];
                    continue;
                }
                curl_multi_add_handle($mh, $ch);
                $running[spl_object_id($ch)] = ['key' => $key, 'ch' => $ch];
            }
            curl_multi_exec($mh, $active);
            if (curl_multi_select($mh, 1.0) === -1) {
                usleep(10000);
            }
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $job = $running[spl_object_id($ch)];
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $error = $info['result'] === CURLE_OK ? '' : curl_error($ch);
                $results[$job['key']] = [
                    'status' => $error === '' ? $status : 0,
                    'error' => $error,
                    'duration_ms' => (int) round(((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000),
                ];
                curl_multi_remove_handle($mh, $ch);
                unset($running[spl_object_id($ch)]);
            }
        }
        curl_multi_close($mh);

        $ordered = [];
        foreach (array_keys($records) as $key) {
            $ordered[$key] = $results[$key];
        }

        return $ordered;
    }

    /** "ok", "expected" or "fail" for an observed status (0 = no response). */
    public static function classify(?object $d, int $status): string
    {
        if ($status >= 200 && $status < 300) {
            return 'ok';
        }
        if ($status > 0 && $d !== null
            && ($d->verification->with_key ?? true) === false
            && (int) ($d->verification->http_status ?? 0) === $status) {
            return 'expected';
        }

        return 'fail';
    }

    /**
     * Fingerprint of what determines the request (example + required headers), so a report
     * entry can be matched against a later version of the record.
     */
    public static function fingerprint(object $d): string
    {
        return hash('sha256', json_encode([$d->example ?? null, $d->api->required_headers ?? null], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Set verification.last_verified and verification.http_status in a dataset file,
     * preserving key order and the canonical file format.
     *
     * @throws \RuntimeException when the file has no verification object
     */
    public static function writeVerification(string $file, string $today, int $status): void
    {
        $doc = json_decode((string) file_get_contents($file), false);
        if (!is_object($doc) || !isset($doc->verification) || !is_object($doc->verification)) {
            throw new \RuntimeException('cannot update (no verification object)');
        }
        $doc->verification->last_verified = $today;
        $doc->verification->http_status = $status;
        file_put_contents($file, Json::encodeFile($doc));
    }

    /** Read a record (json_decode(..., false)); null when unreadable. */
    public static function load(string $file): ?object
    {
        $json = @file_get_contents($file);
        $d = is_string($json) ? json_decode($json, false) : null;

        return is_object($d) ? $d : null;
    }

    private function prepare(?object $d): \CurlHandle|string
    {
        if ($d === null || !isset($d->example->url) || !is_string($d->example->url)) {
            return 'no readable example.url';
        }
        $url = $this->rewrite !== null ? ($this->rewrite)($d->example->url) : $d->example->url;
        if ($this->httpsOnly && stripos($url, 'https://') !== 0) {
            return 'example.url is not https';
        }
        $headers = [];
        foreach ((array) ($d->example->headers ?? []) as $name => $value) {
            $headers[strtolower((string) $name)] = $name . ': ' . $value;
        }
        foreach ((array) ($d->api->required_headers ?? []) as $h) {
            $name = (string) ($h->name ?? '');
            if ($name !== '' && isset($h->example) && !isset($headers[strtolower($name)])) {
                $headers[strtolower($name)] = $name . ': ' . $h->example;
            }
        }
        if (!isset($headers['user-agent'])) {
            $headers['user-agent'] = 'User-Agent: ' . self::USER_AGENT;
        }
        $protocols = $this->httpsOnly ? CURLPROTO_HTTPS : (CURLPROTO_HTTPS | CURLPROTO_HTTP);
        $ch = curl_init($url);
        $method = strtoupper((string) ($d->example->method ?? 'GET'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => array_values($headers),
            CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => $protocols,
            CURLOPT_REDIR_PROTOCOLS => $protocols,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) ($d->example->body ?? ''));
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        return $ch;
    }
}

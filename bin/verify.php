<?php

declare(strict_types=1);

/*
 * Execute each dataset's `example` request and report the HTTP status.
 *
 *   php bin/verify.php [slug...] [--write] [--quiet]
 *
 * - 15 s timeout per request, up to 8 requests in parallel.
 * - Sends example.headers, api.required_headers (their example values) and
 *   User-Agent: datasets-library-verify/1.0 (+https://endtime-instruments.org/datasets/)
 *   (an explicit User-Agent in example.headers wins).
 * - 2xx = OK. Non-2xx counts as a failure, except for entries recorded with
 *   verification.with_key = false whose observed status equals verification.http_status
 *   (keyed APIs checked without a key: the expected 401/403 is reported as "expected").
 * - --write updates verification.last_verified (today) and verification.http_status in each file
 *   that got an HTTP response, preserving key order, 2-space indent and a trailing newline.
 * Exit status 1 when any request fails.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Datasets\Config;
use Datasets\Json;
use Datasets\Schema\Linter;

const USER_AGENT = 'datasets-library-verify/1.0 (+https://endtime-instruments.org/datasets/)';
const TIMEOUT = 15;
const PARALLEL = 8;

if (!extension_loaded('curl')) {
    fwrite(STDERR, "The curl extension is required.\n");
    exit(2);
}

$write = false;
$quiet = false;
$slugs = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--write') {
        $write = true;
    } elseif ($arg === '--quiet' || $arg === '-q') {
        $quiet = true;
    } elseif (str_starts_with($arg, '-')) {
        fwrite(STDERR, "Unknown option $arg\nUsage: php bin/verify.php [slug...] [--write] [--quiet]\n");
        exit(2);
    } else {
        $slugs[] = basename($arg, '.json');
    }
}

$config = Config::fromEnvironment();
$files = [];
if ($slugs === []) {
    $files = Linter::datasetFiles($config->datasetsDir);
} else {
    foreach ($slugs as $slug) {
        $f = $config->datasetsDir . '/' . $slug . '.json';
        if (!is_file($f)) {
            fwrite(STDERR, "$slug: no such dataset ($f)\n");
            exit(2);
        }
        $files[] = $f;
    }
}

/** @return array{0: CurlHandle, 1: object}|string */
function prepare(string $file): array|string
{
    $json = @file_get_contents($file);
    $d = is_string($json) ? json_decode($json, false) : null;
    if (!is_object($d) || !isset($d->example->url) || !is_string($d->example->url)) {
        return 'no readable example.url';
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
        $headers['user-agent'] = 'User-Agent: ' . USER_AGENT;
    }
    $ch = curl_init($d->example->url);
    $method = strtoupper((string) ($d->example->method ?? 'GET'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => TIMEOUT,
        CURLOPT_HTTPHEADER => array_values($headers),
        CURLOPT_ENCODING => '',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, (string) ($d->example->body ?? ''));
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }

    return [$ch, $d];
}

$queue = $files;
$running = [];
$results = [];
$mh = curl_multi_init();
while ($queue !== [] || $running !== []) {
    while ($queue !== [] && count($running) < PARALLEL) {
        $file = array_shift($queue);
        $prep = prepare($file);
        if (is_string($prep)) {
            $results[$file] = ['status' => 0, 'error' => $prep, 'data' => null];
            continue;
        }
        [$ch, $d] = $prep;
        curl_multi_add_handle($mh, $ch);
        $running[(int) spl_object_id($ch)] = ['file' => $file, 'ch' => $ch, 'data' => $d];
    }
    curl_multi_exec($mh, $active);
    if (curl_multi_select($mh, 1.0) === -1) {
        usleep(10000);
    }
    while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle'];
        $job = $running[(int) spl_object_id($ch)];
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $info['result'] === CURLE_OK ? '' : curl_error($ch);
        $results[$job['file']] = ['status' => $error === '' ? $status : 0, 'error' => $error, 'data' => $job['data']];
        curl_multi_remove_handle($mh, $ch);
        unset($running[(int) spl_object_id($ch)]);
    }
}
curl_multi_close($mh);

$failed = 0;
$today = date('Y-m-d');
foreach ($files as $file) {
    $r = $results[$file];
    $slug = basename($file, '.json');
    $d = $r['data'];
    $status = $r['status'];
    $ok = $status >= 200 && $status < 300;
    $expected = !$ok && $status > 0 && $d !== null
        && ($d->verification->with_key ?? true) === false
        && (int) ($d->verification->http_status ?? 0) === $status;
    if ($ok) {
        $label = 'OK';
    } elseif ($expected) {
        $label = 'EXPECTED (no key)';
    } else {
        $label = 'FAIL';
        $failed++;
    }
    if (!$quiet || $label === 'FAIL') {
        fwrite(STDOUT, sprintf("%-40s %s %s%s\n", $slug, $status === 0 ? '---' : (string) $status, $label, $r['error'] !== '' ? ' (' . $r['error'] . ')' : ''));
    }

    if ($write && $status > 0 && $d !== null) {
        $doc = json_decode((string) file_get_contents($file), false);
        if (!is_object($doc) || !isset($doc->verification) || !is_object($doc->verification)) {
            fwrite(STDERR, "$slug: cannot update (no verification object)\n");
            continue;
        }
        $doc->verification->last_verified = $today;
        $doc->verification->http_status = $status;
        file_put_contents($file, Json::encodeFile($doc));
    }
}

if (!$quiet) {
    fwrite(STDOUT, sprintf("%d checked, %d failed%s\n", count($files), $failed, $write ? ' (files updated)' : ''));
}
exit($failed === 0 ? 0 : 1);

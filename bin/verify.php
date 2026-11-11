<?php

declare(strict_types=1);

/*
 * Execute each dataset's `example` request and report the HTTP status.
 *
 *   php bin/verify.php [slug...] [--write] [--quiet]
 *
 * - Requests are sent by Datasets\\Verify\\Verifier: 15 s timeout, up to 8 in parallel,
 *   example.headers + api.required_headers + User-Agent: datasets-library-verify/1.0
 *   (+https://endtime-instruments.org/free-datasets/) (an explicit User-Agent in example.headers wins).
 * - 2xx = OK. Non-2xx counts as a failure, except for entries recorded with
 *   verification.with_key = false whose observed status equals verification.http_status
 *   (keyed APIs checked without a key: the expected 401/403 is reported as "expected").
 * - --write updates verification.last_verified (today) and verification.http_status in each file
 *   that got an HTTP response, preserving key order, 2-space indent and a trailing newline.
 * Exit status 1 when any request fails.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Datasets\Config;
use Datasets\Schema\Linter;
use Datasets\Verify\Verifier;

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

$records = [];
foreach ($files as $file) {
    $records[$file] = Verifier::load($file);
}
$results = (new Verifier())->run($records);

$failed = 0;
$today = date('Y-m-d');
foreach ($files as $file) {
    $r = $results[$file];
    $slug = basename($file, '.json');
    $d = $records[$file];
    $status = $r['status'];
    $label = ['ok' => 'OK', 'expected' => 'EXPECTED (no key)', 'fail' => 'FAIL'][Verifier::classify($d, $status)];
    if ($label === 'FAIL') {
        $failed++;
    }
    if (!$quiet || $label === 'FAIL') {
        fwrite(STDOUT, sprintf("%-40s %s %s%s\n", $slug, $status === 0 ? '---' : (string) $status, $label, $r['error'] !== '' ? ' (' . $r['error'] . ')' : ''));
    }

    if ($write && $status > 0 && $d !== null) {
        try {
            Verifier::writeVerification($file, $today, $status);
        } catch (RuntimeException $e) {
            fwrite(STDERR, "$slug: " . $e->getMessage() . "\n");
        }
    }
}

if (!$quiet) {
    fwrite(STDOUT, sprintf("%d checked, %d failed%s\n", count($files), $failed, $write ? ' (files updated)' : ''));
}
exit($failed === 0 ? 0 : 1);

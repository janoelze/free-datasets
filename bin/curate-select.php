<?php

declare(strict_types=1);

/*
 * Deterministic half of the scheduled curation run (.github/workflows/curate.yml).
 *
 *   php bin/curate-select.php [--count=30] [--report=FILE] [--retry=SECONDS] [--write] [--date=YYYY-MM-DD]
 *   php bin/curate-select.php --apply=FILE
 *
 * Select mode picks the N records with the oldest verification.last_verified (ties broken
 * by slug; records without a date come first), executes their example requests with the
 * same request logic as bin/verify.php (Datasets\Verify\Verifier) and writes a JSON report:
 *
 *   {"date": "...", "generated_at": "...", "count": N, "retry_seconds": S,
 *    "totals": {"ok": n, "expected": n, "fail": n},
 *    "results": [{"slug", "result" (ok|expected|fail), "http_status" (observed, 0 = no response),
 *                 "expected_status" (recorded verification.http_status), "with_key", "error",
 *                 "duration_ms", "attempts", "first_attempt" {http_status, error} (only if retried),
 *                 "previous_last_verified", "method", "url", "fingerprint"}]}
 *
 * --retry=S re-runs failures once after S seconds. --write sets last_verified (today or --date)
 * and http_status in the files of passing records only (ok or expected); failing records are
 * never touched, so their last good verification stays visible.
 *
 * Apply mode does the --write step later, from a report: it updates every passing entry whose
 * record still exists and still has the same example (fingerprint). The publish job uses it so
 * the agent never has to (and cannot usefully) edit verification fields of passing records.
 *
 * Exit status: 0 (also when some requests fail; the report says which), 2 on usage errors.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Datasets\Config;
use Datasets\Schema\Linter;
use Datasets\Verify\Verifier;

$opts = ['count' => 30, 'report' => null, 'retry' => 0, 'write' => false, 'date' => null, 'apply' => null];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--write') {
        $opts['write'] = true;
    } elseif (preg_match('/^--(count|retry)=(\d+)$/', $arg, $m) === 1) {
        $opts[$m[1]] = (int) $m[2];
    } elseif (preg_match('/^--(report|apply)=(.+)$/', $arg, $m) === 1) {
        $opts[$m[1]] = $m[2];
    } elseif (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m) === 1) {
        $opts['date'] = $m[1];
    } else {
        fwrite(STDERR, "Unknown argument $arg\nUsage: php bin/curate-select.php [--count=N] [--report=FILE] [--retry=SECONDS] [--write] [--date=YYYY-MM-DD]\n       php bin/curate-select.php --apply=REPORT\n");
        exit(2);
    }
}

if (!extension_loaded('curl')) {
    fwrite(STDERR, "The curl extension is required.\n");
    exit(2);
}

$config = Config::fromEnvironment();
$dir = $config->datasetsDir;

if ($opts['apply'] !== null) {
    $report = json_decode((string) @file_get_contents($opts['apply']), true);
    if (!is_array($report) || !is_array($report['results'] ?? null) || !is_string($report['date'] ?? null)) {
        fwrite(STDERR, "{$opts['apply']}: not a curate-select report\n");
        exit(2);
    }
    $updated = [];
    $skipped = [];
    foreach ($report['results'] as $r) {
        $slug = (string) ($r['slug'] ?? '');
        if (!in_array($r['result'] ?? '', ['ok', 'expected'], true) || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
            continue;
        }
        $file = "$dir/$slug.json";
        $d = is_file($file) ? Verifier::load($file) : null;
        if ($d === null || Verifier::fingerprint($d) !== ($r['fingerprint'] ?? '')) {
            $skipped[] = $slug;
            continue;
        }
        try {
            Verifier::writeVerification($file, $report['date'], (int) $r['http_status']);
            $updated[] = $slug;
        } catch (RuntimeException $e) {
            $skipped[] = $slug;
        }
    }
    fwrite(STDOUT, sprintf("Applied %d verification update%s from %s.%s\n", count($updated), count($updated) === 1 ? '' : 's', $opts['apply'], $skipped === [] ? '' : ' Skipped (removed or example changed): ' . implode(', ', $skipped)));
    exit(0);
}

$today = $opts['date'] ?? date('Y-m-d');

// Oldest verification first; ties (and equal dates) by slug.
$candidates = [];
foreach (Linter::datasetFiles($dir) as $file) {
    $d = Verifier::load($file);
    $candidates[] = ['slug' => basename($file, '.json'), 'file' => $file, 'data' => $d, 'last' => is_string($d->verification->last_verified ?? null) ? $d->verification->last_verified : ''];
}
usort($candidates, static fn (array $a, array $b): int => [$a['last'], $a['slug']] <=> [$b['last'], $b['slug']]);
$selected = array_slice($candidates, 0, $opts['count']);

$records = [];
foreach ($selected as $c) {
    $records[$c['slug']] = $c['data'];
}
$verifier = new Verifier();
$results = $verifier->run($records);
$attempts = array_fill_keys(array_keys($results), 1);
$first = [];
$retry = [];
foreach ($results as $slug => $r) {
    if (Verifier::classify($records[$slug], $r['status']) === 'fail' && $records[$slug] !== null) {
        $retry[$slug] = $records[$slug];
    }
}
if ($retry !== [] && $opts['retry'] > 0) {
    sleep($opts['retry']);
    foreach ($verifier->run($retry) as $slug => $r) {
        $first[$slug] = ['http_status' => $results[$slug]['status'], 'error' => $results[$slug]['error']];
        $results[$slug] = $r;
        $attempts[$slug] = 2;
    }
}

$out = [];
$totals = ['ok' => 0, 'expected' => 0, 'fail' => 0];
foreach ($selected as $c) {
    $slug = $c['slug'];
    $d = $c['data'];
    $r = $results[$slug];
    $result = Verifier::classify($d, $r['status']);
    $totals[$result]++;
    $entry = [
        'slug' => $slug,
        'result' => $result,
        'http_status' => $r['status'],
        'expected_status' => isset($d->verification->http_status) ? (int) $d->verification->http_status : null,
        'with_key' => ($d->verification->with_key ?? true) !== false,
        'error' => $r['error'],
        'duration_ms' => $r['duration_ms'],
        'attempts' => $attempts[$slug],
    ];
    if (isset($first[$slug])) {
        $entry['first_attempt'] = $first[$slug];
    }
    $entry += [
        'previous_last_verified' => $c['last'] === '' ? null : $c['last'],
        'method' => strtoupper((string) ($d->example->method ?? 'GET')),
        'url' => is_string($d->example->url ?? null) ? $d->example->url : null,
        'fingerprint' => $d !== null ? Verifier::fingerprint($d) : null,
    ];
    $out[] = $entry;

    fwrite(STDOUT, sprintf("%-40s %-10s %s %s%s%s\n", $slug, $c['last'] ?: '-', $r['status'] === 0 ? '---' : (string) $r['status'], strtoupper($result), $r['error'] !== '' ? ' (' . $r['error'] . ')' : '', $attempts[$slug] > 1 ? ' [retried]' : ''));

    if ($opts['write'] && $result !== 'fail') {
        try {
            Verifier::writeVerification($c['file'], $today, $r['status']);
        } catch (RuntimeException $e) {
            fwrite(STDERR, "$slug: " . $e->getMessage() . "\n");
        }
    }
}

$report = [
    'date' => $today,
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'count' => count($out),
    'retry_seconds' => $opts['retry'],
    'totals' => $totals,
    'results' => $out,
];
if ($opts['report'] !== null) {
    file_put_contents($opts['report'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}
fwrite(STDOUT, sprintf("%d checked: %d ok, %d expected, %d failing%s%s\n", count($out), $totals['ok'], $totals['expected'], $totals['fail'], $opts['write'] ? ' (passing records updated)' : '', $opts['report'] !== null ? "; report: {$opts['report']}" : ''));
exit(0);

<?php

declare(strict_types=1);

/*
 * Decide whether a curation agent's changes may be published (see Datasets\Curate\Guard for
 * the full list of checks). Applies the patch to the work tree (git apply --index) only after
 * its paths were found to be allowed data files.
 *
 *   php bin/curate-guard.php --patch=FILE --verify-report=FILE [--report=curation-report.md]
 *       [--repo=DIR] [--base=HEAD] [--max-added=3] [--max-removed=2] [--max-modified=10]
 *       [--site-host=endtime-instruments.org] [--retry=10] [--date=YYYY-MM-DD] [--run-url=URL]
 *       [--summary-json=FILE] [--pr-body=FILE]
 *
 * Prints the guard summary (Markdown). --summary-json writes
 *   {"passed", "paths_ok", "applied", "title", "errors", "added", "modified", "removed",
 *    "verification_only", "backlog_changed", "executed", "reverified"}
 * and --pr-body the PR body: the sanitised agent report, the guard summary and the run link
 * (the workflow appends the result of the full checks).
 * Exit status: 0 passed, 1 failed (summary-json says whether the paths were acceptable), 2 usage.
 *
 * Test hook: CURATE_GUARD_TEST_UPSTREAM=http://127.0.0.1:PORT sends every example request to
 * that local server (path and query kept) and skips DNS checks. Never set it in CI.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Datasets\Curate\Guard;

$o = [
    'patch' => null, 'verify-report' => null, 'report' => null, 'repo' => getcwd(), 'base' => 'HEAD',
    'max-added' => '3', 'max-removed' => '2', 'max-modified' => '10', 'site-host' => 'endtime-instruments.org',
    'retry' => '10', 'date' => gmdate('Y-m-d'), 'run-url' => null, 'summary-json' => null, 'pr-body' => null,
];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/s', $arg, $m) === 1 && array_key_exists($m[1], $o)) {
        $o[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "Unknown argument $arg (see the header of bin/curate-guard.php)\n");
        exit(2);
    }
}
foreach (['patch', 'verify-report'] as $req) {
    if ($o[$req] === null || $o[$req] === '') {
        fwrite(STDERR, "--$req is required\n");
        exit(2);
    }
}
foreach (['max-added', 'max-removed', 'max-modified', 'retry'] as $n) {
    if (preg_match('/^\d+$/', (string) $o[$n]) !== 1) {
        fwrite(STDERR, "--$n must be a non-negative integer\n");
        exit(2);
    }
}

$verify = json_decode((string) @file_get_contents((string) $o['verify-report']), true);
if (!is_array($verify) || !is_array($verify['results'] ?? null)) {
    fwrite(STDERR, "{$o['verify-report']}: not a curate-select report\n");
    exit(2);
}

$guard = new Guard(dirname(__DIR__), (string) realpath((string) $o['repo']), (string) $o['base'], [
    'max_added' => (int) $o['max-added'],
    'max_removed' => (int) $o['max-removed'],
    'max_modified' => (int) $o['max-modified'],
    'site_host' => (string) $o['site-host'],
    'retry_seconds' => (int) $o['retry'],
    'test_upstream' => getenv('CURATE_GUARD_TEST_UPSTREAM') ?: null,
]);
$passed = $guard->run((string) $o['patch'], $verify, $o['report']);
$summary = $guard->summaryMarkdown();
fwrite(STDOUT, $summary);

$title = $guard->title((string) $o['date']);
if ($o['summary-json'] !== null) {
    file_put_contents((string) $o['summary-json'], json_encode([
        'passed' => $passed,
        'paths_ok' => $guard->pathsOk,
        'applied' => $guard->applied,
        'title' => $title,
        'errors' => $guard->errors,
    ] + $guard->stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}
if ($o['pr-body'] !== null) {
    $report = $o['report'] !== null && is_file((string) $o['report']) ? (string) file_get_contents((string) $o['report']) : "_The agent did not write a report._\n";
    $body = Guard::sanitizeReport($report) . "\n\n---\n\n" . $summary . "\n";
    if ($o['run-url']) {
        $body .= "Curation run: {$o['run-url']}\n";
    }
    file_put_contents((string) $o['pr-body'], $body);
}
exit($passed ? 0 : 1);

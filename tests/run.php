<?php

declare(strict_types=1);

/*
 * Dependency-free test runner.
 *
 *   php tests/run.php [filter]
 *
 * Runs every test registered by tests/*Test.php (optionally only those whose name contains
 * `filter`). Exit status 1 when any test fails. HTTP tests start `php -S` on free ports
 * against tests/fixtures/datasets (via APP_DATASETS_DIR).
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/lib.php';

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    require $file;
}

$filter = $argv[1] ?? '';
$passed = 0;
$failed = [];
$start = microtime(true);
foreach ($GLOBALS['__tests'] as $name => $fn) {
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    try {
        $fn();
        $passed++;
        fwrite(STDOUT, "  ok    $name\n");
    } catch (Throwable $e) {
        $where = $e instanceof AssertionFailed ? '' : ' [' . $e::class . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
        $trace = '';
        foreach ($e->getTrace() as $frame) {
            if (isset($frame['file']) && str_ends_with($frame['file'], 'Test.php')) {
                $trace = ' (' . basename($frame['file']) . ':' . $frame['line'] . ')';
                break;
            }
        }
        $failed[] = $name;
        fwrite(STDOUT, "  FAIL  $name$trace\n        " . $e->getMessage() . "$where\n");
    }
}

fwrite(STDOUT, sprintf("\n%d passed, %d failed (%.1fs)\n", $passed, count($failed), microtime(true) - $start));
exit($failed === [] ? 0 : 1);

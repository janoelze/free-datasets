<?php

declare(strict_types=1);

/*
 * Validate dataset files against schema/dataset.schema.json and the semantic rules.
 *
 *   php bin/validate.php [--quiet] [--dir=PATH] [FILE...]
 *
 * Default directory: datasets/ (or $APP_DATASETS_DIR). With FILE arguments only those files are
 * checked (`related` is still resolved against the directory they live in).
 * Prints "file: /json/pointer: message" per error. Exit status 1 when any error is found.
 * --quiet prints only errors (no summary line).
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Datasets\Config;
use Datasets\Schema\Linter;

$quiet = false;
$dir = null;
$files = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--quiet' || $arg === '-q') {
        $quiet = true;
    } elseif (str_starts_with($arg, '--dir=')) {
        $dir = substr($arg, 6);
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php bin/validate.php [--quiet] [--dir=PATH] [FILE...]\n");
        exit(0);
    } elseif (str_starts_with($arg, '-')) {
        fwrite(STDERR, "Unknown option $arg\n");
        exit(2);
    } else {
        $files[] = $arg;
    }
}

$config = Config::fromEnvironment();
$dir ??= $config->datasetsDir;
$linter = Linter::fromSchemaDir($config->schemaDir);

if ($files === []) {
    if (!is_dir($dir)) {
        fwrite(STDERR, "No such directory: $dir\n");
        exit(2);
    }
    $results = $linter->lintDirectory($dir);
} else {
    $results = [];
    foreach ($files as $f) {
        $known = [];
        foreach (Linter::datasetFiles(dirname($f)) as $sibling) {
            $known[basename($sibling, '.json')] = true;
        }
        $results[$f] = $linter->lintFile($f, $known);
    }
}

$errorCount = 0;
$badFiles = 0;
$cwd = getcwd() ?: '';
foreach ($results as $file => $result) {
    if ($result['errors'] === []) {
        continue;
    }
    $badFiles++;
    $shown = ($cwd !== '' && str_starts_with($file, $cwd . '/')) ? substr($file, strlen($cwd) + 1) : $file;
    foreach ($result['errors'] as $error) {
        $errorCount++;
        fwrite(STDOUT, $shown . ': ' . $error->displayPointer() . ': ' . $error->message . "\n");
    }
}

if (!$quiet) {
    $n = count($results);
    fwrite(STDOUT, $errorCount === 0
        ? sprintf("OK: %d file%s valid.\n", $n, $n === 1 ? '' : 's')
        : sprintf("FAIL: %d error%s in %d of %d file%s.\n", $errorCount, $errorCount === 1 ? '' : 's', $badFiles, $n, $n === 1 ? '' : 's'));
}

exit($errorCount === 0 ? 0 : 1);

<?php

declare(strict_types=1);

/*
 * Validate every dataset, then write the compiled catalogue to var/catalog.json
 * (or $APP_CACHE_FILE), sorted by slug. Nothing is written when any file is invalid.
 *
 *   php bin/build.php [--quiet]
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Datasets\Catalog;
use Datasets\Config;
use Datasets\Schema\Linter;

$quiet = in_array('--quiet', $argv, true) || in_array('-q', $argv, true);
$config = Config::fromEnvironment();
$linter = Linter::fromSchemaDir($config->schemaDir);
$results = $linter->lintDirectory($config->datasetsDir);

$datasets = [];
$errors = 0;
foreach ($results as $file => $result) {
    foreach ($result['errors'] as $error) {
        $errors++;
        fwrite(STDERR, basename(dirname($file)) . '/' . basename($file) . ': ' . $error->displayPointer() . ': ' . $error->message . "\n");
    }
    if ($result['errors'] === [] && $result['data'] !== null) {
        $datasets[] = $result['data'];
    }
}

if ($errors > 0) {
    fwrite(STDERR, sprintf("Build aborted: %d error%s. %s was not written.\n", $errors, $errors === 1 ? '' : 's', $config->cacheFile));
    exit(1);
}

Catalog::writeCache($config->cacheFile, $config->datasetsDir, $datasets);
if (!$quiet) {
    fwrite(STDOUT, sprintf("Wrote %d dataset%s to %s\n", count($datasets), count($datasets) === 1 ? '' : 's', $config->cacheFile));
}
exit(0);

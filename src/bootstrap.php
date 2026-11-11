<?php

declare(strict_types=1);

/*
 * Bootstrap: PSR-4-style autoloader for the Datasets\ namespace (mapped to src/)
 * plus the global template helpers. No composer, no dependencies.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Datasets\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once __DIR__ . '/helpers.php';

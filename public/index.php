<?php

declare(strict_types=1);

// Front controller. Apache rewrites every non-file request here (see .htaccess);
// for local development: php -S localhost:8000 -t public public/index.php

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI === 'cli-server' && Datasets\Http\DevServer::handleStatic(__DIR__)) {
    return false; // let the built-in server serve the static file
}

Datasets\App::fromGlobals()->run();

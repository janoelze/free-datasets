<?php

declare(strict_types=1);

// Fake upstream API for tests/CurateTest.php, served by `php -S 127.0.0.1:PORT this-file`.
// Paths ending in /status/NNN answer with that status; every other path answers 200.

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

header('Content-Type: application/json');
if (preg_match('~/status/(\d{3})$~', $path, $m) === 1) {
    http_response_code((int) $m[1]);
    echo json_encode(['status' => (int) $m[1]]);
} else {
    echo json_encode(['ok' => true, 'path' => $path, 'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '']);
}

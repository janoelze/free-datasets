<?php

declare(strict_types=1);

// Fake upstream API for tests/ExploreTest.php, served by `php -S 127.0.0.1:PORT this-file`.
// The explorer reaches it as http://up.test:PORT (test mode resolves *.test to 127.0.0.1).

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$port = (int) ($_SERVER['SERVER_PORT'] ?? 0);

switch ($path) {
    case '/json':
        header('Content-Type: application/json');
        header('X-RateLimit-Remaining: 41');
        header('Set-Cookie: secret=1');
        header('X-Internal: hidden');
        echo json_encode(['ok' => true, 'n' => random_int(1, PHP_INT_MAX)]);
        break;
    case '/echo':
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with((string) $k, 'HTTP_') || in_array($k, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[strtolower(str_replace('_', '-', preg_replace('/^HTTP_/', '', (string) $k)))] = $v;
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'query' => $_SERVER['QUERY_STRING'] ?? '', 'headers' => $headers, 'body' => file_get_contents('php://input')]);
        break;
    case '/big':
        header('Content-Type: text/plain; charset=utf-8');
        $line = str_repeat('é', 50) . "\n"; // multi-byte, so the cut can split a character
        for ($i = 0; $i < 12000; $i++) {
            echo $line;
        }
        break;
    case '/binary':
        header('Content-Type: image/png');
        echo "\x89PNG\r\n\x1a\n" . random_bytes(200);
        break;
    case '/to-other':
        header('Location: http://other.test:' . $port . '/json', true, 302);
        break;
    case '/to-relative':
        header('Location: /json', true, 301);
        break;
    case '/to-evil':
        header('Location: https://evil.example/steal', true, 302);
        break;
    case '/to-private':
        header('Location: http://private.test:' . $port . '/json', true, 307);
        break;
    case '/loop':
        header('Location: /loop', true, 302);
        break;
    case '/status/503':
        http_response_code(503);
        header('Content-Type: application/json');
        echo '{"error":"busy"}';
        break;
    default:
        http_response_code(404);
        echo 'not found';
}

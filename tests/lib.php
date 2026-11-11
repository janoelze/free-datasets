<?php

declare(strict_types=1);

/*
 * Minimal, dependency-free test helpers. Tests register with test(); run.php executes them.
 */

final class AssertionFailed extends \RuntimeException
{
}

/** @var array<string, callable> */
$GLOBALS['__tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][$name] = $fn;
}

function fail(string $message): never
{
    throw new AssertionFailed($message);
}

function show(mixed $v): string
{
    $s = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

    return $s === false ? var_export($v, true) : (strlen($s) > 400 ? substr($s, 0, 400) . '…' : $s);
}

function assert_true(mixed $cond, string $message = 'expected true'): void
{
    if ($cond !== true) {
        fail($message . ' (got ' . show($cond) . ')');
    }
}

function assert_false(mixed $cond, string $message = 'expected false'): void
{
    if ($cond !== false) {
        fail($message . ' (got ' . show($cond) . ')');
    }
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        fail(($message !== '' ? $message . ': ' : '') . 'expected ' . show($expected) . ', got ' . show($actual));
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        fail(($message !== '' ? $message . ': ' : '') . 'expected to find ' . show($needle) . ' in ' . show($haystack));
    }
}

function assert_throws(string $class, callable $fn, string $message = ''): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        fail(($message !== '' ? $message . ': ' : '') . "expected $class, got " . $e::class . ': ' . $e->getMessage());
    }
    fail(($message !== '' ? $message . ': ' : '') . "expected $class to be thrown");
}

/**
 * Assert an error list contains one with the pointer (and keyword, if given).
 *
 * @param list<Datasets\Schema\ValidationError> $errors
 */
function assert_has_error(array $errors, string $pointer, ?string $keyword = null, string $message = ''): void
{
    foreach ($errors as $e) {
        if ($e->pointer === $pointer && ($keyword === null || $e->keyword === $keyword)) {
            return;
        }
    }
    fail(($message !== '' ? $message . ': ' : '') . "expected error at $pointer" . ($keyword ? " ($keyword)" : '') . ', got ' . show(array_map('strval', $errors)));
}

/** Recursively deep-copy a json_decode(..., false) value. */
function deep_copy(mixed $v): mixed
{
    return json_decode(json_encode($v, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), false);
}

function fixtures(): string
{
    return __DIR__ . '/fixtures';
}

function fixture(string $slug, bool $assoc = false): mixed
{
    return json_decode((string) file_get_contents(fixtures() . "/datasets/$slug.json"), $assoc, 512, JSON_THROW_ON_ERROR);
}

/** Fresh temporary directory, removed at shutdown. */
function temp_dir(): string
{
    $dir = sys_get_temp_dir() . '/datasets-test-' . bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    register_shutdown_function(static function () use ($dir): void {
        rm_rf($dir);
    });

    return $dir;
}

function rm_rf(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                rm_rf("$path/$f");
            }
        }
        @rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        @unlink($path);
    }
}

/**
 * Run a PHP script; returns [exit code, stdout, stderr].
 *
 * @param list<string> $args
 * @param array<string, string> $env extra environment
 * @return array{0: int, 1: string, 2: string}
 */
function run_php(string $script, array $args = [], array $env = [], ?string $cwd = null): array
{
    $cmd = array_merge([PHP_BINARY, $script], $args);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd ?? dirname(__DIR__), array_merge(getenv(), $env));
    if (!is_resource($proc)) {
        fail("cannot run $script");
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    return [$code, (string) $out, (string) $err];
}

/** A dev server on a free port; stopped at shutdown. */
final class TestServer
{
    /** @var resource */
    private $proc;

    public readonly string $base;

    /** @param array<string, string> $env */
    public function __construct(array $env)
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            fail("no free port: $errstr");
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);
        $root = dirname(__DIR__);
        $log = sys_get_temp_dir() . "/datasets-test-server-$port.log";
        $proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$root/public", "$root/public/index.php"],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $root,
            array_merge(getenv(), $env)
        );
        if (!is_resource($proc)) {
            fail('cannot start php -S');
        }
        $this->proc = $proc;
        $this->base = "http://127.0.0.1:$port";
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
            if ($c !== false) {
                fclose($c);
                register_shutdown_function([$this, 'stop']);

                return;
            }
            usleep(50000);
        }
        $this->stop();
        fail("php -S did not start on port $port; see $log");
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
    }
}

/**
 * HTTP request with the curl extension.
 *
 * @param list<string> $headers "Name: value"
 * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
 */
function http(string $method, string $url, array $headers = []): array
{
    $ch = curl_init($url);
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
            $p = strpos($line, ':');
            if ($p !== false) {
                $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            }

            return strlen($line);
        },
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        fail("request to $url failed: " . curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    return ['status' => $status, 'headers' => $respHeaders, 'body' => (string) $body, 'json' => json_decode((string) $body, true)];
}

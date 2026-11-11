<?php

declare(strict_types=1);

use Datasets\Explore\ExploreController;
use Datasets\Explore\Fetcher;
use Datasets\Explore\Policy;
use Datasets\Explore\RateLimiter;
use Datasets\Explore\Refusal;
use Datasets\Explore\Target;

/*
 * The explorer proxy (POST /explore). Unit tests run the URL validator and IP classifier with no
 * relaxation; HTTP tests run the app under php -S with APP_EXPLORE_TEST_MODE=1 against a local
 * fake upstream (tests/fixtures/explore-upstream.php) reached as http://up.test:PORT.
 */

// ------------------------------------------------------------------ unit: URL validation

const X_ALLOWED = ['allowed.host' => [443], 'api.example.org' => [443, 8443]];

function refusal_of(callable $fn): Refusal
{
    return assert_throws(Refusal::class, $fn);
}

test('explore: Target::parse refuses hostile URLs', function () {
    $cases = [
        'https://allowed.host@evil.com/' => 400,              // credentials: the real host is evil.com
        'https://user:pass@allowed.host/' => 400,
        'https://evil.com/?x=allowed.host' => 403,
        'https://evil.com/allowed.host' => 403,
        'https://allowed.host.evil.com/' => 403,               // no subdomain/suffix matching
        'https://sub.allowed.host/' => 403,
        'http://allowed.host/' => 400,                          // https only
        'ftp://allowed.host/' => 400,
        'javascript:alert(1)' => 400,
        '//allowed.host/' => 400,
        'https:allowed.host/' => 400,
        'https://allowed.host:22/' => 403,                      // port not used by the record
        'https://allowed.host:8443/' => 403,                    // that port belongs to another host
        'https://allowed.host:99999/' => 400,
        'https://allowed.host:/' => 400,
        'https://127.0.0.1/' => 403,
        'https://169.254.169.254/latest/meta-data/' => 403,
        'https://100.64.0.1/' => 403,
        'https://0.0.0.0/' => 403,
        'https://2130706433/' => 403,                           // decimal 127.0.0.1
        'https://0177.0.0.1/' => 403,                           // octal
        'https://0x7f.0.0.1/' => 403,                           // hex
        'https://[::1]/' => 400,
        'https://[::ffff:10.0.0.1]/' => 400,
        'https://[fc00::1]/' => 400,
        'https://[fe80::1%25en0]/' => 400,
        'https://allowed.host\\@evil.com/' => 400,              // backslash
        'https://allowed.host\\.evil.com/' => 400,
        "https://allowed.host/\r\nHost: evil.com" => 400,       // CR/LF
        "https://allowed.host/\n" => 400,
        "https://allowed.host/\0" => 400,
        'https://allowed.host/a b' => 400,                      // space
        "https://allowed.host/\u{00e9}" => 400,                 // raw non-ASCII
        'https://allowed.host%2eevil.com/' => 400,              // percent in host
        'https://allowed.host./' => 400,                        // trailing dot
        'https://allowed..host/' => 403,
        'https://' => 400,
        '' => 400,
        'https://allowed.host/' . str_repeat('a', 2001) => 400,
    ];
    foreach ($cases as $url => $status) {
        $e = refusal_of(fn () => Target::parse($url, X_ALLOWED));
        assert_same($status, $e->status, 'status for ' . json_encode($url) . ' (' . $e->getMessage() . ')');
    }
});

test('explore: Target::parse accepts and rebuilds allowed URLs', function () {
    $cases = [
        'https://ALLOWED.host/' => 'https://allowed.host/',      // case-insensitive host
        'https://allowed.host' => 'https://allowed.host/',
        'https://allowed.host?q=1' => 'https://allowed.host/?q=1',
        'https://allowed.host:443/x' => 'https://allowed.host/x',
        'HTTPS://allowed.host/x' => 'https://allowed.host/x',
        'https://api.example.org:8443/v1?q=a%20b&x=allowed.host' => 'https://api.example.org:8443/v1?q=a%20b&x=allowed.host',
        'https://allowed.host/a?b=c#frag' => 'https://allowed.host/a?b=c',
        'https://allowed.host#@evil.com/' => 'https://allowed.host/',  // the fragment is dropped, never a host
        'https://allowed.host/p?@evil.com' => 'https://allowed.host/p?@evil.com',
    ];
    foreach ($cases as $in => $out) {
        assert_same($out, Target::parse($in, X_ALLOWED)->url(), $in);
    }
    assert_same('http://allowed.host:8080/x', Target::parse('http://allowed.host:8080/x', ['allowed.host' => [443, 8080]], true)->url(), 'test mode http');
});

test('explore: allowed hosts come from base_url, example and absolute endpoints only', function () {
    $d = fixture('open-meteo', true);
    $d['links']['docs'] = 'https://docs.evil.example/';
    assert_same(
        ['api.open-meteo.com' => [443], 'archive-api.open-meteo.com' => [443]],
        Target::allowedHosts($d)
    );
    $d['api']['base_url'] = 'https://API.Example.org:8443/v1';
    assert_same([443, 8443], Target::allowedHosts($d)['api.example.org']);
});

test('explore: IP classifier', function () {
    $blocked = [
        '127.0.0.1', '127.255.255.254', '10.0.0.1', '172.16.5.4', '172.31.255.255', '192.168.1.1', '169.254.169.254',
        '100.64.0.1', '100.127.255.255', '0.0.0.0', '0.1.2.3', '255.255.255.255', '224.0.0.1', '239.1.1.1', '240.0.0.1',
        '198.18.0.1', '192.0.2.1', '198.51.100.7', '203.0.113.9', '192.0.0.8', '192.88.99.1',
        '::', '::1', '::ffff:10.0.0.1', '::ffff:127.0.0.1', '::ffff:8.8.8.8', '::10.0.0.1', '64:ff9b::a00:1', '64:ff9b:1::1',
        'fc00::1', 'fd12:3456::1', 'fe80::1', 'febf::1', 'fec0::1', 'ff02::1', '100::1', '2001:db8::1', '2002:a00:1::1',
        '2001:0:4136:e378::1', '3fff::1',
        '2130706433', '0177.0.0.1', '0x7f.0.0.1', '127.1', 'localhost', '', 'garbage', '1.2.3.4.5',
    ];
    foreach ($blocked as $ip) {
        assert_false(Target::isPublicIp($ip), "blocked: $ip");
    }
    foreach (['8.8.8.8', '1.1.1.1', '100.63.255.255', '100.128.0.1', '172.32.0.1', '93.184.216.34', '2606:4700:4700::1111', '2a00:1450:4001:82a::200e'] as $ip) {
        assert_true(Target::isPublicIp($ip), "public: $ip");
    }
    assert_true(Target::isLoopback('127.0.0.1'));
    assert_true(Target::isLoopback('::1'));
    assert_false(Target::isLoopback('10.0.0.1'));
});

test('explore: resolver refuses a host when ANY address is not public', function () {
    $f = new Fetcher(static fn (string $h): array => ['93.184.216.34', '10.0.0.1']);
    $e = refusal_of(fn () => $f->vet('allowed.host'));
    assert_same(403, $e->status);
    $f = new Fetcher(static fn (string $h): array => ['2606:4700:4700::1111', '93.184.216.34']);
    assert_same(['2606:4700:4700::1111', '93.184.216.34'], $f->vet('allowed.host'), 'every vetted address is pinned');
    assert_same(502, refusal_of(fn () => (new Fetcher(static fn (string $h): array => []))->vet('x'))->status);
    assert_same(403, refusal_of(fn () => (new Fetcher(static fn (string $h): array => ['127.0.0.1']))->vet('x'))->status, 'loopback only in test mode');
    assert_same(['127.0.0.1'], (new Fetcher(static fn (string $h): array => ['127.0.0.1'], true))->vet('x'));
    assert_same(403, refusal_of(fn () => (new Fetcher(static fn (string $h): array => ['10.0.0.1'], true))->vet('x'))->status, 'test mode still refuses private ranges');
});

test('explore: redirect Location resolution', function () {
    $t = Target::parse('https://allowed.host/a/b?c=1', X_ALLOWED);
    assert_same('https://allowed.host/x', Fetcher::resolveLocation($t, '/x'));
    assert_same('https://allowed.host/a/y', Fetcher::resolveLocation($t, 'y'));
    assert_same('https://allowed.host/a/b?d=2', Fetcher::resolveLocation($t, '?d=2'));
    assert_same('https://evil.com/', Fetcher::resolveLocation($t, '//evil.com/'));
    assert_same('https://evil.com/', Fetcher::resolveLocation($t, 'https://evil.com/'));
});

test('explore: text bodies, binary detection, returned headers', function () {
    assert_same('{"a":1}', Fetcher::textBody('{"a":1}', 'application/json', false));
    assert_same(null, Fetcher::textBody('abc', 'image/png', false));
    assert_same(null, Fetcher::textBody("a\0b", 'text/plain', false));
    assert_same(null, Fetcher::textBody("\xff\xfe", 'text/plain', false));
    assert_same('ab', Fetcher::textBody("ab\xc3", 'text/plain', true), 'a character split by truncation is dropped');
    assert_same(
        ['content-type' => 'text/plain', 'ratelimit-policy' => '10;w=60', 'x-rate-limit-reset' => '5', 'x-ratelimit-remaining' => '3'],
        Fetcher::returnedHeaders(['content-type' => 'text/plain', 'set-cookie' => 'a=b', 'x-ratelimit-remaining' => '3', 'ratelimit-policy' => '10;w=60', 'x-rate-limit-reset' => '5', 'server' => 'x'])
    );
});

// ------------------------------------------------------------------ unit: policy

test('explore: policy decides proxy eligibility, methods and headers', function () {
    $d = fixture('open-meteo', true);
    $p = new Policy($d);
    assert_same('proxy', $p->mode());
    assert_same(['GET'], $p->methods());
    assert_true($p->directPossible('GET'));
    assert_false($p->directPossible('POST'));

    $keyed = $d;
    $keyed['api']['auth'] = ['type' => 'api_key', 'key_in' => 'query', 'key_name' => 'api_key'];
    assert_true(str_contains((string) (new Policy($keyed))->proxyRefusal(), 'own free key'));
    assert_same('direct', (new Policy($keyed))->mode(), 'CORS allows browser-only use');
    $keyed['api']['cors'] = 'none';
    assert_same('off', (new Policy($keyed))->mode());
    $keyed['api']['auth']['type'] = 'oauth2';
    assert_same('off', (new Policy($keyed))->mode());

    $demo = $d;
    $demo['api']['auth'] = ['type' => 'api_key', 'key_in' => 'query', 'key_name' => 'api_key', 'demo_key' => 'DEMO_KEY'];
    $pd = new Policy($demo);
    assert_same(null, $pd->proxyRefusal());
    assert_false($pd->personalKeyIn('https://h/x?api_key=DEMO_KEY'));
    assert_false($pd->personalKeyIn('https://h/x?a=1'));
    assert_true($pd->personalKeyIn('https://h/x?api_key=abc123'));
    assert_true($pd->personalKeyIn('https://h/x?API_KEY=abc123&api_key=DEMO_KEY'));

    $dep = $d;
    $dep['status'] = 'deprecated';
    assert_same('off', (new Policy($dep))->mode());
    assert_true(str_contains((string) (new Policy($dep))->proxyRefusal(), 'deprecated'));

    $post = $d;
    $post['endpoints'][] = ['method' => 'POST', 'path' => '/q', 'summary' => 'query'];
    $post['example']['headers'] = ['User-Agent' => 'MyApp/1.0 (you@example.com)', 'Authorization' => 'Bearer x', 'Cookie' => 'a=b', 'Accept' => 'text/csv', 'X-Bad' => "a\r\nInjected: 1"];
    $post['api']['required_headers'] = [['name' => 'X-Client', 'example' => 'demo']];
    $pp = new Policy($post);
    assert_same(['GET', 'POST'], $pp->methods());
    assert_same([
        'User-Agent: ' . Policy::USER_AGENT, 'Accept: text/csv', 'X-Client: demo',
        'Content-Type: application/x-www-form-urlencoded', 'Expect:',
    ], $pp->upstreamHeaders('POST'));
    assert_same(['User-Agent: ' . Policy::USER_AGENT, 'Accept: ' . Policy::DEFAULT_ACCEPT, 'Expect:'], $p->upstreamHeaders('GET'));
    $post['example']['headers'] = ['Content-Type' => 'application/json'];
    assert_same('application/json', (new Policy($post))->postContentType());
});

test('explore: test mode is never honoured outside the built-in server', function () {
    putenv('APP_EXPLORE_TEST_MODE=1');
    try {
        assert_false(ExploreController::testMode(), 'PHP_SAPI is ' . PHP_SAPI);
    } finally {
        putenv('APP_EXPLORE_TEST_MODE');
    }
});

// ------------------------------------------------------------------ unit: rate limiter

test('explore: rate limiter windows, hashing and 429', function () {
    $dir = temp_dir() . '/ratelimit';
    $now = 1_800_000_000 + 15; // 15 s into a minute
    $rl = new RateLimiter($dir, 20, 200, 300, function () use (&$now): int {
        return $now;
    });
    for ($i = 0; $i < 20; $i++) {
        $rl->hit('203.0.113.7');
    }
    $e = refusal_of(fn () => $rl->hit('203.0.113.7'));
    assert_same(429, $e->status);
    assert_same('45', $e->headers['Retry-After']);
    $rl->hit('198.51.100.1'); // other clients are unaffected

    // IPv6 clients share a /64
    for ($i = 0; $i < 20; $i++) {
        $rl->hit('2001:4860:1:2:' . dechex($i) . '::1');
    }
    assert_same(429, refusal_of(fn () => $rl->hit('2001:4860:1:2:ffff::1'))->status);
    $rl->hit('2001:4860:1:3::1');

    $now += 60; // next window
    $rl->hit('203.0.113.7');

    foreach (glob($dir . '/*') ?: [] as $f) {
        assert_false(str_contains(basename($f), '203.0.113') || str_contains((string) file_get_contents($f), '203.0.113'), 'no raw IP stored in ' . basename($f));
    }
    assert_same('0700', substr(sprintf('%o', fileperms($dir)), -4), 'directory mode');
    foreach (glob($dir . '/*') ?: [] as $f) {
        assert_same('0600', substr(sprintf('%o', fileperms($f)), -4), 'file mode ' . basename($f));
    }

    $rl->cleanup(intdiv($now, 60) + 5, gmdate('Ymd', $now));
    assert_same(0, count(glob($dir . '/[gm]-*') ?: []), 'stale minute counters removed');
    assert_same(4, count(glob($dir . '/d-*') ?: []), 'day counters kept (four clients)');

    $day = new RateLimiter(temp_dir(), 100, 3, 300, fn () => $now);
    $day->hit('1.2.3.4');
    $day->hit('1.2.3.4');
    $day->hit('1.2.3.4');
    assert_true(str_contains(refusal_of(fn () => $day->hit('1.2.3.4'))->getMessage(), 'per day'));

    $global = new RateLimiter(temp_dir(), 100, 100, 2, fn () => $now);
    $global->hit('1.1.1.1');
    $global->hit('2.2.2.2');
    assert_true(str_contains(refusal_of(fn () => $global->hit('3.3.3.3'))->getMessage(), 'site-wide'));
});

// ------------------------------------------------------------------ HTTP: proxy against a local upstream

function x_upstream(): TestServer
{
    static $s = null;

    return $s ??= new TestServer([], __DIR__ . '/fixtures/explore-upstream.php');
}

/** A record served by the fake upstream. */
function x_record(string $slug, array $over = []): array
{
    $port = x_upstream()->port;
    $d = fixture('open-meteo', true);
    unset($d['related'], $d['links']['openapi'], $d['links']['source']);
    $d['slug'] = $slug;
    $d['name'] = "Upstream $slug";
    $d['api']['base_url'] = "https://up.test:$port";
    $d['endpoints'] = [
        ['method' => 'GET', 'path' => '/json', 'summary' => 'JSON'],
        ['method' => 'GET', 'path' => "https://other.test:$port/json", 'summary' => 'other host'],
    ];
    $d['example'] = ['method' => 'GET', 'url' => "https://up.test:$port/json", 'response_excerpt' => '{"ok":true}'];

    return array_replace_recursive($d, $over);
}

function x_server(array $extraEnv = []): TestServer
{
    $dir = temp_dir();
    mkdir("$dir/datasets");
    $port = x_upstream()->port;
    $records = [
        x_record('up-none'),
        x_record('up-post', [
            'endpoints' => [2 => ['method' => 'POST', 'path' => '/echo', 'summary' => 'echo']],
            'api' => ['required_headers' => [['name' => 'X-Required', 'example' => '1'], ['name' => 'User-Agent', 'example' => 'MyApp/1.0 (you@example.com)']]],
            'example' => ['method' => 'POST', 'url' => "https://up.test:$port/echo", 'headers' => ['Content-Type' => 'application/json', 'User-Agent' => 'MyApp/1.0 (you@example.com)'], 'body' => '{"q":1}'],
        ]),
        x_record('up-key', ['api' => ['auth' => ['type' => 'api_key', 'key_in' => 'query', 'key_name' => 'api_key']], 'links' => ['signup' => 'https://up.example/signup']]),
        x_record('up-demo', ['api' => ['auth' => ['type' => 'api_key', 'key_in' => 'query', 'key_name' => 'api_key', 'demo_key' => 'DEMO']], 'links' => ['signup' => 'https://up.example/signup'], 'example' => ['url' => "https://up.test:$port/echo?api_key=DEMO"]]),
        x_record('up-deprecated', ['status' => 'deprecated']),
        x_record('up-private', ['api' => ['base_url' => "https://private.test:$port"], 'example' => ['url' => "https://private.test:$port/json"]]),
    ];
    foreach ($records as $r) {
        file_put_contents("$dir/datasets/{$r['slug']}.json", json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return new TestServer($extraEnv + [
        'APP_DATASETS_DIR' => "$dir/datasets",
        'APP_CACHE_FILE' => "$dir/catalog.json",
        'APP_VAR_DIR' => "$dir/var",
        'APP_BASE_PATH' => '',
        'APP_PUBLIC_URL' => '',
        'APP_EXPLORE_TEST_MODE' => '1',
        'APP_EXPLORE_TEST_RESOLVE' => 'up.test=127.0.0.1,other.test=127.0.0.1,private.test=10.0.0.1',
        'APP_EXPLORE_TEST_LIMITS' => '1000,1000,1000',
    ]);
}

function xs(): TestServer
{
    static $s = null;

    return $s ??= x_server();
}

/** POST /explore; $payload is JSON-encoded unless it is a string. */
function explore(array|string $payload, array $headers = [], ?TestServer $s = null, string $path = '/explore'): array
{
    $body = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES);
    $hasType = false;
    foreach ($headers as $h) {
        $hasType = $hasType || stripos($h, 'content-type:') === 0;
    }

    return http('POST', ($s ?? xs())->base . $path, $hasType ? $headers : array_merge(['Content-Type: application/json'], $headers), $body);
}

function up(string $path): string
{
    return 'http://up.test:' . x_upstream()->port . $path;
}

function assert_problem(array $r, int $status, string $title = ''): void
{
    assert_same($status, $r['status'], 'status (' . substr($r['body'], 0, 300) . ')');
    assert_same('application/problem+json; charset=utf-8', $r['headers']['content-type'] ?? null);
    assert_same($status, $r['json']['status'] ?? null);
    if ($title !== '') {
        assert_same($title, $r['json']['title'] ?? null);
    }
    assert_explore_headers($r);
}

function assert_explore_headers(array $r): void
{
    assert_same('no-store', $r['headers']['cache-control'] ?? null, 'Cache-Control');
    assert_same('noindex', $r['headers']['x-robots-tag'] ?? null, 'X-Robots-Tag');
    foreach (array_keys($r['headers']) as $h) {
        assert_false(str_starts_with($h, 'access-control-'), "no CORS header ($h)");
    }
}

test('explore http: GET through the proxy, filtered headers, cache', function () {
    $r = explore(['slug' => 'up-none', 'method' => 'GET', 'url' => up('/json')]);
    assert_same(200, $r['status'], $r['body']);
    assert_same('application/json; charset=utf-8', $r['headers']['content-type']);
    assert_explore_headers($r);
    $j = $r['json'];
    assert_same(200, $j['status']);
    assert_same('OK', $j['status_text']);
    assert_same(false, $j['truncated']);
    assert_same(false, $j['cached']);
    assert_same(up('/json'), $j['final_url']);
    assert_same([], $j['redirects']);
    assert_true(json_decode($j['body'], true)['ok']);
    assert_same('41', $j['headers']['x-ratelimit-remaining'] ?? null);
    assert_false(isset($j['headers']['set-cookie']), 'set-cookie not returned');
    assert_false(isset($j['headers']['x-internal']), 'arbitrary headers not returned');
    assert_true(is_int($j['duration_ms']) && $j['size'] === strlen($j['body']));

    $again = explore(['slug' => 'up-none', 'method' => 'GET', 'url' => up('/json')])['json'];
    assert_same(true, $again['cached']);
    assert_same($j['body'], $again['body'], 'served from the 30 s cache');
    assert_same(false, explore(['slug' => 'up-none', 'method' => 'GET', 'url' => up('/json?x=1')])['json']['cached']);
});

test('explore http: only our own headers go upstream', function () {
    $r = explore(['slug' => 'up-demo', 'method' => 'GET', 'url' => up('/echo?api_key=DEMO')], [
        'Cookie: session=secret', 'Authorization: Bearer secret', 'X-Forwarded-For: 10.0.0.1', 'User-Agent: visitor-browser', 'X-Custom: 1',
    ]);
    assert_same(200, $r['status'], $r['body']);
    $echo = json_decode($r['json']['body'], true);
    assert_same('GET', $echo['method']);
    assert_same('api_key=DEMO', $echo['query']);
    $h = $echo['headers'];
    assert_same(Policy::USER_AGENT, $h['user-agent']);
    assert_same(Policy::DEFAULT_ACCEPT, $h['accept']);
    foreach (['cookie', 'authorization', 'x-forwarded-for', 'x-custom', 'origin', 'referer'] as $k) {
        assert_false(isset($h[$k]), "$k not forwarded");
    }
});

test('explore http: POST with the record\'s Content-Type and required headers', function () {
    $r = explore(['slug' => 'up-post', 'method' => 'POST', 'url' => up('/echo'), 'body' => '{"q":2}']);
    assert_same(200, $r['status'], $r['body']);
    $echo = json_decode($r['json']['body'], true);
    assert_same('POST', $echo['method']);
    assert_same('{"q":2}', $echo['body']);
    assert_same('application/json', $echo['headers']['content-type']);
    assert_same('1', $echo['headers']['x-required']);
    assert_same(Policy::USER_AGENT, $echo['headers']['user-agent'], 'placeholder User-Agent replaced by ours');

    assert_problem(explore(['slug' => 'up-post', 'method' => 'POST', 'url' => up('/echo'), 'body' => str_repeat('a', 4001)]), 413);
    assert_problem(explore(['slug' => 'up-none', 'method' => 'POST', 'url' => up('/json'), 'body' => 'x']), 403, 'Method not allowed for this API');
    assert_problem(explore(['slug' => 'up-post', 'method' => 'DELETE', 'url' => up('/echo')]), 400);
    assert_problem(explore(['slug' => 'up-post', 'method' => 'PUT', 'url' => up('/echo')]), 400);
});

test('explore http: redirects are followed by hand and re-validated', function () {
    $j = explore(['slug' => 'up-none', 'method' => 'GET', 'url' => up('/to-other')])['json'];
    assert_same(200, $j['status'], json_encode($j));
    assert_same('http://other.test:' . x_upstream()->port . '/json', $j['final_url']);
    assert_same(302, $j['redirects'][0]['status']);

    $j = explore(['slug' => 'up-none', 'method' => 'GET', 'url' => up('/to-relative')])['json'];
    assert_same(200, $j['status']);
    assert_same(up('/json'), $j['final_url']);

    $j = explore(['slug' => 'up-none', 'method' => 'GET', 'url' => up('/to-evil')])['json'];
    assert_same(302, $j['status'], 'redirect to a foreign host is returned, not followed');
    assert_same('https://evil.example/steal', $j['headers']['location']);
    assert_contains('Redirect not followed', $j['note']);
    assert_same([], $j['redirects']);

    $j = explore(['slug' => 'up-none', 'method' => 'GET', 'url' => up('/loop')])['json'];
    assert_same(302, $j['status']);
    assert_same(3, count($j['redirects']));
    assert_contains('stopped after 3', $j['note']);
});

test('explore http: SSRF — hosts resolving to private addresses are refused', function () {
    // private.test is in up-private's allowlist but resolves to 10.0.0.1
    assert_problem(explore(['slug' => 'up-private', 'method' => 'GET', 'url' => 'http://private.test:' . x_upstream()->port . '/json']), 403, 'Address not allowed');
    // and a redirect to it from an allowed host is refused on the hop
    $port = x_upstream()->port;
    $dir = temp_dir();
    mkdir("$dir/d");
    file_put_contents("$dir/d/up-hop.json", json_encode(x_record('up-hop', ['endpoints' => [2 => ['method' => 'GET', 'path' => "https://private.test:$port/json", 'summary' => 'private']]])));
    $s = new TestServer(['APP_DATASETS_DIR' => "$dir/d", 'APP_CACHE_FILE' => "$dir/c.json", 'APP_VAR_DIR' => "$dir/var", 'APP_BASE_PATH' => '', 'APP_PUBLIC_URL' => '',
        'APP_EXPLORE_TEST_MODE' => '1', 'APP_EXPLORE_TEST_RESOLVE' => 'up.test=127.0.0.1,private.test=10.0.0.1']);
    assert_problem(explore(['slug' => 'up-hop', 'method' => 'GET', 'url' => up('/to-private')], [], $s), 403, 'Address not allowed');
    $s->stop();

    assert_problem(explore(['slug' => 'up-none', 'method' => 'GET', 'url' => 'https://169.254.169.254/latest/meta-data/']), 403, 'Host not allowed');
    assert_problem(explore(['slug' => 'up-none', 'method' => 'GET', 'url' => 'http://127.0.0.1:' . x_upstream()->port . '/json']), 403, 'Host not allowed');
    assert_problem(explore(['slug' => 'up-none', 'method' => 'GET', 'url' => 'https://evil.example/']), 403, 'Host not allowed');
    assert_problem(explore(['slug' => 'up-none', 'method' => 'GET', 'url' => 'http://up.test:' . x_upstream()->port . '@evil.example/']), 400, 'Credentials in URL');
    assert_problem(explore(['slug' => 'up-none', 'method' => 'GET', 'url' => 'http://up.test:22/json']), 403, 'Port not allowed');
});

test('explore http: body cap, binary bodies, upstream errors relayed', function () {
    $j = explore(['slug' => 'up-none', 'method' => 'GET', 'url' => up('/big')])['json'];
    assert_same(200, $j['status']);
    assert_same(true, $j['truncated']);
    assert_same(Fetcher::MAX_BODY, $j['size']);
    assert_true(is_string($j['body']) && strlen($j['body']) >= Fetcher::MAX_BODY - 3 && preg_match('//u', $j['body']) === 1, 'valid UTF-8 text');

    $j = explore(['slug' => 'up-none', 'method' => 'GET', 'url' => up('/binary')])['json'];
    assert_same(null, $j['body']);
    assert_contains('Binary response (image/png', $j['note']);

    $j = explore(['slug' => 'up-none', 'method' => 'GET', 'url' => up('/status/503')])['json'];
    assert_same(503, $j['status']);
    assert_same('{"error":"busy"}', $j['body']);
});

test('explore http: eligibility refusals', function () {
    assert_problem(explore(['slug' => 'nope', 'method' => 'GET', 'url' => up('/json')]), 404, 'Unknown dataset');
    $r = explore(['slug' => 'up-key', 'method' => 'GET', 'url' => up('/json?api_key=mine')]);
    assert_problem($r, 403, 'Not available through the proxy');
    assert_contains('own free key', $r['json']['detail']);
    assert_problem(explore(['slug' => 'up-deprecated', 'method' => 'GET', 'url' => up('/json')]), 403, 'Not available through the proxy');
    assert_same(200, explore(['slug' => 'up-demo', 'method' => 'GET', 'url' => up('/echo?api_key=DEMO')])['json']['status']);
    assert_problem(explore(['slug' => 'up-demo', 'method' => 'GET', 'url' => up('/echo?api_key=personal123')]), 403, 'Personal keys are not proxied');
});

test('explore http: request format and same-origin checks', function () {
    $ok = ['slug' => 'up-none', 'method' => 'GET', 'url' => up('/json')];
    assert_problem(explore(json_encode($ok), ['Content-Type: text/plain']), 415);
    assert_problem(explore(json_encode($ok), ['Content-Type: application/x-www-form-urlencoded']), 415);
    assert_problem(explore('{"slug":"up-none","method":"GET","url":"' . str_repeat('a', 9000) . '"}'), 413);
    assert_problem(explore('not json'), 400);
    assert_problem(explore('["up-none"]'), 400);
    assert_problem(explore(['slug' => 'up-none', 'method' => 'GET', 'url' => 5]), 400);

    assert_problem(explore($ok, ['Sec-Fetch-Site: cross-site']), 403, 'Cross-site request refused');
    assert_problem(explore($ok, ['Sec-Fetch-Site: same-site']), 403);
    assert_problem(explore($ok, ['Origin: https://evil.example']), 403, 'Cross-origin request refused');
    assert_problem(explore($ok, ['Origin: null']), 403);
    assert_same(200, explore($ok, ['Sec-Fetch-Site: same-origin', 'Origin: ' . xs()->base])['status'], 'own origin');
    assert_same(200, explore($ok, ['Content-Type: application/json; charset=utf-8'])['status']);
});

test('explore http: other methods get 405, never a CORS preflight', function () {
    foreach (['GET', 'HEAD', 'PUT', 'DELETE'] as $m) {
        $r = http($m, xs()->base . '/explore');
        assert_same(405, $r['status'], $m);
        assert_same('POST', $r['headers']['allow'] ?? null);
        assert_explore_headers($r);
    }
    $r = http('OPTIONS', xs()->base . '/explore', ['Origin: https://evil.example', 'Access-Control-Request-Method: POST', 'Access-Control-Request-Headers: content-type']);
    assert_problem($r, 405);
    assert_same('POST', $r['headers']['allow']);
});

test('explore http: rate limit per client returns 429 with Retry-After', function () {
    $s = x_server(['APP_EXPLORE_TEST_LIMITS' => '']); // the production limits
    $statuses = [];
    for ($i = 0; $i < 21; $i++) {
        $statuses[] = explore(['slug' => 'nope', 'method' => 'GET', 'url' => up('/json')], [], $s)['status'];
    }
    assert_same(array_fill(0, 20, 404), array_slice($statuses, 0, 20), 'first 20 counted, not limited');
    $r = explore(['slug' => 'up-none', 'method' => 'GET', 'url' => up('/json')], [], $s);
    assert_problem($r, 429, 'Too many requests');
    assert_true((int) ($r['headers']['retry-after'] ?? 0) >= 1, 'Retry-After');
    $s->stop();
});

test('explore http: robots.txt, prefixed mount, dataset page', function () {
    assert_contains("Disallow: /explore\n", get('/robots.txt')['body']);
    assert_contains("Disallow: /datasets/explore\n", http('GET', prefixed_server()->base . '/datasets/robots.txt')['body']);
    $r = http('POST', prefixed_server()->base . '/datasets/explore', ['Content-Type: application/json'], '{"slug":"nope","method":"GET","url":"https://x/"}');
    assert_problem($r, 404, 'Unknown dataset');

    $html = get('/d/open-meteo')['body'];
    assert_contains('<section class="section" id="try"', $html);
    assert_contains('<li><a href="#try">Try it</a></li>', $html);
    assert_contains('data-explorer-config', $html);
    assert_contains('"proxy":"/explore"', $html);
    assert_true(preg_match('~<script src="/assets/explorer\.js\?v=\d+" defer>~', $html) === 1, 'explorer.js cache-busted');
    assert_contains('data-x-nojs', $html);
    assert_false(str_contains(get('/')['body'], 'explorer.js'), 'only on dataset pages');
    assert_false((bool) preg_match('/\son[a-z]+=/', $html), 'no inline event handlers');
    assert_contains('"proxy":"/datasets/explore"', http('GET', prefixed_server()->base . '/datasets/d/open-meteo')['body']);
});

<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function server(): TestServer
{
    static $s = null;

    return $s ??= new TestServer([
        'APP_DATASETS_DIR' => fixtures() . '/datasets',
        'APP_CACHE_FILE' => sys_get_temp_dir() . '/datasets-test-nocache.json',
        'APP_BASE_PATH' => '',
        'APP_PUBLIC_URL' => '',
    ]);
}

function prefixed_server(): TestServer
{
    static $s = null;

    return $s ??= new TestServer([
        'APP_DATASETS_DIR' => fixtures() . '/datasets',
        'APP_CACHE_FILE' => sys_get_temp_dir() . '/datasets-test-nocache.json',
        'APP_BASE_PATH' => '/free-datasets',
        'APP_PUBLIC_URL' => '',
    ]);
}

function get(string $path, array $headers = [], string $method = 'GET'): array
{
    return http($method, server()->base . $path, $headers);
}

function assert_api_headers(array $r, string $type = 'application/json; charset=utf-8'): void
{
    assert_same('*', $r['headers']['access-control-allow-origin'] ?? null, 'CORS header');
    assert_same('nosniff', $r['headers']['x-content-type-options'] ?? null, 'nosniff');
    assert_same('public, max-age=300', $r['headers']['cache-control'] ?? null, 'Cache-Control');
    if ($r['status'] !== 304 && $r['status'] !== 204) {
        assert_same($type, $r['headers']['content-type'] ?? null, 'Content-Type');
    }
}

test('http: API root', function () {
    $r = get('/api/v1');
    assert_same(200, $r['status']);
    assert_api_headers($r);
    assert_true((bool) preg_match('/^"[0-9a-f]{32}"$/', $r['headers']['etag'] ?? ''), 'strong ETag');
    $j = $r['json'];
    assert_same(4, $j['counts']['datasets']);
    assert_same(count(json_decode((string) file_get_contents(dirname(__DIR__) . '/schema/categories.json'), true)), $j['counts']['categories']);
    $base = server()->base;
    foreach (['datasets', 'categories', 'tags', 'facets', 'schema', 'openapi'] as $k) {
        assert_true(str_starts_with($j['links'][$k], "$base/api/v1"), "link $k");
    }
    assert_contains("\n    \"name\"", $r['body'], 'pretty-printed');
    assert_contains('"self": "' . $base . '/api/v1"', $r['body'], 'unescaped slashes');
});

test('http: dataset list envelope, pagination and Link header', function () {
    $base = server()->base;
    $r = get('/api/v1/datasets?limit=2');
    assert_same(200, $r['status']);
    assert_api_headers($r);
    $j = $r['json'];
    assert_same(['total' => 4, 'limit' => 2, 'offset' => 0, 'count' => 2], $j['meta']);
    assert_same(['nasa-apod', 'open-meteo'], array_column($j['data'], 'slug'));
    assert_same("$base/api/v1/datasets/nasa-apod", $j['data'][0]['url']);
    assert_same("$base/d/nasa-apod", $j['data'][0]['html_url']);
    assert_same(null, $j['links']['prev']);
    assert_same("$base/api/v1/datasets?limit=2&offset=2", $j['links']['next']);
    assert_same("$base/api/v1/datasets?limit=2&offset=2", $j['links']['last']);
    assert_same("$base/api/v1/datasets?limit=2", $j['links']['first']);
    $link = $r['headers']['link'] ?? '';
    assert_contains("<$base/api/v1/datasets?limit=2&offset=2>; rel=\"next\"", $link);
    assert_contains('rel="first"', $link);
    assert_false(str_contains($link, 'rel="prev"'));

    $r = get('/api/v1/datasets?limit=2&offset=2');
    assert_same("$base/api/v1/datasets?limit=2", $r['json']['links']['prev']);
    assert_same(null, $r['json']['links']['next']);
    assert_same(['usgs-earthquakes', 'wikidata'], array_column($r['json']['data'], 'slug'));

    $r = get('/api/v1/datasets');
    assert_same(50, $r['json']['meta']['limit']);
    assert_same(4, $r['json']['meta']['count']);
    assert_true(is_object(json_decode($r['body'])->data[1]->example->headers), '{} stays an object');
});

test('http: dataset list filters, sort and fields', function () {
    $slugs = static fn (string $qs): array => array_column(get('/api/v1/datasets?' . $qs)['json']['data'], 'slug');
    assert_same(['usgs-earthquakes'], $slugs('q=earthquake'));
    assert_same(['open-meteo'], $slugs('q=Weather+historical'));
    assert_same(['open-meteo', 'usgs-earthquakes'], $slugs('category=weather-climate&category=earth-environment'));
    assert_same(['open-meteo', 'usgs-earthquakes'], $slugs('category=weather-climate,earth-environment'));
    assert_same(['open-meteo'], $slugs('tag=weather,forecast'));
    assert_same([], $slugs('tag=weather,astronomy'));
    assert_same(['nasa-apod'], $slugs('auth=api_key'));
    assert_same(['nasa-apod', 'open-meteo', 'usgs-earthquakes', 'wikidata'], $slugs('auth=none,api_key'));
    assert_same(['wikidata'], $slugs('cors=reflect'));
    assert_same(['usgs-earthquakes'], $slugs('format=geojson'));
    assert_same(['wikidata'], $slugs('style=sparql'));
    assert_same(['nasa-apod', 'usgs-earthquakes'], $slugs('provider_type=government'));
    assert_same(['usgs-earthquakes', 'wikidata'], $slugs('commercial_use=allowed'));
    assert_same(4, count($slugs('status=active')));
    assert_same(['open-meteo', 'usgs-earthquakes', 'wikidata'], $slugs('keyless=true'));
    assert_same(['nasa-apod'], $slugs('keyless=false'));
    assert_same(4, count($slugs('browser=true')));
    assert_same(['open-meteo'], $slugs('has_openapi=true'));
    assert_same(['nasa-apod', 'usgs-earthquakes', 'wikidata'], $slugs('has_openapi=false'));
    assert_same(['nasa-apod', 'usgs-earthquakes', 'wikidata', 'open-meteo'], $slugs('sort=-added'));
    assert_same(['wikidata', 'usgs-earthquakes', 'open-meteo', 'nasa-apod'], $slugs('sort=-name'));
    assert_same(['nasa-apod', 'open-meteo', 'wikidata', 'usgs-earthquakes'], $slugs('sort=verified'));

    $r = get('/api/v1/datasets?fields=name,api&limit=1');
    $item = $r['json']['data'][0];
    assert_same(['slug', 'name', 'api', 'url', 'html_url'], array_keys($item));
    assert_contains('fields=name,api', $r['json']['links']['self']);

    $r = get('/api/v1/datasets?keyless=true&limit=1&sort=-added');
    assert_same(server()->base . '/api/v1/datasets?keyless=true&sort=-added&limit=1&offset=1', $r['json']['links']['next']);
});

test('http: invalid parameters give 400 problem+json with errors', function () {
    $cases = [
        'limit=0' => 'limit', 'limit=201' => 'limit', 'limit=abc' => 'limit', 'offset=-1' => 'offset',
        'sort=popular' => 'sort', 'auth=password' => 'auth', 'cors=yes' => 'cors', 'category=nope' => 'category',
        'format=pdf' => 'format', 'style=soap' => 'style', 'provider_type=alien' => 'provider_type',
        'commercial_use=maybe' => 'commercial_use', 'status=dead' => 'status', 'keyless=maybe' => 'keyless',
        'browser=2' => 'browser', 'has_openapi=perhaps' => 'has_openapi', 'fields=name,secret' => 'fields',
        'tag=Not%20Kebab' => 'tag', 'categroy=food' => 'categroy', 'sort=name&sort=-name' => 'sort',
        'q=' . str_repeat('x', 201) => 'q',
    ];
    foreach ($cases as $qs => $param) {
        $r = get('/api/v1/datasets?' . $qs);
        assert_same(400, $r['status'], $qs);
        assert_api_headers($r, 'application/problem+json; charset=utf-8');
        $j = $r['json'];
        assert_same(400, $j['status'], $qs);
        assert_same('about:blank', $j['type']);
        assert_same('Invalid query parameters', $j['title']);
        assert_same('/api/v1/datasets?' . $qs, $j['instance']);
        assert_same($param, $j['errors'][0]['parameter'], $qs);
        assert_true(is_string($j['detail']) && $j['detail'] !== '');
    }
    $r = get('/api/v1/datasets?limit=500&auth=x');
    assert_same(2, count($r['json']['errors']));
});

test('http: single dataset and 404', function () {
    $base = server()->base;
    $r = get('/api/v1/datasets/open-meteo');
    assert_same(200, $r['status']);
    assert_api_headers($r);
    $j = $r['json'];
    assert_same('open-meteo', $j['data']['slug']);
    assert_same("$base/api/v1/datasets/open-meteo", $j['data']['url']);
    assert_same("$base/d/open-meteo", $j['data']['html_url']);
    assert_same("$base/api/v1/datasets/open-meteo", $j['links']['self']);
    assert_same("$base/d/open-meteo", $j['links']['html']);
    assert_same("$base/api/v1/datasets", $j['links']['collection']);
    assert_same(["$base/api/v1/datasets/usgs-earthquakes"], $j['links']['related']);
    assert_contains('"temporal": "1940–present', $r['body'], 'unescaped unicode');
    assert_contains('Météo-France', $r['body']);

    $r = get('/api/v1/datasets/nope');
    assert_same(404, $r['status']);
    assert_api_headers($r, 'application/problem+json; charset=utf-8');
    assert_same(['type', 'title', 'status', 'detail', 'instance'], array_keys($r['json']));
    assert_same('Not Found', $r['json']['title']);
    assert_same('/api/v1/datasets/nope', $r['json']['instance']);

    $r = get('/api/v1/nothing/here');
    assert_same(404, $r['status']);
    assert_same('application/problem+json; charset=utf-8', $r['headers']['content-type']);
});

test('http: categories, tags, facets', function () {
    $base = server()->base;
    $r = get('/api/v1/categories');
    assert_same(200, $r['status']);
    assert_same(count(json_decode((string) file_get_contents(dirname(__DIR__) . '/schema/categories.json'), true)), count($r['json']['data']));
    $first = $r['json']['data'][0];
    assert_same(['id', 'name', 'description', 'count', 'links'], array_keys($first));
    assert_same('weather-climate', $first['id']);
    assert_same(1, $first['count']);
    assert_same("$base/api/v1/datasets?category=weather-climate", $first['links']['datasets']);

    $r = get('/api/v1/categories/space-astronomy');
    assert_same(200, $r['status']);
    assert_same(1, $r['json']['data']['count']);
    assert_same(404, get('/api/v1/categories/nope')['status']);

    $r = get('/api/v1/tags');
    assert_same(13, count($r['json']['data']));
    assert_same(['tag', 'count', 'links'], array_keys($r['json']['data'][0]));
    $counts = array_column($r['json']['data'], 'count');
    $sorted = $counts;
    rsort($sorted);
    assert_same($sorted, $counts, 'by count desc');

    $r = get('/api/v1/facets');
    assert_same(200, $r['status']);
    assert_same(['category', 'auth', 'cors', 'format', 'style', 'provider_type', 'commercial_use', 'status'], array_keys($r['json']['data']));
    assert_same(['none' => 3, 'api_key' => 1, 'oauth2' => 0], $r['json']['data']['auth']);
    assert_same(4, $r['json']['meta']['total']);
    $r = get('/api/v1/facets?keyless=true');
    assert_same(3, $r['json']['meta']['total']);
    assert_same(0, $r['json']['data']['auth']['api_key']);
    assert_same(400, get('/api/v1/facets?limit=5')['status'], 'paging params are not facet params');
});

test('http: schema and openapi', function () {
    $base = server()->base;
    $r = get('/api/v1/schema');
    assert_same(200, $r['status']);
    assert_api_headers($r, 'application/schema+json; charset=utf-8');
    assert_same("$base/api/v1/schema", $r['json']['$id']);
    assert_same('https://json-schema.org/draft/2020-12/schema', $r['json']['$schema']);
    assert_same('Dataset', $r['json']['title']);

    $r = get('/api/v1/openapi.json');
    assert_same(200, $r['status']);
    assert_api_headers($r);
    $o = $r['json'];
    assert_same('3.1.0', $o['openapi']);
    assert_same($base, $o['servers'][0]['url']);
    assert_same('CC0-1.0', $o['info']['license']['identifier']);
    $ops = [];
    foreach ($o['paths'] as $path => $item) {
        foreach ($item as $method => $op) {
            $ops[] = $op['operationId'];
            assert_true(isset($op['responses']['200']), "$path 200");
            preg_match_all('/\{(\w+)\}/', $path, $m);
            foreach ($m[1] as $name) {
                $declared = false;
                foreach ($op['parameters'] as $p) {
                    $p = isset($p['$ref']) ? $o['components']['parameters'][substr($p['$ref'], strlen('#/components/parameters/'))] : $p;
                    $declared = $declared || ($p['in'] === 'path' && $p['name'] === $name && $p['required'] === true);
                }
                assert_true($declared, "path parameter $name of $path");
            }
        }
    }
    assert_same(count($ops), count(array_unique($ops)), 'unique operationIds');
    foreach (['getApiRoot', 'listDatasets', 'getDataset', 'listCategories', 'getCategory', 'listTags', 'getFacets', 'getSchema', 'getOpenApi'] as $id) {
        assert_true(in_array($id, $ops, true), $id);
    }
    // every $ref resolves
    $walk = function (mixed $node) use (&$walk, $o): void {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['$ref']) && is_string($node['$ref'])) {
            $target = $o;
            foreach (explode('/', substr($node['$ref'], 2)) as $tok) {
                $tok = str_replace(['~1', '~0'], ['/', '~'], $tok);
                assert_true(is_array($target) && array_key_exists($tok, $target), 'unresolvable $ref ' . $node['$ref']);
                $target = $target[$tok];
            }
        }
        foreach ($node as $child) {
            $walk($child);
        }
    };
    $walk($o);
    // enums are generated from the schema files
    assert_same(['none', 'api_key', 'oauth2'], $o['components']['parameters']['auth']['schema']['items']['enum']);
    assert_same(count(json_decode((string) file_get_contents(dirname(__DIR__) . '/schema/categories.json'), true)), count($o['components']['parameters']['category']['schema']['items']['enum']));
    assert_same('Dataset', $o['components']['schemas']['Dataset']['title']);
    assert_true(isset($o['components']['schemas']['Problem']));
    $params = array_map(static fn ($p) => basename($p['$ref']), $o['paths']['/api/v1/datasets']['get']['parameters']);
    foreach (['q', 'category', 'tag', 'auth', 'cors', 'format', 'style', 'provider_type', 'commercial_use', 'status', 'keyless', 'browser', 'has_openapi', 'sort', 'limit', 'offset', 'fields'] as $p) {
        assert_true(in_array($p, $params, true), "listDatasets documents $p");
    }
});

test('http: ETag / If-None-Match round trip', function () {
    $r = get('/api/v1/datasets?limit=2');
    $etag = $r['headers']['etag'];
    $r2 = get('/api/v1/datasets?limit=2', ["If-None-Match: $etag"]);
    assert_same(304, $r2['status']);
    assert_same('', $r2['body']);
    assert_same($etag, $r2['headers']['etag']);
    assert_api_headers($r2);
    assert_same(304, get('/api/v1/datasets?limit=2', ['If-None-Match: "zzz", W/' . $etag])['status'], 'list + weak form');
    assert_same(304, get('/api/v1/datasets?limit=2', ['If-None-Match: ' . substr($etag, 0, -1) . '-gzip"'])['status'], 'mod_deflate suffix');
    assert_same(200, get('/api/v1/datasets?limit=2', ['If-None-Match: "other"'])['status']);
    assert_same(200, get('/api/v1/datasets?limit=3', ["If-None-Match: $etag"])['status'], 'different representation');
});

test('http: HEAD, OPTIONS, 405', function () {
    $g = get('/api/v1/datasets');
    $h = get('/api/v1/datasets', [], 'HEAD');
    assert_same(200, $h['status']);
    assert_same('', $h['body']);
    assert_same($g['headers']['etag'], $h['headers']['etag']);
    assert_same($g['headers']['content-type'], $h['headers']['content-type']);

    $o = get('/api/v1/datasets', ['Origin: https://example.org', 'Access-Control-Request-Method: GET', 'Access-Control-Request-Headers: if-none-match'], 'OPTIONS');
    assert_same(204, $o['status']);
    assert_same('*', $o['headers']['access-control-allow-origin']);
    assert_same('GET, HEAD, OPTIONS', $o['headers']['access-control-allow-methods']);
    assert_same('if-none-match', $o['headers']['access-control-allow-headers']);
    assert_same('GET, HEAD, OPTIONS', $o['headers']['allow']);
    assert_same('', $o['body']);
    assert_false(isset($o['headers']['content-type']), 'no Content-Type on 204');

    foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $m) {
        $r = get('/api/v1/datasets', [], $m);
        assert_same(405, $r['status'], $m);
        assert_same('GET, HEAD, OPTIONS', $r['headers']['allow'], $m);
        assert_same('application/problem+json; charset=utf-8', $r['headers']['content-type']);
        assert_same(405, $r['json']['status']);
    }
    assert_same(405, get('/api/v1/datasets/open-meteo', [], 'POST')['status']);
    assert_same(404, get('/api/v1/nope', [], 'OPTIONS')['status']);
});

test('http: trailing slash redirects', function () {
    $r = get('/api/v1/datasets/?limit=1');
    assert_same(308, $r['status']);
    assert_same('/api/v1/datasets?limit=1', $r['headers']['location']);
});

test('http: llms.txt and robots.txt', function () {
    $base = server()->base;
    $r = get('/llms.txt');
    assert_same(200, $r['status']);
    assert_same('text/plain; charset=utf-8', $r['headers']['content-type']);
    assert_true(str_starts_with($r['body'], '# '));
    assert_contains("- [Open-Meteo]($base/api/v1/datasets/open-meteo): Global weather forecasts", $r['body']);
    assert_contains('## Weather & Climate', $r['body']);
    assert_contains('keyless', $r['body']);
    $r = get('/robots.txt');
    assert_same(200, $r['status']);
    assert_contains("User-agent: *\nAllow: /", $r['body']);
});

test('http: HTML routes dispatch to templates', function () {
    $r = get('/');
    assert_same(200, $r['status']);
    assert_same('text/html; charset=utf-8', $r['headers']['content-type']);
    assert_contains('Open-Meteo', $r['body']);
    assert_contains('href="/d/open-meteo"', $r['body']);
    $r = get('/?keyless=false');
    assert_contains('NASA', $r['body']);
    assert_false(str_contains($r['body'], 'Open-Meteo'));
    assert_same(200, get('/?auth=bogus&limit=9999')['status'], 'HTML filters are lenient');
    $r = get('/d/wikidata');
    assert_same(200, $r['status']);
    assert_contains('<h1>Wikidata Query Service</h1>', $r['body']);
    $r = get('/d/nope');
    assert_same(404, $r['status']);
    assert_contains('Not found', $r['body']);
    assert_same(200, get('/api')['status']);
    assert_same(200, get('/about')['status']);
    $r = get('/no/such/page');
    assert_same(404, $r['status']);
    assert_same('text/html; charset=utf-8', $r['headers']['content-type']);
    assert_same(405, get('/', [], 'POST')['status']);
});

test('http: built-in server serves static files from public/', function () use ($root) {
    $file = "$root/public/__test-static.txt";
    file_put_contents($file, "static ok\n");
    try {
        $r = get('/__test-static.txt');
        assert_same(200, $r['status']);
        assert_same("static ok\n", $r['body']);
        $r = http('GET', prefixed_server()->base . '/free-datasets/__test-static.txt');
        assert_same(200, $r['status'], 'under base path');
        assert_same("static ok\n", $r['body']);
    } finally {
        @unlink($file);
    }
    assert_same(404, get('/.htaccess')['status'], 'dotfiles are not served');
});

test('http: APP_BASE_PATH=/free-datasets prefixes every link', function () {
    $base = prefixed_server()->base;
    $r = http('GET', "$base/free-datasets/api/v1");
    assert_same(200, $r['status']);
    assert_same("$base/free-datasets/api/v1/datasets", $r['json']['links']['datasets']);
    assert_same("$base/free-datasets/", $r['json']['links']['html']);

    $r = http('GET', "$base/free-datasets/api/v1/datasets?limit=1");
    assert_same("$base/free-datasets/api/v1/datasets/nasa-apod", $r['json']['data'][0]['url']);
    assert_same("$base/free-datasets/d/nasa-apod", $r['json']['data'][0]['html_url']);
    assert_same("$base/free-datasets/api/v1/datasets?limit=1&offset=1", $r['json']['links']['next']);
    assert_contains("<$base/free-datasets/api/v1/datasets?limit=1&offset=1>; rel=\"next\"", $r['headers']['link']);

    $r = http('GET', "$base/free-datasets/api/v1/nope");
    assert_same('/free-datasets/api/v1/nope', $r['json']['instance']);

    $r = http('GET', "$base/free-datasets/api/v1/openapi.json");
    assert_same("$base/free-datasets", $r['json']['servers'][0]['url']);
    assert_same("$base/free-datasets/api/v1/schema", http('GET', "$base/free-datasets/api/v1/schema")['json']['$id']);

    $r = http('GET', "$base/free-datasets/");
    assert_same(200, $r['status']);
    assert_contains('href="/free-datasets/d/open-meteo"', $r['body']);
    assert_contains("($base/free-datasets/api/v1/datasets/open-meteo)", http('GET', "$base/free-datasets/llms.txt")['body']);
    $r = http('GET', "$base/free-datasets/api/v1/datasets/");
    assert_same('/free-datasets/api/v1/datasets', $r['headers']['location']);
});

test('http: APP_PUBLIC_URL overrides absolute links', function () {
    $s = new TestServer([
        'APP_DATASETS_DIR' => fixtures() . '/datasets',
        'APP_BASE_PATH' => '',
        'APP_PUBLIC_URL' => 'https://endtime-instruments.org/free-datasets/',
    ]);
    $r = http('GET', $s->base . '/api/v1');
    assert_same('https://endtime-instruments.org/free-datasets/api/v1/datasets', $r['json']['links']['datasets']);
    assert_same('https://endtime-instruments.org/free-datasets', http('GET', $s->base . '/api/v1/openapi.json')['json']['servers'][0]['url']);
    $s->stop();
});

test('base path detection from SCRIPT_NAME (Apache)', function () {
    // Exercised in a child process: detection is skipped under cli-server and env overrides.
    $code = <<<'PHP'
        require $argv[1] . '/src/bootstrap.php';
        $r = Datasets\Http\Request::fromGlobals([
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/free-datasets/api/v1/datasets?limit=1',
            'QUERY_STRING' => 'limit=1', 'SCRIPT_NAME' => '/free-datasets/index.php',
            'HTTP_HOST' => 'endtime-instruments.org', 'HTTPS' => 'on',
        ]);
        echo json_encode([$r->basePath, $r->path, $r->publicUrl, $r->query]);
        PHP;
    $dir = temp_dir();
    file_put_contents("$dir/detect.php", "<?php\n" . $code);
    [$exit, $out, $err] = run_php("$dir/detect.php", [dirname(__DIR__)], ['APP_BASE_PATH' => '', 'APP_PUBLIC_URL' => '']);
    assert_same(0, $exit, $err);
    assert_same(['/free-datasets', '/api/v1/datasets', 'https://endtime-instruments.org/free-datasets', ['limit' => ['1']]], json_decode($out, true));
});

test('cli: verify.php executes examples and --write preserves formatting', function () use ($root) {
    $dir = temp_dir();
    $src = (string) file_get_contents(fixtures() . '/datasets/usgs-earthquakes.json');
    $doc = json_decode($src, false);
    $doc->example->url = server()->base . '/api/v1/datasets/usgs-earthquakes';
    $doc->verification->last_verified = '2026-01-01';
    $doc->verification->http_status = 500;
    file_put_contents("$dir/usgs-earthquakes.json", Datasets\Json::encodeFile($doc));
    $bad = deep_copy($doc);
    $bad->slug = 'missing';
    $bad->example->url = server()->base . '/api/v1/datasets/missing';
    file_put_contents("$dir/missing.json", Datasets\Json::encodeFile($bad));

    [$code, $out] = run_php("$root/bin/verify.php", ['usgs-earthquakes'], ['APP_DATASETS_DIR' => $dir]);
    assert_same(0, $code, $out);
    assert_contains('usgs-earthquakes', $out);
    assert_contains('200 OK', $out);

    [$code, $out] = run_php("$root/bin/verify.php", ['--write'], ['APP_DATASETS_DIR' => $dir]);
    assert_same(1, $code, 'a 404 fails the run');
    assert_contains('404 FAIL', $out);
    $after = (string) file_get_contents("$dir/usgs-earthquakes.json");
    $expected = clone $doc;
    $expected->verification->last_verified = date('Y-m-d');
    $expected->verification->http_status = 200;
    assert_same(Datasets\Json::encodeFile($expected), $after, 'only verification fields change');
    assert_true(str_ends_with($after, "}\n"));
    assert_contains("\n  \"slug\": \"usgs-earthquakes\",", $after, '2-space indent');
    assert_same(404, json_decode((string) file_get_contents("$dir/missing.json"))->verification->http_status);
});

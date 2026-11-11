<?php

declare(strict_types=1);

/*
 * POST {base}/mcp: the stateless MCP server, both protocol eras (2026-07-28 per-request metadata
 * and the initialize-based 2025 revisions), every transport error path, and the tools against the
 * four fixture datasets.
 */

use Datasets\Api\ListParams;
use Datasets\Catalog;
use Datasets\Mcp\Server as McpServer;

const MCP_MODERN = '2026-07-28';

function mcp_server(string $limits = '10000,10000,10000', string $basePath = ''): TestServer
{
    static $servers = [];

    return $servers[$limits . $basePath] ??= new TestServer([
        'APP_DATASETS_DIR' => fixtures() . '/datasets',
        'APP_CACHE_FILE' => sys_get_temp_dir() . '/datasets-test-nocache.json',
        'APP_VAR_DIR' => temp_dir(),
        'APP_BASE_PATH' => $basePath,
        'APP_PUBLIC_URL' => '',
        'APP_MCP_TEST_LIMITS' => $limits,
    ]);
}

/** Raw POST to /mcp. */
function mcp_raw(string $body, array $headers = [], ?TestServer $s = null, string $method = 'POST', string $path = '/mcp'): array
{
    $s ??= mcp_server();

    return http($method, $s->base . $path, array_merge(['Content-Type: application/json', 'Accept: application/json, text/event-stream'], $headers), $body);
}

/** @param array<string, mixed> $params */
function rpc(string $method, array $params = [], int|string|null $id = 1): array
{
    $m = ['jsonrpc' => '2.0'] + ($id !== null ? ['id' => $id] : []) + ['method' => $method];
    if ($params !== []) {
        $m['params'] = $params;
    }

    return $m;
}

/** A 2026-07-28 request with the body _meta and the mirrored headers a conforming client sends. */
function modern(string $method, array $params = [], array $headerOverride = [], ?TestServer $s = null): array
{
    $params['_meta'] = ['io.modelcontextprotocol/protocolVersion' => MCP_MODERN, 'io.modelcontextprotocol/clientCapabilities' => new stdClass()] + ($params['_meta'] ?? []);
    $h = ['MCP-Protocol-Version' => MCP_MODERN, 'Mcp-Method' => $method];
    if (isset($params['name'])) {
        $h['Mcp-Name'] = $params['name'];
    }
    $headers = [];
    foreach (array_merge($h, $headerOverride) as $k => $v) {
        if ($v !== null) {
            $headers[] = "$k: $v";
        }
    }

    return mcp_raw(json_encode(rpc($method, $params), JSON_UNESCAPED_SLASHES), $headers, $s);
}

/** A legacy (initialize-era) request: no _meta, MCP-Protocol-Version header optional. */
function legacy(string $method, array $params = [], ?string $version = '2025-06-18', int|string|null $id = 1): array
{
    return mcp_raw(json_encode(rpc($method, $params, $id), JSON_UNESCAPED_SLASHES), $version !== null ? ["MCP-Protocol-Version: $version"] : []);
}

function assert_mcp_headers(array $r): void
{
    assert_same('*', $r['headers']['access-control-allow-origin'] ?? null, 'CORS *');
    assert_same(null, $r['headers']['access-control-allow-credentials'] ?? null, 'never with credentials');
    assert_same('no-store', $r['headers']['cache-control'] ?? null, 'no-store');
    assert_same('noindex', $r['headers']['x-robots-tag'] ?? null, 'noindex');
    assert_same('nosniff', $r['headers']['x-content-type-options'] ?? null, 'nosniff');
    assert_same(null, $r['headers']['mcp-session-id'] ?? null, 'no session id');
}

function assert_rpc_error(array $r, int $status, int $code, mixed $id = 1): array
{
    assert_same($status, $r['status'], 'HTTP status (' . $r['body'] . ')');
    assert_same('application/json; charset=utf-8', $r['headers']['content-type'] ?? null);
    assert_mcp_headers($r);
    assert_same('2.0', $r['json']['jsonrpc'] ?? null);
    assert_same($code, $r['json']['error']['code'] ?? null, 'JSON-RPC code (' . $r['body'] . ')');
    assert_true(is_string($r['json']['error']['message'] ?? null) && $r['json']['error']['message'] !== '');
    assert_same($id, $r['json']['id'] ?? null, 'id');
    assert_false(isset($r['json']['result']));

    return $r['json']['error'];
}

function assert_result(array $r, bool $modern, mixed $id = 1): array
{
    assert_same(200, $r['status'], $r['body']);
    assert_same('application/json; charset=utf-8', $r['headers']['content-type'] ?? null);
    assert_mcp_headers($r);
    assert_same($id, $r['json']['id'] ?? null);
    $res = $r['json']['result'] ?? null;
    assert_true(is_array($res), 'result object');
    if ($modern) {
        assert_same('complete', $res['resultType'] ?? null, 'resultType');
        assert_same(McpServer::SERVER_INFO, $res['_meta']['io.modelcontextprotocol/serverInfo'] ?? null, '_meta serverInfo');
    } else {
        assert_false(isset($res['resultType']), 'legacy results have no resultType');
    }

    return $res;
}

/** structuredContent of a successful tools/call, checked against its text block. */
function tool_ok(array $r, bool $modern = true): array
{
    $res = assert_result($r, $modern);
    assert_same(false, $res['isError'] ?? null, $r['body']);
    assert_same('text', $res['content'][0]['type']);
    assert_same($res['structuredContent'], json_decode($res['content'][0]['text'], true), 'text block = structuredContent');

    return $res['structuredContent'];
}

function tool_error(array $r): string
{
    $res = assert_result($r, true);
    assert_same(true, $res['isError'] ?? null, $r['body']);
    assert_false(isset($res['structuredContent']));

    return (string) $res['content'][0]['text'];
}

function call(string $tool, array $args = []): array
{
    return modern('tools/call', ['name' => $tool] + ($args !== [] ? ['arguments' => $args] : []));
}

// ---------------------------------------------------------------- modern era (2026-07-28)

test('mcp modern: server/discover', function () {
    $res = assert_result(modern('server/discover'), true);
    assert_same(['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'], $res['supportedVersions']);
    assert_same(['tools' => ['listChanged' => false]], $res['capabilities']);
    assert_true(str_contains($res['instructions'], 'search_datasets'));
    assert_same(3600000, $res['ttlMs']);
    assert_same('public', $res['cacheScope']);
    assert_same(['resultType', '_meta'], array_keys(assert_result(modern('ping'), true)), 'ping: empty result');
});

test('mcp modern: tools/list is three small read-only tools', function () {
    $r = modern('tools/list');
    $res = assert_result($r, true);
    assert_same(['search_datasets', 'get_dataset', 'list_categories'], array_column($res['tools'], 'name'));
    assert_true(strlen($r['body']) < 4500, 'tools/list stays small: ' . strlen($r['body']) . ' bytes');
    foreach ($res['tools'] as $t) {
        assert_true(strlen($t['description']) <= 300, "{$t['name']}: concise description");
        assert_same('object', $t['inputSchema']['type']);
        assert_same(false, $t['inputSchema']['additionalProperties']);
        assert_same(['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false], $t['annotations']);
    }
    assert_same(['slug'], $res['tools'][1]['inputSchema']['required']);
    $search = $res['tools'][0]['inputSchema']['properties'];
    assert_same(['q', 'category', 'tag', 'keyless', 'browser', 'has_openapi', 'limit', 'offset'], array_keys($search));
    assert_same([1, 50, 10], [$search['limit']['minimum'], $search['limit']['maximum'], $search['limit']['default']]);
    assert_contains('"properties":{}', $r['body'], 'list_categories: properties is an object, not []');
});

test('mcp tools: search_datasets filters like the REST API', function () {
    $base = mcp_server()->base;
    $all = tool_ok(call('search_datasets'));
    assert_same(4, $all['total']);
    assert_same(['nasa-apod', 'open-meteo', 'usgs-earthquakes', 'wikidata'], array_column($all['items'], 'slug'));
    assert_same(['slug', 'name', 'summary', 'category', 'auth', 'cors', 'status', 'html_url', 'api_url'], array_keys($all['items'][0]));
    $q = tool_ok(call('search_datasets', ['q' => 'earthquake']));
    assert_same(['usgs-earthquakes'], array_column($q['items'], 'slug'));
    assert_same("$base/d/usgs-earthquakes", $q['items'][0]['html_url']);
    assert_same("$base/api/v1/datasets/usgs-earthquakes", $q['items'][0]['api_url']);
    assert_same(['none', 'any', 'active'], [$q['items'][0]['auth'], $q['items'][0]['cors'], $q['items'][0]['status']]);
    // keyless=false, the same as GET /api/v1/datasets?keyless=false
    $rest = http('GET', "$base/api/v1/datasets?keyless=false&fields=slug")['json'];
    assert_same(array_column($rest['data'], 'slug'), array_column(tool_ok(call('search_datasets', ['keyless' => false]))['items'], 'slug'));
    assert_same(['open-meteo'], array_column(tool_ok(call('search_datasets', ['category' => 'weather-climate', 'browser' => true]))['items'], 'slug'));
    $page = tool_ok(call('search_datasets', ['limit' => 2, 'offset' => 2]));
    assert_same([4, 2, ['usgs-earthquakes', 'wikidata']], [$page['total'], $page['offset'], array_column($page['items'], 'slug')]);
    assert_same(0, tool_ok(call('search_datasets', ['q' => 'zzzz-nothing']))['total']);
});

test('mcp tools: bad arguments are tool errors with the REST API\'s messages', function () {
    $rest = http('GET', mcp_server()->base . '/api/v1/datasets?category=nope')['json']['errors'][0]['detail'];
    assert_same('category: ' . $rest, tool_error(call('search_datasets', ['category' => 'nope'])), 'same validation as ListParams');
    assert_contains('limit must be an integer between 1 and 50', tool_error(call('search_datasets', ['limit' => 51])));
    assert_contains('limit must be an integer between 1 and 50', tool_error(call('search_datasets', ['limit' => '5'])));
    assert_contains('keyless must be true or false', tool_error(call('search_datasets', ['keyless' => 'yes'])));
    assert_contains('unknown argument bogus', tool_error(call('search_datasets', ['bogus' => 1])));
    assert_contains('q: must be at most 200 characters', tool_error(call('search_datasets', ['q' => str_repeat('a', 201)])));
    assert_contains('tag: invalid tag', tool_error(call('search_datasets', ['tag' => 'Not A Tag'])));
    assert_contains('this tool takes none', tool_error(call('list_categories', ['x' => 1])));
    assert_contains('slug is required', tool_error(call('get_dataset')));
    assert_contains('No dataset with slug "nope"', tool_error(call('get_dataset', ['slug' => 'nope'])));
    // arguments: a list is not an object
    assert_rpc_error(modern('tools/call', ['name' => 'get_dataset', 'arguments' => [1, 2]]), 200, -32602);
});

test('mcp tools: get_dataset returns the full record, list_categories the non-empty categories', function () {
    $base = mcp_server()->base;
    $d = tool_ok(call('get_dataset', ['slug' => 'usgs-earthquakes']));
    $rest = http('GET', "$base/api/v1/datasets/usgs-earthquakes")['json']['data'];
    assert_same($rest, $d, 'identical to the REST record');
    assert_same("$base/d/usgs-earthquakes", $d['html_url']);
    foreach (['api', 'endpoints', 'example', 'verification', 'data', 'links'] as $k) {
        assert_true(isset($d[$k]), "full record has $k");
    }
    $c = tool_ok(call('list_categories'))['categories'];
    $catalog = Catalog::load(fixtures() . '/datasets', dirname(__DIR__) . '/schema', null);
    $expected = array_values(array_filter($catalog->categories(), static fn ($x) => $x['count'] > 0));
    assert_same(array_column($expected, 'id'), array_column($c, 'id'));
    assert_same(['id', 'name', 'description', 'count'], array_keys($c[0]));
    assert_same(array_sum(array_column($c, 'count')), 4);
});

test('mcp modern: header/body consistency (-32020 HeaderMismatch, 400)', function () {
    assert_rpc_error(modern('tools/list', [], ['MCP-Protocol-Version' => null]), 400, -32020);
    assert_rpc_error(modern('tools/list', [], ['MCP-Protocol-Version' => '2025-11-25']), 400, -32020);
    assert_rpc_error(modern('tools/list', [], ['Mcp-Method' => null]), 400, -32020);
    $e = assert_rpc_error(modern('tools/list', [], ['Mcp-Method' => 'tools/call']), 400, -32020);
    assert_contains("Mcp-Method header value 'tools/call' does not match body value 'tools/list'", $e['message']);
    assert_rpc_error(modern('tools/call', ['name' => 'list_categories'], ['Mcp-Name' => null]), 400, -32020);
    assert_rpc_error(modern('tools/call', ['name' => 'list_categories'], ['Mcp-Name' => 'get_dataset']), 400, -32020);
    assert_rpc_error(modern('tools/call', ['name' => 'list_categories'], ['Mcp-Name' => '=?base64?!!!?=']), 400, -32020);
    // Base64 sentinel form is decoded before comparing.
    tool_ok(modern('tools/call', ['name' => 'list_categories'], ['Mcp-Name' => '=?base64?' . base64_encode('list_categories') . '?=']));
    // Header names are case-insensitive.
    assert_result(mcp_raw(json_encode(rpc('tools/list', ['_meta' => ['io.modelcontextprotocol/protocolVersion' => MCP_MODERN, 'io.modelcontextprotocol/clientCapabilities' => new stdClass()]])),
        ['mcp-protocol-version: ' . MCP_MODERN, 'MCP-METHOD: tools/list']), true);
});

test('mcp modern: versions, required _meta, unknown methods and tools', function () {
    $r = mcp_raw(json_encode(rpc('tools/list', ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2099-01-01', 'io.modelcontextprotocol/clientCapabilities' => new stdClass()]])),
        ['MCP-Protocol-Version: 2099-01-01', 'Mcp-Method: tools/list']);
    $e = assert_rpc_error($r, 400, -32022);
    assert_same(['supported' => McpServer::supported(), 'requested' => '2099-01-01'], $e['data']);
    // Header says modern, body has no _meta version: malformed request.
    assert_rpc_error(mcp_raw(json_encode(rpc('tools/list')), ['MCP-Protocol-Version: ' . MCP_MODERN, 'Mcp-Method: tools/list']), 400, -32602);
    // clientCapabilities is required.
    assert_rpc_error(mcp_raw(json_encode(rpc('tools/list', ['_meta' => ['io.modelcontextprotocol/protocolVersion' => MCP_MODERN]])),
        ['MCP-Protocol-Version: ' . MCP_MODERN, 'Mcp-Method: tools/list']), 400, -32602);
    assert_rpc_error(modern('resources/list'), 404, -32601);
    assert_rpc_error(modern('initialize', ['protocolVersion' => MCP_MODERN]), 404, -32601);
    $e = assert_rpc_error(modern('tools/call', ['name' => 'delete_everything']), 200, -32602);
    assert_contains('Unknown tool', $e['message']);
});

// ---------------------------------------------------------------- legacy era (initialize)

test('mcp legacy: initialize without a session, notifications 202, then stateless calls', function () {
    $r = legacy('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 't', 'version' => '1']], null);
    $res = assert_result($r, false);
    assert_same('2025-06-18', $res['protocolVersion'], 'supported version is echoed');
    assert_same(['tools' => ['listChanged' => false]], $res['capabilities']);
    assert_same(McpServer::SERVER_INFO, $res['serverInfo']);
    assert_true(str_contains($res['instructions'], 'get_dataset'));
    assert_same('2025-11-25', assert_result(legacy('initialize', ['protocolVersion' => '2024-11-05'], null), false)['protocolVersion'], 'unknown version: our latest legacy');
    assert_same('2025-11-25', assert_result(legacy('initialize', ['protocolVersion' => MCP_MODERN], null), false)['protocolVersion']);

    $n = legacy('notifications/initialized', [], '2025-06-18', null);
    assert_same(202, $n['status']);
    assert_same('', $n['body']);
    assert_mcp_headers($n);

    assert_same([], assert_result(legacy('ping'), false));
    $tools = assert_result(legacy('tools/list'), false);
    assert_same(['search_datasets', 'get_dataset', 'list_categories'], array_column($tools['tools'], 'name'));
    assert_false(isset($tools['ttlMs']), 'no modern cache hints');
    $res = assert_result(legacy('tools/call', ['name' => 'search_datasets', 'arguments' => ['q' => 'weather']]), false);
    assert_same(['open-meteo'], array_column($res['structuredContent']['items'], 'slug'));
    // Without any version header (2025-03-26 clients) and with a stale session id: served the same.
    assert_result(legacy('tools/list', [], null), false);
    assert_result(mcp_raw(json_encode(rpc('tools/list')), ['MCP-Protocol-Version: 2025-11-25', 'Mcp-Session-Id: abc123', 'Last-Event-ID: 7']), false);
});

test('mcp legacy: errors', function () {
    assert_rpc_error(legacy('resources/list'), 200, -32601);
    assert_rpc_error(legacy('tools/call', ['name' => 'nope']), 200, -32602);
    $e = assert_rpc_error(legacy('tools/list', [], '1999-01-01'), 400, -32022);
    assert_same('1999-01-01', $e['data']['requested']);
    assert_same([], assert_result(legacy('ping', [], '2025-03-26', 'abc'), false, 'abc'), 'string ids');
});

// ---------------------------------------------------------------- transport

test('mcp transport: methods, preflight, body format and size', function () {
    foreach (['GET', 'DELETE', 'PUT', 'HEAD'] as $m) {
        $r = http($m, mcp_server()->base . '/mcp', ['Accept: text/event-stream']);
        assert_same(405, $r['status'], $m);
        assert_same('POST, OPTIONS', $r['headers']['allow'] ?? null, "$m Allow");
        assert_mcp_headers($r);
    }
    $p = http('OPTIONS', mcp_server()->base . '/mcp', ['Origin: https://example.com', 'Access-Control-Request-Method: POST', 'Access-Control-Request-Headers: content-type, mcp-protocol-version, mcp-method, mcp-name']);
    assert_same(204, $p['status']);
    assert_same('*', $p['headers']['access-control-allow-origin'] ?? null);
    assert_same('POST, OPTIONS', $p['headers']['access-control-allow-methods'] ?? null);
    assert_same('content-type, mcp-protocol-version, mcp-method, mcp-name', $p['headers']['access-control-allow-headers'] ?? null);

    assert_rpc_error(mcp_raw('{"jsonrpc": "2.0", "id": 1, "method": '), 400, -32700, null);
    assert_false(array_key_exists('id', mcp_raw('{nope')['json']), 'no id when it could not be read');
    assert_contains('Batch', assert_rpc_error(mcp_raw(json_encode([rpc('ping'), rpc('ping', [], 2)])), 400, -32600, null)['message']);
    assert_rpc_error(mcp_raw('"just a string"'), 400, -32600, null);
    assert_rpc_error(mcp_raw('{"jsonrpc":"1.0","id":1,"method":"ping"}'), 400, -32600, null);
    assert_rpc_error(mcp_raw('{"jsonrpc":"2.0","id":1}'), 400, -32600, null);
    assert_rpc_error(mcp_raw('{"jsonrpc":"2.0","id":null,"method":"ping"}'), 400, -32600, null);
    assert_rpc_error(mcp_raw('{"jsonrpc":"2.0","id":1,"method":"ping","params":[1]}'), 400, -32600, 1);
    assert_rpc_error(mcp_raw('{"jsonrpc":"2.0","id":1,"result":{}}'), 400, -32600, null);
    $r = http('POST', mcp_server()->base . '/mcp', ['Content-Type: text/plain'], json_encode(rpc('ping')));
    assert_rpc_error($r, 415, -32600, null);
    $big = json_encode(rpc('ping', ['pad' => str_repeat('x', McpServer::MAX_BODY)]));
    assert_rpc_error(mcp_raw($big), 413, -32600, null);
    assert_result(mcp_raw(json_encode(rpc('ping', ['pad' => str_repeat('x', 60000)]))), false);
});

test('mcp transport: origin policy, prefixed mount, robots, rate limit', function () {
    assert_result(mcp_raw(json_encode(rpc('ping')), ['Origin: https://example.com']), false);
    assert_result(mcp_raw(json_encode(rpc('ping')), ['Origin: null']), false);
    assert_result(mcp_raw(json_encode(rpc('ping')), ['Origin: http://localhost:5173']), false);
    assert_rpc_error(mcp_raw(json_encode(rpc('ping')), ['Origin: javascript:alert(1)']), 403, -32600, null);
    assert_rpc_error(mcp_raw(json_encode(rpc('ping')), ['Origin: https://a.example/path']), 403, -32600, null);

    $ps = mcp_server('10000,10000,10000', '/free-datasets');
    $r = modern('tools/call', ['name' => 'search_datasets', 'arguments' => ['q' => 'earthquake']], [], $ps);
    assert_same($ps->base . '/free-datasets/d/usgs-earthquakes', tool_ok($r)['items'][0]['html_url']);
    assert_contains("Disallow: /free-datasets/mcp\n", http('GET', $ps->base . '/free-datasets/robots.txt')['body']);

    $limited = mcp_server('2,100,100');
    for ($i = 0; $i < 2; $i++) {
        assert_same(200, mcp_raw(json_encode(rpc('ping')), [], $limited)['status']);
    }
    $r = mcp_raw(json_encode(rpc('ping')), [], $limited);
    assert_rpc_error($r, 429, -31429, null);
    assert_true((int) ($r['headers']['retry-after'] ?? 0) >= 1, 'Retry-After');
});

test('mcp: limits and the REST parameter rules stay in sync', function () {
    assert_true(\Datasets\Mcp\Tools::MAX_LIMIT <= ListParams::MAX_LIMIT);
    assert_same(['q', 'category', 'tag', 'keyless', 'browser', 'has_openapi'], array_values(array_intersect(ListParams::FILTERS, ['q', 'category', 'tag', 'keyless', 'browser', 'has_openapi'])));
});

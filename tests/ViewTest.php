<?php

declare(strict_types=1);

use Datasets\Web\View;

test('view: curl command includes method, headers, required headers and body', function () {
    $d = [
        'api' => ['required_headers' => [['name' => 'User-Agent', 'example' => 'my-app/1.0'], ['name' => 'Accept', 'example' => 'ignored/when-in-example']]],
        'example' => ['method' => 'POST', 'url' => 'https://example.org/api?a=1&b=2', 'headers' => ['accept' => 'application/json'], 'body' => "data=[out:json];node(1,2,3,4);out 'x';"],
    ];
    assert_same(
        "curl -X POST 'https://example.org/api?a=1&b=2' \\\n  -H 'accept: application/json' \\\n  -H 'User-Agent: my-app/1.0' \\\n  --data 'data=[out:json];node(1,2,3,4);out '\\''x'\\'';'",
        View::curl($d),
    );
    assert_same('curl https://example.org/x', View::curl(['api' => [], 'example' => ['method' => 'GET', 'url' => 'https://example.org/x']]));
});

test('view: response excerpts are pretty-printed only when they are JSON', function () {
    $r = View::excerpt('{"a":{"b":[1,2.0]},"u":"https://x/y","e":{}}');
    assert_true($r['json']);
    assert_same("{\n  \"a\": {\n    \"b\": [\n      1,\n      2.0\n    ]\n  },\n  \"u\": \"https://x/y\",\n  \"e\": {}\n}", $r['text']);
    $r = View::excerpt("{\"truncated\": [1, 2, ...");
    assert_false($r['json']);
    assert_same("{\"truncated\": [1, 2, ...", $r['text']);
});

test('view: schema fields are flattened with required flags', function () {
    $rows = View::schemaFields(['required' => ['a'], 'properties' => [
        'a' => ['type' => 'object', 'required' => ['b'], 'properties' => ['b' => ['enum' => ['x', 'y'], 'description' => 'B']]],
        'c' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['d' => ['type' => 'string', 'format' => 'uri']]]],
    ]]);
    assert_same(['a', 'a.b', 'c', 'c[].d'], array_column($rows, 'path'));
    assert_same([true, true, false, false], array_column($rows, 'required'));
    assert_same(['x', 'y'], $rows[1]['enum']);
    assert_same('array of objects', $rows[2]['type']);
    assert_same('string (uri)', $rows[3]['type']);
});

test('http: home lists everything, keeps filters and shows the API equivalent', function () {
    $base = server()->base;
    $r = get('/');
    assert_same(4, substr_count($r['body'], '<li class="row">'));
    assert_same(2, substr_count(get('/?limit=2')['body'], '<li class="row">'), 'an explicit limit is honoured');
    assert_contains('<span class="verb">GET</span> ' . $base . '/api/v1/datasets</code>', $r['body']);
    $r = get('/?keyless=true&tag=weather&cors=any');
    assert_contains('name="keyless" value="true" checked', $r['body']);
    assert_contains('<input type="hidden" name="tag" value="weather">', $r['body'], 'filters without a visible control survive a form submit');
    assert_contains('<input type="hidden" name="cors" value="any">', $r['body']);
    assert_contains(e($base . '/api/v1/datasets?cors=any&tag=weather&keyless=true'), $r['body']);
    assert_contains('No dataset matches', get('/?q=zzzz-nothing')['body']);
});

test('http: dataset page has the Try it section (curl, excerpt), JSON alternate and JSON-LD', function () {
    $base = server()->base;
    $r = get('/d/wikidata');
    assert_contains('<link rel="alternate" type="application/json" href="' . $base . '/api/v1/datasets/wikidata"', $r['body']);
    assert_contains('<script type="application/ld+json">', $r['body']);
    assert_contains('-H &apos;User-Agent: my-app/1.0 (me@example.org)&apos;', $r['body'], 'required header added to curl');
    assert_contains('<section class="section" id="example" aria-labelledby="h-example">', $r['body']);
    assert_contains('<pre id="curl"><code>curl ', $r['body'], 'curl command in the no-JS markup');
    assert_contains('<pre id="response" class="response"><code>', $r['body'], 'recorded excerpt');
    assert_contains('data-explorer hidden', $r['body'], 'explorer mount (enabled for a keyless record)');
    preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $r['body'], $m);
    $ld = json_decode($m[1] ?? '', true);
    assert_same(['ItemPage', 'Dataset', 'WebAPI', 'BreadcrumbList'], array_column($ld['@graph'] ?? [], '@type'));
    assert_contains('Free API key', get('/d/nasa-apod')['body']);
});

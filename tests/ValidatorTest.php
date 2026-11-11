<?php

declare(strict_types=1);

use Datasets\Schema\UnsupportedSchemaException;
use Datasets\Schema\Validator;

$schemaFile = dirname(__DIR__) . '/schema/dataset.schema.json';

test('validator: the real schema only uses supported keywords', function () use ($schemaFile) {
    Validator::fromFile($schemaFile);
    assert_true(true);
});

test('validator: every valid fixture passes', function () use ($schemaFile) {
    $v = Validator::fromFile($schemaFile);
    foreach (glob(fixtures() . '/datasets/*.json') as $f) {
        $errors = $v->validateJson((string) file_get_contents($f));
        assert_same([], array_map('strval', $errors), basename($f));
    }
});

test('validator: invalid documents are reported at the right pointer', function () use ($schemaFile) {
    $v = Validator::fromFile($schemaFile);
    $base = fixture('open-meteo');

    // [description, mutation, expected pointer, expected keyword]
    $cases = [
        ['missing required', function ($d) { unset($d->summary); }, '/summary', 'required'],
        ['unknown top-level property', fn ($d) => $d->foo = 'bar', '/foo', 'additionalProperties'],
        ['unknown nested property', fn ($d) => $d->api->auth->token = 'x', '/api/auth/token', 'additionalProperties'],
        ['wrong type', fn ($d) => $d->name = 42, '/name', 'type'],
        ['object expected', fn ($d) => $d->provider = 'NASA', '/provider', 'type'],
        ['array expected', fn ($d) => $d->tags = 'weather', '/tags', 'type'],
        ['enum', fn ($d) => $d->api->style = 'soap', '/api/style', 'enum'],
        ['enum in items', fn ($d) => $d->api->formats = ['json', 'bson'], '/api/formats/1', 'enum'],
        ['minItems', fn ($d) => $d->tags = ['weather'], '/tags', 'minItems'],
        ['maxItems', fn ($d) => $d->tags = ['a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8', 'a9'], '/tags', 'maxItems'],
        ['uniqueItems', fn ($d) => $d->tags = ['weather', 'climate', 'weather'], '/tags/2', 'uniqueItems'],
        ['uniqueItems on enum list', fn ($d) => $d->api->formats = ['json', 'csv', 'json'], '/api/formats/2', 'uniqueItems'],
        ['uniqueItems on related', fn ($d) => $d->related = ['wikidata', 'wikidata'], '/related/1', 'uniqueItems'],
        ['pattern', fn ($d) => $d->tags = ['Weather Data', 'climate'], '/tags/0', 'pattern'],
        ['slug pattern', fn ($d) => $d->slug = 'Open_Meteo', '/slug', 'pattern'],
        ['https pattern on base_url', fn ($d) => $d->api->base_url = 'http://api.open-meteo.com/v1', '/api/base_url', 'pattern'],
        ['minLength', fn ($d) => $d->summary = 'Too short.', '/summary', 'minLength'],
        ['maxLength', fn ($d) => $d->name = str_repeat('x', 81), '/name', 'maxLength'],
        ['maxLength is multibyte-aware', fn ($d) => $d->name = str_repeat('é', 81), '/name', 'maxLength'],
        ['format uri', fn ($d) => $d->links->homepage = 'open-meteo.com', '/links/homepage', 'format'],
        ['format uri rejects spaces', fn ($d) => $d->links->docs = 'https://open-meteo.com/en/my docs', '/links/docs', 'format'],
        ['format uri needs a host', fn ($d) => $d->links->terms = 'https:///terms', '/links/terms', 'format'],
        ['format date', fn ($d) => $d->added = '2026-02-30', '/added', 'format'],
        ['format date shape', fn ($d) => $d->verification->last_verified = '1 Nov 2026', '/verification/last_verified', 'format'],
        ['minimum', fn ($d) => $d->verification->http_status = 99, '/verification/http_status', 'minimum'],
        ['maximum', fn ($d) => $d->verification->http_status = 600, '/verification/http_status', 'maximum'],
        ['integer', fn ($d) => $d->api->rate_limit->requests = 1.5, '/api/rate_limit/requests', 'type'],
        ['dependentRequired requests->per', function ($d) { unset($d->api->rate_limit->per); }, '/api/rate_limit/per', 'dependentRequired'],
        ['dependentRequired per->requests', function ($d) { unset($d->api->rate_limit->requests); }, '/api/rate_limit/requests', 'dependentRequired'],
        ['additionalProperties schema', fn ($d) => $d->example->headers = (object) ['X-Num' => 5], '/example/headers/X-Num', 'type'],
        ['items schema', fn ($d) => $d->endpoints[0]->method = 'PUT', '/endpoints/0/method', 'enum'],
        ['required in items', function ($d) { unset($d->endpoints[1]->summary); }, '/endpoints/1/summary', 'required'],
        ['hn url pattern', fn ($d) => $d->hn = [(object) ['url' => 'https://example.com/item?id=1']], '/hn/0/url', 'pattern'],
        ['boolean type', fn ($d) => $d->verification->with_key = 'yes', '/verification/with_key', 'type'],
        ['empty endpoints', fn ($d) => $d->endpoints = [], '/endpoints', 'minItems'],
    ];

    foreach ($cases as [$label, $mutate, $pointer, $keyword]) {
        $d = deep_copy($base);
        $mutate($d);
        assert_has_error($v->validate($d), $pointer, $keyword, $label);
    }
});

test('validator: multibyte strings within limits pass', function () use ($schemaFile) {
    $v = Validator::fromFile($schemaFile);
    $d = fixture('open-meteo');
    $d->name = str_repeat('€', 80); // 240 bytes, 80 code points
    assert_same([], array_map('strval', $v->validate($d)));
    assert_same(3, Validator::length('aé€'));
});

test('validator: integral floats count as integers', function () use ($schemaFile) {
    $v = Validator::fromFile($schemaFile);
    $d = fixture('open-meteo');
    $d->verification->http_status = 200.0;
    assert_same([], array_map('strval', $v->validate($d)));
});

test('validator: invalid JSON is reported at the root', function () use ($schemaFile) {
    $errors = Validator::fromFile($schemaFile)->validateJson('{"slug": ');
    assert_same(1, count($errors));
    assert_same('', $errors[0]->pointer);
    assert_same('json', $errors[0]->keyword);
});

test('validator: root must be an object', function () use ($schemaFile) {
    assert_has_error(Validator::fromFile($schemaFile)->validateJson('[1,2]'), '', 'type');
});

test('validator: pointers escape ~ and /', function () {
    $v = new Validator(['type' => 'object', 'additionalProperties' => false]);
    $errors = $v->validate((object) ['a/b' => 1, 'c~d' => 2]);
    assert_has_error($errors, '/a~1b', 'additionalProperties');
    assert_has_error($errors, '/c~0d', 'additionalProperties');
});

test('validator: const, enum deep equality and type lists', function () {
    $v = new Validator([
        'type' => 'object',
        'properties' => [
            'c' => ['const' => ['a' => 1]],
            'e' => ['enum' => [1, 'x', null]],
            't' => ['type' => ['string', 'null']],
        ],
    ]);
    assert_same([], $v->validate((object) ['c' => (object) ['a' => 1], 'e' => null, 't' => null]));
    assert_same([], $v->validate((object) ['e' => 1.0, 't' => 's']));
    $errors = $v->validate((object) ['c' => (object) ['a' => 2], 'e' => 'y', 't' => 3]);
    assert_has_error($errors, '/c', 'const');
    assert_has_error($errors, '/e', 'enum');
    assert_has_error($errors, '/t', 'type');
});

test('validator: associative arrays are accepted as objects', function () {
    $v = new Validator(['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'object', 'required' => ['b']]]]);
    assert_same([], $v->validate(['a' => ['b' => 1]]));
    assert_has_error($v->validate(['a' => []]), '/a/b', 'required');
});

test('validator: unknown keywords throw', function () {
    assert_throws(UnsupportedSchemaException::class, fn () => new Validator(['type' => 'object', 'oneOf' => [['type' => 'string']]]));
    assert_throws(UnsupportedSchemaException::class, fn () => new Validator(['properties' => ['a' => ['type' => 'string', 'contentEncoding' => 'base64']]]));
    assert_throws(UnsupportedSchemaException::class, fn () => new Validator(['items' => ['$ref' => '#/x']]));
    assert_throws(UnsupportedSchemaException::class, fn () => new Validator(['additionalProperties' => ['patternProperties' => []]]));
    assert_throws(UnsupportedSchemaException::class, fn () => new Validator(['format' => 'email']));
    assert_throws(UnsupportedSchemaException::class, fn () => new Validator(['type' => 'float']));
    // annotations are fine
    new Validator(['$schema' => 'x', '$id' => 'y', 'title' => 't', 'description' => 'd', '$comment' => 'c', 'examples' => [1]]);
});

test('validator: uniqueItems compares objects structurally (key order irrelevant)', function () {
    $v = new Validator(['type' => 'array', 'uniqueItems' => true]);
    assert_has_error($v->validate(json_decode('[{"a":1,"b":2},{"b":2,"a":1}]')), '/1', 'uniqueItems');
    assert_same([], $v->validate(json_decode('[{"a":1},{"a":2},1,"1"]')));
});

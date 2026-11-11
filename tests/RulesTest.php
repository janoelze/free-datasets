<?php

declare(strict_types=1);

use Datasets\Schema\DatasetRules;
use Datasets\Schema\Linter;

function rules(): DatasetRules
{
    $cats = json_decode((string) file_get_contents(dirname(__DIR__) . '/schema/categories.json'), true);

    return new DatasetRules(array_column($cats, 'id'), '2026-11-11');
}

$known = ['open-meteo' => true, 'usgs-earthquakes' => true, 'nasa-apod' => true, 'wikidata' => true];

test('rules: valid fixtures pass', function () use ($known) {
    foreach (array_keys($known) as $slug) {
        assert_same([], array_map('strval', rules()->check(fixture($slug, true), $slug, $known)), $slug);
    }
});

test('rules: slug must equal file name', function () use ($known) {
    assert_has_error(rules()->check(fixture('open-meteo', true), 'open-meteo-2', $known), '/slug', 'x-slug-filename');
});

test('rules: category must exist', function () {
    $d = fixture('open-meteo', true);
    $d['category'] = 'weather';
    assert_has_error(rules()->check($d, 'open-meteo'), '/category', 'x-category');
});

test('rules: related slugs must exist (and not be self)', function () use ($known) {
    $d = fixture('open-meteo', true);
    $d['related'] = ['wikidata', 'does-not-exist', 'open-meteo'];
    $errors = rules()->check($d, 'open-meteo', $known);
    assert_has_error($errors, '/related/1', 'x-related');
    assert_has_error($errors, '/related/2', 'x-related');
    assert_same(2, count($errors));
    // skipped without a slug set
    assert_same([], rules()->check($d, 'open-meteo', null));
});

test('rules: api_key requires key_in and links.signup', function () {
    $d = fixture('open-meteo', true);
    $d['api']['auth'] = ['type' => 'api_key'];
    $errors = rules()->check($d, 'open-meteo');
    assert_has_error($errors, '/api/auth/key_in', 'x-auth');
    assert_has_error($errors, '/links/signup', 'x-auth');
    $d['api']['auth']['key_in'] = 'header';
    $d['links']['signup'] = 'https://open-meteo.com/en/pricing';
    assert_same([], rules()->check($d, 'open-meteo'));
});

test('rules: key_optional only with auth none', function () {
    $d = fixture('nasa-apod', true);
    $d['api']['auth']['key_optional'] = true;
    assert_has_error(rules()->check($d, 'nasa-apod'), '/api/auth/key_optional', 'x-auth');
    $d = fixture('open-meteo', true);
    $d['api']['auth']['key_optional'] = true;
    assert_same([], rules()->check($d, 'open-meteo'));
});

test('rules: dates must not be in the future', function () {
    $d = fixture('open-meteo', true);
    $d['added'] = '2026-11-12';
    $d['verification']['last_verified'] = '2027-01-01';
    $errors = rules()->check($d, 'open-meteo');
    assert_has_error($errors, '/added', 'x-date');
    assert_has_error($errors, '/verification/last_verified', 'x-date');
    $d['added'] = '2026-11-11';
    $d['verification']['last_verified'] = '2026-11-11';
    assert_same([], rules()->check($d, 'open-meteo'));
});

test('rules: http is allowed nowhere', function () {
    $d = fixture('open-meteo', true);
    $d['links']['terms'] = 'http://open-meteo.com/en/terms';
    $d['example']['url'] = 'HTTP://api.open-meteo.com/v1/forecast';
    $d['provider']['url'] = 'http://open-meteo.com/';
    $d['data']['license']['url'] = 'http://creativecommons.org/licenses/by/4.0/';
    $d['endpoints'][1]['path'] = 'http://archive-api.open-meteo.com/v1/archive';
    $errors = rules()->check($d, 'open-meteo');
    foreach (['/links/terms', '/example/url', '/provider/url', '/data/license/url', '/endpoints/1/path'] as $p) {
        assert_has_error($errors, $p, 'x-https');
    }
    // http inside free text is fine
    $d = fixture('wikidata', true);
    assert_contains('http://www.wikidata.org', $d['example']['response_excerpt']);
    assert_same([], rules()->check($d, 'wikidata'));
});

test('rules: robust against schema-invalid shapes', function () {
    $errors = rules()->check(['slug' => 5, 'api' => 'x', 'links' => 'y', 'endpoints' => 'z'], 'a');
    assert_has_error($errors, '/slug', 'x-slug-filename');
});

test('linter: directory lint reports schema and rule errors', function () {
    $results = Linter::fromSchemaDir(dirname(__DIR__) . '/schema', '2026-11-11')->lintDirectory(fixtures() . '/invalid');
    $byName = [];
    foreach ($results as $file => $r) {
        $byName[basename($file)] = $r;
    }
    assert_same(['broken.json', 'usgs-earthquakes.json', 'wrong-name.json'], array_keys($byName));
    assert_same([], $byName['usgs-earthquakes.json']['errors']);
    assert_true(is_array($byName['usgs-earthquakes.json']['data']));
    assert_has_error($byName['broken.json']['errors'], '', 'json');
    $wn = $byName['wrong-name.json']['errors'];
    assert_has_error($wn, '/tags/1', 'uniqueItems');
    assert_has_error($wn, '/slug', 'x-slug-filename');
    assert_has_error($wn, '/category', 'x-category');
    assert_has_error($wn, '/links/docs', 'x-https');
    assert_same(null, $byName['wrong-name.json']['data']);
});

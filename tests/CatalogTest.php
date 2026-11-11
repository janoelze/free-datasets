<?php

declare(strict_types=1);

use Datasets\Catalog;
use Datasets\Json;
use Datasets\Url;

function catalog(): Catalog
{
    static $c = null;

    return $c ??= Catalog::load(fixtures() . '/datasets', dirname(__DIR__) . '/schema');
}

function slugs(array $datasets): array
{
    return array_map(static fn (array $d) => $d['slug'], $datasets);
}

test('catalog: loads fixtures sorted by slug', function () {
    $c = catalog();
    assert_same('files', $c->source());
    assert_same(4, $c->count());
    assert_same(['nasa-apod', 'open-meteo', 'usgs-earthquakes', 'wikidata'], $c->slugs());
    assert_same('Open-Meteo', $c->get('open-meteo')['name']);
    assert_same(null, $c->get('nope'));
    assert_true($c->has('wikidata'));
    assert_same([], $c->skipped());
});

test('catalog: invalid files are skipped when loading from files', function () {
    $c = Catalog::load(fixtures() . '/invalid', dirname(__DIR__) . '/schema');
    assert_same(['usgs-earthquakes'], $c->slugs());
    assert_same(2, count($c->skipped()));
});

test('catalog: full-text search, all terms must match', function () {
    $c = catalog();
    assert_same(['usgs-earthquakes'], slugs($c->filter(['q' => 'EARTHQUAKE'])));
    assert_same(['open-meteo'], slugs($c->filter(['q' => 'weather historical'])));
    assert_same([], slugs($c->filter(['q' => 'weather sparql'])));
    assert_same(['wikidata'], slugs($c->filter(['q' => 'Wikimedia'])), 'provider name');
    assert_same(['open-meteo'], slugs($c->filter(['q' => 'air-quality'])), 'tag');
    assert_same(['usgs-earthquakes'], slugs($c->filter(['q' => 'count matching'])), 'endpoint summary');
    assert_same(['open-meteo'], slugs($c->filter(['q' => 'MÉTÉO-FRANCE'])), 'multibyte case folding');
});

test('catalog: facet filters (OR within, AND across)', function () {
    $c = catalog();
    assert_same(['open-meteo', 'usgs-earthquakes'], slugs($c->filter(['category' => 'weather-climate,earth-environment'])));
    assert_same(['open-meteo', 'usgs-earthquakes'], slugs($c->filter(['category' => ['weather-climate', 'earth-environment']])));
    assert_same(['nasa-apod'], slugs($c->filter(['auth' => 'api_key'])));
    assert_same(['wikidata'], slugs($c->filter(['cors' => ['reflect']])));
    assert_same(['open-meteo', 'usgs-earthquakes', 'wikidata'], slugs($c->filter(['format' => 'csv'])));
    assert_same(['usgs-earthquakes'], slugs($c->filter(['format' => 'geojson'])));
    assert_same(['wikidata'], slugs($c->filter(['style' => 'sparql'])));
    assert_same(['nasa-apod', 'usgs-earthquakes'], slugs($c->filter(['provider_type' => 'government'])));
    assert_same(['usgs-earthquakes', 'wikidata'], slugs($c->filter(['commercial_use' => 'allowed'])));
    assert_same(['usgs-earthquakes'], slugs($c->filter(['commercial_use' => 'allowed', 'format' => 'geojson,json-ld'])));
    assert_same(4, count($c->filter(['status' => 'active'])));
});

test('catalog: tag filter is AND', function () {
    $c = catalog();
    assert_same(['open-meteo'], slugs($c->filter(['tag' => 'weather,forecast'])));
    assert_same([], slugs($c->filter(['tag' => ['weather', 'astronomy']])));
});

test('catalog: boolean filters', function () {
    $c = catalog();
    assert_same(['open-meteo', 'usgs-earthquakes', 'wikidata'], slugs($c->filter(['keyless' => true])));
    assert_same(['nasa-apod'], slugs($c->filter(['keyless' => 'false'])));
    assert_same(4, count($c->filter(['browser' => true])));
    assert_same(['open-meteo'], slugs($c->filter(['has_openapi' => true])));
    assert_same(3, count($c->filter(['has_openapi' => false])));
});

test('catalog: sorting', function () {
    $c = catalog();
    $all = $c->all();
    assert_same(['nasa-apod', 'open-meteo', 'usgs-earthquakes', 'wikidata'], slugs($c->sort($all, 'name')));
    assert_same(['wikidata', 'usgs-earthquakes', 'open-meteo', 'nasa-apod'], slugs($c->sort($all, '-name')));
    assert_same(['open-meteo', 'wikidata', 'usgs-earthquakes', 'nasa-apod'], slugs($c->sort($all, 'added')));
    assert_same(['nasa-apod', 'usgs-earthquakes', 'wikidata', 'open-meteo'], slugs($c->sort($all, '-added')));
    assert_same(['usgs-earthquakes', 'wikidata', 'open-meteo', 'nasa-apod'], slugs($c->sort($all, '-verified')));
    assert_throws(InvalidArgumentException::class, fn () => $c->sort($all, 'popularity'));
});

test('catalog: pagination', function () {
    $c = catalog();
    $p = $c->query([], 'name', 3, 0);
    assert_same(4, $p->total);
    assert_same(3, $p->count());
    assert_false($p->hasPrev());
    assert_same(3, $p->nextOffset());
    assert_same(3, $p->lastOffset());
    assert_same(2, $p->pageCount());
    $p = $c->query([], 'name', 3, 3);
    assert_same(['wikidata'], slugs($p->items));
    assert_same(0, $p->prevOffset());
    assert_same(null, $p->nextOffset());
    $p = $c->query([], 'name', 2, 10);
    assert_same([], $p->items);
    assert_same(4, $p->total);
    $p = $c->query(['q' => 'nothing-matches-this'], 'name', 10, 0);
    assert_same(0, $p->lastOffset());
});

test('catalog: facets, tags, categories, related', function () {
    $c = catalog();
    $f = $c->facets();
    assert_same(['category', 'tag', 'auth', 'cors', 'format', 'style', 'provider_type', 'commercial_use', 'status'], array_keys($f));
    assert_same(['none' => 3, 'api_key' => 1, 'oauth2' => 0], $f['auth']);
    assert_same(1, $f['category']['weather-climate']);
    assert_same(0, $f['category']['sports']);
    assert_same(3, $f['format']['json']);
    assert_same(3, $f['format']['csv']);
    assert_same(['any' => 3, 'reflect' => 1, 'restricted' => 0, 'none' => 0, 'unknown' => 0], $f['cors']);
    assert_same(1, $f['tag']['weather']);
    $sub = $c->facets($c->filter(['keyless' => true]));
    assert_same(0, $sub['auth']['api_key']);

    $tags = $c->tags();
    assert_same(array_keys($tags), array_keys($tags), 'stable');
    assert_same(13, count($tags));
    assert_same('air-quality', array_key_first($tags), 'ties alphabetical');

    $cats = $c->categories();
    assert_same(count(json_decode((string) file_get_contents(dirname(__DIR__) . '/schema/categories.json'), true)), count($cats));
    assert_same('weather-climate', $cats[0]['id']);
    assert_same(1, $cats[0]['count']);
    assert_same('Weather & Climate', $c->category('weather-climate')['name']);
    assert_same(null, $c->category('nope'));
    assert_same(['usgs-earthquakes'], slugs($c->related('open-meteo')));
    assert_same([], $c->related('nasa-apod'));

    $enums = $c->enums();
    assert_same(['none', 'api_key', 'oauth2'], $enums['auth']);
    assert_same(count(json_decode((string) file_get_contents(dirname(__DIR__) . '/schema/categories.json'), true)), count($enums['category']));
});

test('catalog: compiled cache is used only when fresh and from the same directory', function () {
    $dir = temp_dir();
    mkdir("$dir/datasets");
    foreach (glob(fixtures() . '/datasets/*.json') as $f) {
        copy($f, "$dir/datasets/" . basename($f));
        touch("$dir/datasets/" . basename($f), time() - 100);
    }
    touch("$dir/datasets", time() - 100);
    $schemaDir = dirname(__DIR__) . '/schema';
    $cache = "$dir/var/catalog.json";
    $c = Catalog::load("$dir/datasets", $schemaDir, $cache);
    Catalog::writeCache($cache, "$dir/datasets", array_reverse($c->all()));
    $compiled = json_decode((string) file_get_contents($cache), true);
    assert_same(['nasa-apod', 'open-meteo', 'usgs-earthquakes', 'wikidata'], slugs($compiled['datasets']), 'sorted by slug');

    // schema files may be newer than "now - 100" in a fresh checkout; pin them via a copy
    $schemaCopy = "$dir/schema";
    mkdir($schemaCopy);
    foreach (glob("$schemaDir/*.json") as $f) {
        copy($f, "$schemaCopy/" . basename($f));
        touch("$schemaCopy/" . basename($f), time() - 100);
    }
    assert_same('cache', Catalog::load("$dir/datasets", $schemaCopy, $cache)->source());
    assert_same('files', Catalog::load(fixtures() . '/datasets', $schemaCopy, $cache)->source(), 'different datasets dir');

    touch("$dir/datasets/wikidata.json", time() + 5);
    assert_same('files', Catalog::load("$dir/datasets", $schemaCopy, $cache)->source(), 'stale after edit');
});

test('json: encodeFile uses 2-space indent, unescaped slashes/unicode, keeps {} and key order', function () {
    $doc = json_decode('{"b":1,"a":{"url":"https://x.org/ä","h":{},"l":[]},"f":1.0}', false);
    $out = Json::encodeFile($doc);
    $expected = "{\n  \"b\": 1,\n  \"a\": {\n    \"url\": \"https://x.org/ä\",\n    \"h\": {},\n    \"l\": []\n  },\n  \"f\": 1.0\n}\n";
    assert_same($expected, $out);
});

test('json: objectify restores empty objects from the schema', function () {
    $schema = catalog()->schema();
    $d = fixture('open-meteo', true);
    assert_same([], $d['example']['headers']);
    $o = Json::objectify($d, $schema);
    assert_true($o['example']['headers'] instanceof stdClass);
    assert_contains('"headers": {}', Json::encode($o));
    assert_contains('"required_headers": []', Json::encode(Json::objectify(fixture('nasa-apod', true), $schema)));
});

test('url: base-path aware helpers', function () {
    $u = new Url('/free-datasets', 'https://endtime-instruments.org/free-datasets', dirname(__DIR__) . '/public');
    assert_same('/free-datasets/d/open-meteo', $u->path('/d/open-meteo'));
    assert_same('/free-datasets/', $u->path('/'));
    assert_same('/free-datasets/?category=a,b&keyless=true', $u->path('/', ['category' => ['a', 'b'], 'keyless' => true, 'x' => null]));
    assert_same('https://endtime-instruments.org/free-datasets/api/v1', $u->absolute('/api/v1'));
    assert_same('https://endtime-instruments.org/free-datasets/', $u->absolute('/'));
    assert_same('/free-datasets/assets/missing.css', $u->asset('assets/missing.css'));
    assert_same('/free-datasets/.htaccess?v=' . filemtime(dirname(__DIR__) . '/public/.htaccess'), $u->asset('.htaccess'));
    Url::setCurrent($u);
    assert_same('/free-datasets/about', url('/about'));
    assert_same('https://endtime-instruments.org/free-datasets/d/x', abs_url('/d/x'));
    assert_same('&lt;a href=&quot;x&quot;&gt;', e('<a href="x">'));
    assert_same('', Url::normaliseBasePath('/'));
    assert_same('/a/b', Url::normaliseBasePath('a/b/'));
});

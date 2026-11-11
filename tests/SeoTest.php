<?php

declare(strict_types=1);

/*
 * Search-engine plumbing: index policy (canonical/robots per URL), landing pages, sitemap, feed,
 * JSON-LD, titles/descriptions and the HTTP rules around them. Uses server() / prefixed_server()
 * from HttpTest.php (4 fixture datasets) and real_server() (the real datasets/ directory) where
 * the fixtures are too small (tag pages need 3+ datasets).
 */

use Datasets\Catalog;
use Datasets\Config;
use Datasets\Seo\IndexPolicy;
use Datasets\Seo\Meta;
use Datasets\Seo\StructuredData;

function real_server(): TestServer
{
    static $s = null;

    return $s ??= new TestServer([
        'APP_DATASETS_DIR' => dirname(__DIR__) . '/datasets',
        'APP_CACHE_FILE' => sys_get_temp_dir() . '/datasets-test-nocache.json',
        'APP_BASE_PATH' => '/datasets',
        'APP_PUBLIC_URL' => '',
    ]);
}

function real_catalog(): Catalog
{
    static $c = null;

    return $c ??= Catalog::load(dirname(__DIR__) . '/datasets', dirname(__DIR__) . '/schema', null);
}

/** @return array{canonical: ?string, robots: ?string, title: ?string, description: ?string, h1: int, ld: list<array<string, mixed>>} */
function seo_head(string $html): array
{
    preg_match_all('~<link rel="canonical" href="([^"]*)">~', $html, $c);
    preg_match('~<meta name="robots" content="([^"]*)">~', $html, $r);
    preg_match('~<title>([^<]*)</title>~', $html, $t);
    preg_match('~<meta name="description" content="([^"]*)">~', $html, $d);
    preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $ld);
    if (count($c[1]) > 1) {
        fail('more than one canonical');
    }
    $graph = [];
    foreach ($ld[1] as $block) {
        $j = json_decode($block, true, 512, JSON_THROW_ON_ERROR);
        assert_same('https://schema.org', $j['@context'] ?? null, 'JSON-LD @context');
        array_push($graph, ...($j['@graph'] ?? [$j]));
    }

    return [
        'canonical' => isset($c[1][0]) ? html_entity_decode($c[1][0], ENT_QUOTES | ENT_HTML5) : null,
        'robots' => $r[1] ?? null,
        'title' => isset($t[1]) ? html_entity_decode($t[1], ENT_QUOTES | ENT_HTML5) : null,
        'description' => isset($d[1]) ? html_entity_decode($d[1], ENT_QUOTES | ENT_HTML5) : null,
        'h1' => substr_count($html, '<h1'),
        'ld' => $graph,
    ];
}

/** @return array<string, array<string, mixed>> nodes by @type (first of each) */
function ld_types(array $graph): array
{
    $out = [];
    foreach ($graph as $n) {
        $out[(string) $n['@type']] ??= $n;
    }

    return $out;
}

function assert_breadcrumbs(array $node, int $min = 2): void
{
    assert_same('BreadcrumbList', $node['@type']);
    assert_true(count($node['itemListElement']) >= $min, 'breadcrumb depth');
    foreach ($node['itemListElement'] as $i => $item) {
        assert_same('ListItem', $item['@type']);
        assert_same($i + 1, $item['position']);
        assert_true(is_string($item['name']) && $item['name'] !== '', 'crumb name');
        assert_true(str_starts_with($item['item'], 'http'), 'crumb item is absolute');
    }
}

test('seo: canonical and robots policy per query-parameter combination', function () {
    $base = server()->base;
    $cases = [
        // query => [canonical, robots]
        '' => ["$base/", null],
        '?sort=-added' => ["$base/", null],
        '?utm_source=news&fbclid=x' => ["$base/", null],
        '?category=bogus' => ["$base/", null],
        '?category=weather-climate' => ["$base/c/weather-climate", null],
        '?category=weather-climate&sort=-added' => ["$base/c/weather-climate", null],
        '?category=weather-climate,earth-environment' => ["$base/?category=earth-environment,weather-climate", 'noindex,follow'],
        '?category=earth-environment,weather-climate' => ["$base/?category=earth-environment,weather-climate", 'noindex,follow'],
        '?q=weather' => ["$base/?q=weather", 'noindex,follow'],
        '?keyless=true' => ["$base/?keyless=true", 'noindex,follow'],
        '?tag=weather' => ["$base/?tag=weather", 'noindex,follow'], // 1 dataset: thin, no landing page
        '?keyless=true&tag=b,a&category=weather-climate' => ["$base/?category=weather-climate&tag=a,b&keyless=true", 'noindex,follow'],
        '?tag=a,b&category=weather-climate&keyless=true' => ["$base/?category=weather-climate&tag=a,b&keyless=true", 'noindex,follow'],
        '?sort=-added&q=x' => ["$base/?q=x&sort=-added", 'noindex,follow'],
        '?limit=2' => ["$base/?limit=2", 'noindex,follow'],
        '?limit=2&offset=2' => ["$base/?limit=2&offset=2", 'noindex,follow'],
        '?category=weather-climate&limit=2' => ["$base/?category=weather-climate&limit=2", 'noindex,follow'],
    ];
    foreach ($cases as $q => [$canonical, $robots]) {
        $r = get('/' . $q);
        assert_same(200, $r['status'], $q);
        $h = seo_head($r['body']);
        assert_same($canonical, $h['canonical'], "canonical for /$q");
        assert_same($robots, $h['robots'], "robots for /$q");
    }
    // Real data: a single tag with a landing page canonicalises to it.
    $tag = (string) array_key_first(IndexPolicy::landingTags(real_catalog()));
    $rb = real_server()->base;
    $h = seo_head(http('GET', "$rb/datasets/?tag=$tag")['body']);
    assert_same("$rb/datasets/t/$tag", $h['canonical']);
    assert_same(null, $h['robots']);
});

test('seo: home page shows the whole catalogue (no pager) and links categories by clean URL', function () {
    $r = http('GET', real_server()->base . '/datasets/');
    assert_same(real_catalog()->count(), substr_count($r['body'], '<li class="row">'), 'every dataset on one page');
    assert_false(str_contains($r['body'], 'class="pager"'), 'no pager');
    assert_contains('href="/datasets/c/weather-climate" data-filter-link data-filter-href="/datasets/?category=weather-climate"', $r['body']);
    assert_contains('href="/datasets/t"', $r['body']);
    assert_contains('<h2 id="results-count" class="count" aria-live="polite">', $r['body'], 'heading keeps its role');
});

test('seo: category landing pages', function () {
    $base = server()->base;
    $r = get('/c/weather-climate');
    assert_same(200, $r['status']);
    $h = seo_head($r['body']);
    assert_same("$base/c/weather-climate", $h['canonical']);
    assert_same(null, $h['robots']);
    assert_same(1, $h['h1']);
    assert_contains('<h1>Weather &amp; Climate APIs</h1>', $r['body']);
    assert_contains('Forecasts, observations', $r['body'], 'category description as intro');
    assert_same(1, substr_count($r['body'], '<li class="row">'));
    assert_contains('href="/d/open-meteo"', $r['body']);
    assert_contains('href="/c/space-astronomy"', $r['body'], 'sibling categories');
    assert_contains('aria-label="Breadcrumb"', $r['body']);
    assert_same('Free Weather & Climate APIs — 1 dataset | Free Dataset APIs', $h['title']);
    $ld = ld_types($h['ld']);
    assert_same(['CollectionPage', 'BreadcrumbList'], array_keys($ld));
    assert_same(1, $ld['CollectionPage']['mainEntity']['numberOfItems']);
    assert_same("$base/d/open-meteo", $ld['CollectionPage']['mainEntity']['itemListElement'][0]['url']);
    assert_breadcrumbs($ld['BreadcrumbList']);

    assert_same(404, get('/c/nope')['status']);
    assert_same(404, get('/c/energy')['status'], 'a category without datasets has no page');
    $r = get('/c/Weather-Climate');
    assert_same(301, $r['status']);
    assert_same('/c/weather-climate', $r['headers']['location']);

    $p = prefixed_server()->base;
    $r = http('GET', "$p/datasets/c/weather-climate");
    assert_same(200, $r['status']);
    assert_same("$p/datasets/c/weather-climate", seo_head($r['body'])['canonical']);
    assert_contains('href="/datasets/d/open-meteo"', $r['body']);
});

test('seo: tag landing pages and the tag index', function () {
    // Fixtures: every tag is on one dataset only, so none has a page.
    $r = get('/t/weather');
    assert_same(302, $r['status'], 'thin tag -> filter view');
    assert_same('/?tag=weather', $r['headers']['location']);
    assert_same(404, get('/t/no-such-tag')['status']);

    $rb = real_server()->base;
    $tags = IndexPolicy::landingTags(real_catalog());
    assert_true(count($tags) > 10, 'real catalogue has tag pages');
    foreach (array_slice(array_keys($tags), 0, 3) as $tag) {
        $r = http('GET', "$rb/datasets/t/$tag");
        assert_same(200, $r['status'], $tag);
        $h = seo_head($r['body']);
        assert_same("$rb/datasets/t/$tag", $h['canonical']);
        assert_same(null, $h['robots']);
        assert_same(1, $h['h1']);
        assert_same($tags[$tag], substr_count($r['body'], '<li class="row">'), "rows for $tag");
        $ld = ld_types($h['ld']);
        assert_same($tags[$tag], $ld['CollectionPage']['mainEntity']['numberOfItems']);
        assert_breadcrumbs($ld['BreadcrumbList'], 3);
    }
    $r = http('GET', "$rb/datasets/t");
    assert_same(200, $r['status']);
    assert_same(count($tags), substr_count($r['body'], 'href="/datasets/t/'), 'tag index links every tag page');
    $thin = array_key_last(array_filter(real_catalog()->tags(), static fn ($n) => $n < IndexPolicy::MIN_TAG_DATASETS));
    assert_same(302, http('GET', "$rb/datasets/t/$thin")['status']);
});

test('seo: dataset pages link landing pages and carry valid Dataset JSON-LD', function () {
    $base = server()->base;
    $r = get('/d/open-meteo');
    assert_contains('href="/c/weather-climate"', $r['body'], 'category link is the landing page');
    assert_contains('href="/?tag=forecast"', $r['body'], 'thin tag links the filter view');
    $h = seo_head($r['body']);
    assert_same("$base/d/open-meteo", $h['canonical']);
    assert_same('Open-Meteo API — free weather & climate data, no key', $h['title'], 'site suffix dropped past 70 chars');
    $ld = ld_types($h['ld']);
    assert_same(['ItemPage', 'Dataset', 'WebAPI', 'BreadcrumbList'], array_keys($ld));
    $ds = $ld['Dataset'];
    foreach (['name', 'description', 'url', 'license', 'creator', 'keywords', 'isAccessibleForFree', 'includedInDataCatalog', 'sameAs'] as $k) {
        assert_true(isset($ds[$k]), "Dataset.$k");
    }
    assert_true(mb_strlen($ds['description']) >= 50 && mb_strlen($ds['description']) <= 5000, 'Google: description 50-5000 chars');
    assert_same('Organization', $ds['creator']['@type']);
    assert_false(isset($ds['temporalCoverage']), 'free-text coverage is not ISO 8601, so it is left out');
    assert_same('2026-11-01', $ld['ItemPage']['dateModified']);
    assert_same('2026-10-20', $ld['ItemPage']['datePublished']);
    assert_same('https://open-meteo.com/en/docs', $ld['WebAPI']['documentation']);
    assert_breadcrumbs($ld['BreadcrumbList'], 3);
    assert_same("$base/c/weather-climate", $ld['BreadcrumbList']['itemListElement'][1]['item']);

    // Real data: every record yields the required properties.
    $sd = new StructuredData(new Datasets\Url('/datasets', 'https://example.org/datasets'));
    foreach (real_catalog()->all() as $d) {
        $t = ld_types($sd->dataset($d, real_catalog()->category($d['category']), Meta::datasetDescription($d)));
        assert_true(mb_strlen((string) $t['Dataset']['description']) >= 50, $d['slug'] . ' description length');
        assert_true(isset($t['Dataset']['license'], $t['Dataset']['creator']['name']), $d['slug'] . ' license/creator');
        if (isset($t['Dataset']['temporalCoverage'])) {
            assert_true(StructuredData::isIsoInterval($t['Dataset']['temporalCoverage']), $d['slug']);
        }
    }
});

test('seo: home JSON-LD has WebSite + SearchAction, Organization, DataCatalog and an ItemList', function () {
    $base = server()->base;
    $ld = ld_types(seo_head(get('/')['body'])['ld']);
    assert_same(['WebSite', 'Organization', 'DataCatalog', 'CollectionPage'], array_keys($ld));
    assert_same("$base/?q={search_term_string}", $ld['WebSite']['potentialAction']['target']['urlTemplate']);
    assert_same(['@id' => "$base/#organization"], $ld['WebSite']['publisher']);
    assert_same(4, $ld['CollectionPage']['mainEntity']['numberOfItems']);
    assert_same([], seo_head(get('/?q=x')['body'])['ld'], 'no JSON-LD on noindex filter views');
    assert_true(StructuredData::isIsoInterval('1940/..'));
    assert_true(StructuredData::isIsoInterval('2010-01/2020-12-31'));
    assert_false(StructuredData::isIsoInterval('1940–present'));
});

test('seo: sitemap.xml is valid and lists exactly the indexable URLs', function () {
    $base = server()->base;
    $r = get('/sitemap.xml');
    assert_same(200, $r['status']);
    assert_same('application/xml; charset=utf-8', $r['headers']['content-type']);
    assert_same('public, max-age=300', $r['headers']['cache-control']);
    assert_true(isset($r['headers']['etag']), 'ETag');
    assert_same('noindex', $r['headers']['x-robots-tag'] ?? null);
    $xml = new SimpleXMLElement($r['body']);
    assert_same('http://www.sitemaps.org/schemas/sitemap/0.9', $xml->getNamespaces()[''] ?? null);
    $locs = [];
    foreach ($xml->url as $u) {
        $locs[] = (string) $u->loc;
        if (isset($u->lastmod)) {
            assert_true(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $u->lastmod) === 1, 'W3C date');
        }
        assert_false(isset($u->changefreq) || isset($u->priority), 'no changefreq/priority');
    }
    // 3 pages + 4 categories with datasets (no tag pages in the fixtures) + 4 datasets
    assert_same(["$base/", "$base/api", "$base/about", "$base/c/weather-climate", "$base/c/space-astronomy", "$base/c/earth-environment", "$base/c/knowledge-reference",
        "$base/d/nasa-apod", "$base/d/open-meteo", "$base/d/usgs-earthquakes", "$base/d/wikidata"], $locs);
    assert_contains("<loc>$base/d/open-meteo</loc><lastmod>2026-11-01</lastmod>", $r['body'], 'lastmod = later of added/last_verified');
    assert_contains("<loc>$base/</loc><lastmod>2026-11-05</lastmod>", $r['body']);
    assert_contains("<loc>$base/api</loc></url>", $r['body'], 'no invented lastmod');
    assert_same(304, get('/sitemap.xml', ['If-None-Match: ' . $r['headers']['etag']])['status']);
    $head = get('/sitemap.xml', [], 'HEAD');
    assert_same(200, $head['status']);
    assert_same('', $head['body']);
    assert_contains("Sitemap: $base/sitemap.xml", get('/robots.txt')['body']);
    assert_contains("Disallow: /explore", get('/robots.txt')['body']);

    // Real data, prefixed: counts add up, and every URL is 200, self-canonical and indexable.
    $rb = real_server()->base;
    $c = real_catalog();
    $r = http('GET', "$rb/datasets/sitemap.xml");
    $locs = array_map('strval', iterator_to_array((new SimpleXMLElement($r['body']))->xpath('//*[local-name()="loc"]'), false));
    $cats = count(array_filter($c->categories(), static fn ($x) => $x['count'] > 0));
    assert_same(3 + $cats + 1 + count(IndexPolicy::landingTags($c)) + $c->count(), count($locs));
    assert_same(count($locs), count(array_unique($locs)));
    foreach ($locs as $i => $loc) {
        assert_true(str_starts_with($loc, "$rb/datasets/"), 'absolute, prefixed');
        if ($i % 7 !== 0 && !str_contains($loc, '/c/')) {
            continue; // sample: all categories plus every 7th URL
        }
        $p = http('GET', $loc);
        assert_same(200, $p['status'], $loc);
        $h = seo_head($p['body']);
        assert_same($loc, $h['canonical'], "self-canonical $loc");
        assert_same(null, $h['robots'], "indexable $loc");
    }
});

test('seo: Atom feed of newly added datasets', function () {
    $base = server()->base;
    $r = get('/feed.xml');
    assert_same(200, $r['status']);
    assert_same('application/atom+xml; charset=utf-8', $r['headers']['content-type']);
    $xml = new SimpleXMLElement($r['body']);
    assert_same('http://www.w3.org/2005/Atom', $xml->getNamespaces()[''] ?? null);
    $xml->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');
    assert_same("$base/feed.xml", (string) $xml->id);
    assert_same('2026-10-25T00:00:00Z', (string) $xml->updated);
    $entries = $xml->xpath('//a:entry');
    assert_same(4, count($entries));
    assert_same(['NASA Astronomy Picture of the Day', 'USGS Earthquake Catalog', 'Wikidata Query Service', 'Open-Meteo'],
        array_map(static fn ($e) => (string) $e->title, $entries), 'newest first');
    foreach ($entries as $e) {
        foreach (['id', 'title', 'updated', 'summary'] as $k) {
            assert_true((string) $e->{$k} !== '', "entry $k");
        }
        assert_true(str_starts_with((string) $e->id, "$base/d/"), 'entry id is the page URL');
    }
    assert_contains('<link rel="alternate" type="application/atom+xml" title="Newly added datasets" href="/feed.xml">', get('/about')['body']);
});

test('seo: head tags on every page type', function () {
    $base = server()->base;
    foreach (['/', '/c/weather-climate', '/d/open-meteo', '/api', '/about'] as $path) {
        $b = get($path)['body'];
        assert_contains('<html lang="en">', $b, $path);
        assert_contains('<meta property="og:image" content="' . $base . '/assets/og.png">', $b, $path);
        assert_contains('<meta name="twitter:card" content="summary_large_image">', $b, $path);
        assert_contains('<link rel="apple-touch-icon"', $b, $path);
        assert_contains('<meta property="og:url" content="', $b, $path);
        $h = seo_head($b);
        assert_same(1, $h['h1'], "one h1 on $path");
        assert_true($h['title'] !== null && mb_strlen($h['title']) <= 75, "title length on $path");
        assert_true($h['description'] !== null && mb_strlen($h['description']) <= 160, "description length on $path");
    }
    $root = dirname(__DIR__) . '/public/assets';
    assert_same([1200, 630], array_slice(getimagesize("$root/og.png") ?: [], 0, 2), 'og.png is 1200x630');
    assert_same([180, 180], array_slice(getimagesize("$root/apple-touch-icon.png") ?: [], 0, 2));

    $r = get('/no/such/page');
    $h = seo_head($r['body']);
    assert_same(404, $r['status']);
    assert_same('noindex', $h['robots']);
    assert_same(null, $h['canonical'], 'a 404 has no canonical');
});

test('seo: HTTP rules — case redirects, X-Robots-Tag on machine endpoints, API unchanged', function () {
    $r = get('/About?x=1');
    assert_same(301, $r['status']);
    assert_same('/about?x=1', $r['headers']['location']);
    assert_same(301, get('/D/open-meteo')['status']);
    assert_same('/d/open-meteo', get('/d/Open-Meteo')['headers']['location']);
    $r = get('/API/V1');
    assert_same(404, $r['status'], 'API paths are case-sensitive, no redirect');
    $p = prefixed_server()->base;
    assert_same('/datasets/about', http('GET', "$p/datasets/ABOUT")['headers']['location']);

    $r = get('/api/v1/datasets/open-meteo');
    assert_same(200, $r['status']);
    assert_same('noindex', $r['headers']['x-robots-tag'] ?? null);
    assert_api_headers($r);
    assert_same('noindex', get('/llms.txt')['headers']['x-robots-tag'] ?? null);
    assert_same('noindex', get('/feed.xml')['headers']['x-robots-tag'] ?? null);
    assert_false(isset(get('/')['headers']['x-robots-tag']), 'HTML pages use meta robots');
    assert_false(isset(get('/robots.txt')['headers']['x-robots-tag']));
    assert_same(204, get('/api/v1/datasets', ['Origin: https://example.org', 'Access-Control-Request-Method: GET'], 'OPTIONS')['status'], 'preflight unchanged');
});

test('seo: titles and descriptions are unique and bounded across the real catalogue', function () {
    $c = real_catalog();
    $titles = [];
    $descriptions = [];
    foreach ($c->all() as $d) {
        $cat = $c->category($d['category']);
        $titles[] = Meta::datasetTitle($d, $cat);
        $descriptions[] = Meta::datasetDescription($d);
    }
    foreach ($c->categories() as $cat) {
        if ($cat['count'] > 0) {
            $titles[] = Meta::categoryTitle($cat);
            $descriptions[] = Meta::categoryDescription($cat, 0);
        }
    }
    foreach (IndexPolicy::landingTags($c) as $tag => $n) {
        $titles[] = Meta::tagTitle((string) $tag, $n);
        $descriptions[] = Meta::tagDescription((string) $tag, $n, ['A', 'B']);
    }
    assert_same(count($titles), count(array_unique($titles)), 'unique titles');
    assert_same(count($descriptions), count(array_unique($descriptions)), 'unique descriptions');
    foreach ($titles as $t) {
        assert_true(mb_strlen($t) >= 25 && mb_strlen($t) <= 75, "title length: $t");
        assert_false(str_contains($t, 'API API'), $t);
    }
    foreach ($descriptions as $s) {
        assert_true(mb_strlen($s) >= 50 && mb_strlen($s) <= Meta::DESCRIPTION_MAX, "description length: $s");
    }
    assert_same('A short sentence that…', Meta::truncate('A short sentence that keeps going', 23));
});

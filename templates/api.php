<?php
declare(strict_types=1);
/** Docs for this site's own REST API. Vars: $catalog, $request, $urls, $apiBase, $openapiUrl, $schemaUrl */
use Datasets\Api\ListParams;
use Datasets\Catalog;
use Datasets\Web\View;

$enums = $catalog->enums();
$first = $catalog->all()[0] ?? null;
$slug = $first['slug'] ?? 'example';
$firstCat = $catalog->categoryIds()[0] ?? 'example';
$llms = abs_url('/llms.txt');

$endpoints = [
    ['/api/v1', 'Index: name, description, counts and links to every endpoint.'],
    ['/api/v1/datasets', 'List and filter datasets. Accepts every filter below plus sort, limit, offset and fields. Paginated, with next/prev in the body and a Link header.'],
    ['/api/v1/datasets/{slug}', 'One complete dataset record, exactly as stored, plus computed url and html_url.'],
    ['/api/v1/categories', 'All categories with descriptions and dataset counts.'],
    ['/api/v1/categories/{id}', 'One category with its count and a link to its datasets.'],
    ['/api/v1/tags', 'Every tag with its count, most used first.'],
    ['/api/v1/facets', 'Counts per value of each facet (category, tag, auth, cors, format, style, provider_type, commercial_use, status). Accepts the same filters as /datasets, so you can count before you list.'],
    ['/api/v1/schema', 'The JSON Schema (2020-12) every dataset record validates against.'],
    ['/api/v1/openapi.json', 'OpenAPI 3.1 description of this API, generated from the schema.'],
    ['/llms.txt', 'Plain-text index for language models: every dataset grouped by category, with links to the JSON records.'],
];

$paramDocs = [
    'q' => ['string', 'Full-text search. Every whitespace-separated term must occur (case-insensitive) in the name, provider, summary, description, tags or endpoint summaries. Max 200 characters.'],
    'category' => ['enum, list', 'Category id. Comma-separated or repeated = any of.'],
    'tag' => ['string, list', 'Tag (lower-case kebab-case). Several tags = all of.'],
    'auth' => ['enum, list', 'What ordinary use needs: none, a free API key, or OAuth 2.'],
    'cors' => ['enum, list', 'CORS behaviour from a real response: any (*), reflect (echoes Origin), restricted, none, unknown.'],
    'format' => ['enum, list', 'Response format offered by the API.'],
    'style' => ['enum, list', 'Dominant query style.'],
    'provider_type' => ['enum, list', 'Who runs the API.'],
    'commercial_use' => ['enum, list', 'What the data license says about commercial use.'],
    'status' => ['enum, list', 'active, unstable (works but undocumented or flaky) or deprecated.'],
    'keyless' => ['boolean', 'true = auth is none (works without any credential); false = needs a key.'],
    'browser' => ['boolean', 'true = cors is any or reflect, so browser JavaScript can call it directly.'],
    'has_openapi' => ['boolean', 'true = the provider publishes a confirmed OpenAPI/Swagger document.'],
    'sort' => ['enum', 'Sort order; a leading minus sorts descending. Ties are broken by name.'],
    'limit' => ['integer', sprintf('Page size, 1–%d. Default %d.', ListParams::MAX_LIMIT, ListParams::DEFAULT_LIMIT)],
    'offset' => ['integer', 'Number of results to skip. Default 0.'],
    'fields' => ['string, list', 'Return only these top-level fields (slug, url and html_url are always included).'],
];
$paramEnums = $enums + ['sort' => Catalog::SORTS];

$examples = [
    'Keyless, browser-callable weather APIs' => abs_url('/api/v1/datasets', ['category' => 'weather-climate', 'keyless' => true, 'browser' => true]),
    'Search, only a few fields' => abs_url('/api/v1/datasets', ['q' => 'earthquake', 'fields' => 'name,summary,api']),
    'One record' => abs_url('/api/v1/datasets/' . $slug),
    'How many datasets need a key, per category?' => abs_url('/api/v1/facets', ['keyless' => false]),
];

$envelope = <<<JSON
{
  "data": [
    {
      "slug": "$slug",
      "name": "…",
      "url": "$apiBase/datasets/$slug",
      "html_url": "{$urls->absolute('/d/' . $slug)}"
    }
  ],
  "meta": { "total": {$catalog->count()}, "limit": 50, "offset": 0, "count": 50 },
  "links": {
    "self": "$apiBase/datasets",
    "first": "$apiBase/datasets",
    "prev": null,
    "next": "$apiBase/datasets?offset=50",
    "last": "$apiBase/datasets?offset=50"
  }
}
JSON;
$problem = <<<JSON
{
  "type": "about:blank",
  "title": "Invalid query parameters",
  "status": 400,
  "detail": "Invalid query parameter \"keyles\": unknown parameter; allowed: q, category, …",
  "instance": "/api/v1/datasets?keyles=true",
  "errors": [
    { "parameter": "keyles", "detail": "unknown parameter; allowed: q, category, …" }
  ]
}
JSON;

$title = 'REST API';
$metaTitle = \Datasets\Seo\Meta::withSite('Catalogue REST API — no key, CORS, OpenAPI 3.1');
$description = 'This catalogue as a free, open, read-only REST API: no key, CORS enabled, OpenAPI 3.1, filters for auth, CORS, license and format.';
$canonical = abs_url('/api');
$current = 'api';
$sections = ['overview' => 'Overview', 'endpoints' => 'Endpoints', 'parameters' => 'Parameters', 'examples' => 'Examples', 'responses' => 'Responses', 'errors' => 'Errors', 'http' => 'CORS & caching', 'machine' => 'Machine-readable'];
$rail = static function () use ($sections): void { ?>
    <div class="rail-section">
      <h2 class="micro">On this page</h2>
      <ol><?php foreach ($sections as $id => $label): ?><li><a href="#<?= e($id) ?>"><?= e($label) ?></a></li><?php endforeach ?></ol>
    </div>
<?php };
$copyBlock = static function (string $id, string $label, string $text): void { ?>
        <div class="code">
          <div class="code-head"><span class="micro"><?= e($label) ?></span><button type="button" class="copy" data-copy="#<?= e($id) ?>" hidden>Copy</button></div>
          <pre id="<?= e($id) ?>"><code><?= e($text) ?></code></pre>
        </div>
<?php };

include __DIR__ . '/partials/header.php';
?>
    <article class="doc">
      <header class="d-head">
        <p class="kicker micro">Documentation</p>
        <h1>The catalogue as an API</h1>
        <p class="lede">Everything on this site is available as JSON. Free, no key, no signup, CORS open to every origin — call it from a script, a browser or an agent.</p>
      </header>

      <section class="section" id="overview" aria-labelledby="h-overview">
        <h2 id="h-overview">Overview</h2>
        <table class="facts">
          <tbody>
            <tr><th scope="row">Base URL</th><td><code class="url"><?= e($apiBase) ?></code></td></tr>
            <tr><th scope="row">Authentication</th><td>None</td></tr>
            <tr><th scope="row">Methods</th><td><code>GET</code>, <code>HEAD</code>, <code>OPTIONS</code> — read-only</td></tr>
            <tr><th scope="row">Format</th><td>JSON (<code>application/json</code>), pretty-printed; errors as <code>application/problem+json</code></td></tr>
            <tr><th scope="row">CORS</th><td><code>Access-Control-Allow-Origin: *</code></td></tr>
            <tr><th scope="row">Rate limit</th><td>None enforced; responses are cacheable for 5 minutes, please cache</td></tr>
            <tr><th scope="row">Description</th><td><a href="<?= e($openapiUrl) ?>">OpenAPI 3.1</a> · <a href="<?= e($schemaUrl) ?>">JSON Schema</a> · <a href="<?= e($llms) ?>">llms.txt</a></td></tr>
          </tbody>
        </table>
      </section>

      <section class="section" id="endpoints" aria-labelledby="h-endpoints">
        <h2 id="h-endpoints">Endpoints</h2>
        <div class="table-wrap">
          <table class="endpoints">
            <thead><tr><th scope="col">Method</th><th scope="col">Path</th><th scope="col">Description</th></tr></thead>
            <tbody>
              <?php foreach ($endpoints as [$path, $text]):
                  $concrete = str_replace(['{slug}', '{id}'], [$slug, $firstCat], $path); ?>
                <tr>
                  <td class="method">GET</td>
                  <td class="path"><a href="<?= e(url($concrete)) ?>"><code><?= e($path) ?></code></a></td>
                  <td><?= e($text) ?></td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
        <p class="aside">Paths are relative to <code class="url"><?= e(abs_url('/')) ?></code>. URLs with a trailing slash redirect (308) to the slash-less form.</p>
      </section>

      <section class="section" id="parameters" aria-labelledby="h-parameters">
        <h2 id="h-parameters">Parameters</h2>
        <p class="aside">For <code>/api/v1/datasets</code> (all) and <code>/api/v1/facets</code> (filters only). All filters combine with AND. Unknown parameters are rejected with 400, so a typo never silently returns everything. Allowed values are read from the schema.</p>
        <div class="table-wrap">
          <table class="params-table">
            <thead><tr><th scope="col">Parameter</th><th scope="col">Type</th><th scope="col">Meaning and allowed values</th></tr></thead>
            <tbody>
              <?php foreach (array_merge(ListParams::FILTERS, ListParams::PAGING) as $name):
                  [$type, $text] = $paramDocs[$name] ?? ['string', '']; ?>
                <tr>
                  <td><code><?= e($name) ?></code></td>
                  <td class="type"><?= e($type) ?></td>
                  <td><?= e($text) ?>
                    <?php if (!empty($paramEnums[$name])): ?>
                      <span class="enum"><?php foreach ($paramEnums[$name] as $v): ?><code><?= e($v) ?></code> <?php endforeach ?></span>
                    <?php endif ?>
                  </td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="section" id="examples" aria-labelledby="h-examples">
        <h2 id="h-examples">Examples</h2>
        <?php $i = 0; foreach ($examples as $label => $u): $i++; ?>
          <?php $copyBlock('ex' . $i, $label, 'curl ' . View::shellQuote($u)); ?>
        <?php endforeach ?>
        <?php $copyBlock('ex-js', 'From a browser', "const res = await fetch(" . json_encode($examples['Keyless, browser-callable weather APIs'], JSON_UNESCAPED_SLASHES) . ");\nconst { data, meta } = await res.json();"); ?>
      </section>

      <section class="section" id="responses" aria-labelledby="h-responses">
        <h2 id="h-responses">Responses</h2>
        <p>Lists come wrapped in <code>data</code>, <code>meta</code> (total, limit, offset, count) and <code>links</code> (self, first, prev, next, last — <code>null</code> when there is none). A single record is <code>{"data": {…}, "links": {…}}</code>. Records are the stored JSON files unchanged, plus <code>url</code> (this API) and <code>html_url</code> (the page on this site).</p>
        <?php $copyBlock('envelope', 'GET /api/v1/datasets', $envelope); ?>
      </section>

      <section class="section" id="errors" aria-labelledby="h-errors">
        <h2 id="h-errors">Errors</h2>
        <p>Errors follow <a href="https://www.rfc-editor.org/rfc/rfc9457">RFC 9457</a> problem details, served as <code>application/problem+json</code> with <code>type</code>, <code>title</code>, <code>status</code>, <code>detail</code> and <code>instance</code>. A 400 also lists each bad parameter under <code>errors</code>. Unknown slugs and paths return 404; methods other than GET, HEAD and OPTIONS return 405.</p>
        <?php $copyBlock('problem', '400 Bad Request', $problem); ?>
      </section>

      <section class="section" id="http" aria-labelledby="h-http">
        <h2 id="h-http">CORS &amp; caching</h2>
        <ul class="plain spaced">
          <li>Every response carries <code>Access-Control-Allow-Origin: *</code> and exposes <code>ETag</code>, <code>Link</code>, <code>Allow</code> and <code>Content-Length</code>. Preflight (<code>OPTIONS</code>) is answered directly.</li>
          <li><code>Cache-Control: public, max-age=300</code> and a strong <code>ETag</code>; send <code>If-None-Match</code> to get <code>304 Not Modified</code>.</li>
          <li>List responses include an RFC 8288 <code>Link</code> header (<code>rel="next"</code> etc.) mirroring <code>links</code> in the body.</li>
        </ul>
        <p class="aside">Not part of this API: the “Try it” explorer on dataset pages uses a private proxy at <code><?= e(url('/explore')) ?></code>. It only serves this site’s own pages (no CORS, same-origin only, rate-limited), only contacts the listed dataset’s hosts, never forwards your cookies or credentials and never proxies personal API keys. It is not in the OpenAPI document and may change without notice — call the dataset APIs directly instead.</p>
      </section>

      <section class="section" id="machine" aria-labelledby="h-machine">
        <h2 id="h-machine">Machine-readable</h2>
        <ul class="links">
          <li><a href="<?= e($openapiUrl) ?>">OpenAPI 3.1 document</a> <span class="host"><?= e($openapiUrl) ?></span></li>
          <li><a href="<?= e($schemaUrl) ?>">Dataset JSON Schema</a> <span class="host"><?= e($schemaUrl) ?></span></li>
          <li><a href="<?= e($llms) ?>">llms.txt</a> <span class="host"><?= e($llms) ?></span></li>
        </ul>
      </section>
    </article>
<?php include __DIR__ . '/partials/footer.php';

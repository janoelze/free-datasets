<?php
declare(strict_types=1);
/** Dataset detail. Vars: $catalog, $request, $urls, $dataset, $category, $related, $apiUrl */
use Datasets\Explore\Policy;
use Datasets\Seo\IndexPolicy;
use Datasets\Seo\Meta;
use Datasets\Seo\StructuredData;
use Datasets\Web\View;

$d = $dataset;
$api = $d['api'];
$auth = $api['auth'];
$license = $d['data']['license'];
$coverage = $d['data']['coverage'] ?? [];
$ver = $d['verification'];
$links = $d['links'];
$browser = View::browserUsable($d);
$excerpt = View::excerpt((string) $d['example']['response_excerpt']);
$pageUrl = abs_url('/d/' . $d['slug']);
$categoryName = $category['name'] ?? $d['category'];
$categoryNames = array_column($catalog->categories(), 'name', 'id');
$formats = implode(', ', array_map(static fn ($f) => View::label('format', $f), $api['formats']));

$explorer = new Policy($d);
$xMode = $explorer->mode();
$xConfig = $explorer->clientConfig(url('/explore')) + [
    'recorded' => ['status' => (int) $ver['http_status'], 'date' => (string) $ver['last_verified'], 'withKey' => (bool) $ver['with_key']],
];

$title = $d['name'];
$metaTitle = Meta::datasetTitle($d, $category);
$ogTitle = Meta::datasetShareTitle($d, $category);
$description = Meta::datasetDescription($d);
$canonical = $pageUrl;
$current = '';
$ogType = 'article';
$jsonLd = (new StructuredData($urls))->dataset($d, $category, $description);
$headExtra = '<link rel="alternate" type="application/json" href="' . e($apiUrl) . '" title="This record as JSON">' . "\n"
    . ($xMode !== 'off' ? '<script src="' . e(asset('assets/explorer.js')) . '" defer></script>' . "\n" : '');
// Crawlable landing pages for the category and for tags that have one (Seo\IndexPolicy).
$categoryUrl = url('/c/' . $d['category']);
$tagUrl = static fn (string $t): string => IndexPolicy::tagHasPage($catalog, $t) ? url('/t/' . $t) : url('/', ['tag' => $t]);

$authText = match ($auth['type']) {
    'none' => !empty($auth['key_optional']) ? 'None required (a free key raises limits)' : 'None — no key, no signup',
    'api_key' => 'Free API key',
    'oauth2' => 'OAuth 2',
    default => $auth['type'],
};


$sections = ['access' => 'Access', 'endpoints' => 'Endpoints', 'example' => 'Try it'];
if (!empty($d['agent_notes']) || !empty($d['caveats'])) {
    $sections['notes'] = 'Notes & caveats';
}
$sections['links'] = 'Links';
if (!empty($d['hn'])) {
    $sections['discussion'] = 'Discussion';
}
if ($related !== []) {
    $sections['related'] = 'Related';
}
$sections['record'] = 'Record';

$rail = static function () use ($sections, $categoryUrl, $categoryName): void { ?>
    <div class="rail-section">
      <h2 class="micro">On this page</h2>
      <ol>
        <?php foreach ($sections as $id => $label): ?>
          <li><a href="#<?= e($id) ?>"><?= e($label) ?></a></li>
        <?php endforeach ?>
      </ol>
      <p class="rail-back"><a href="<?= e($categoryUrl) ?>">More in <?= e($categoryName) ?></a></p>
    </div>
<?php };

$linkLabels = [
    'docs' => 'Documentation', 'homepage' => 'Homepage', 'openapi' => 'OpenAPI / Swagger spec', 'signup' => 'Get a free key',
    'terms' => 'Terms of use', 'bulk_download' => 'Bulk download', 'source' => 'Source code',
];

include __DIR__ . '/partials/header.php';
?>
    <article class="dataset">
      <header class="d-head">
        <p class="kicker micro"><a href="<?= e($categoryUrl) ?>"><?= e($categoryName) ?></a><?php if ($d['status'] !== 'active'): ?> <span class="flag"><?= e($d['status']) ?></span><?php endif ?></p>
        <h1><?= e($d['name']) ?></h1>
        <p class="d-provider">by <?php if (isset($d['provider']['url'])): ?><a href="<?= e($d['provider']['url']) ?>" rel="noopener"><?= e($d['provider']['name']) ?></a><?php else: ?><?= e($d['provider']['name']) ?><?php endif ?>
          <span class="muted">· <?= e($d['provider']['type']) ?></span></p>
        <p class="lede"><?= e($d['summary']) ?></p>
      </header>

      <dl class="glance" aria-label="At a glance">
        <div><dt>Auth</dt><dd class="<?= $auth['type'] === 'none' ? 'ok' : 'warn' ?>"><?= e($auth['type'] === 'none' ? 'No key' : View::label('auth', $auth['type'])) ?></dd></div>
        <div><dt>Browser</dt><dd class="<?= $browser ? 'ok' : 'off' ?>"><?= e($browser ? 'CORS ' . ($api['cors'] === 'any' ? '*' : 'reflect') : View::label('cors', $api['cors'])) ?></dd></div>
        <div><dt>Rate limit</dt><dd><?php if (($budget = View::rateBudget($api['rate_limit'])) !== null): ?><?= e($budget) ?><?php elseif (stripos($api['rate_limit']['summary'], 'not documented') !== false): ?>Not documented<?php else: ?><a href="#access">See details</a><?php endif ?></dd></div>
        <div class="g-lic"><dt>License</dt><dd title="<?= e($license['name']) ?>"><?= e($license['spdx'] ?? $license['name']) ?></dd></div>
        <div><dt>Commercial use</dt><dd class="<?= $license['commercial_use'] === 'allowed' ? 'ok' : ($license['commercial_use'] === 'prohibited' ? 'warn' : '') ?>"><?= e(View::label('commercial_use', $license['commercial_use'])) ?></dd></div>
        <div><dt>Formats</dt><dd><?= View::formatBadges($api['formats']) ?></dd></div>
        <div class="g-url"><dt>Base URL</dt><dd><code class="url"><?= e($api['base_url']) ?></code></dd></div>
      </dl>

      <div class="prose">
        <p><?= e($d['description']) ?></p>
      </div>

      <section class="section" id="access" aria-labelledby="h-access">
        <h2 id="h-access">Access</h2>
        <table class="facts">
          <tbody>
            <tr><th scope="row">Base URL</th><td><code class="url"><?= e($api['base_url']) ?></code></td></tr>
            <tr><th scope="row">Style</th><td><?= e(View::label('style', $api['style'])) ?></td></tr>
            <tr><th scope="row">Authentication</th><td>
              <?= e($authText) ?>
              <?php if ($auth['type'] !== 'none' || isset($auth['key_name'])): ?>
                <?php if (isset($auth['key_in']) || isset($auth['key_name'])): ?>
                  <br><span class="muted">Key sent as</span> <?= isset($auth['key_in']) ? e(View::KEY_IN[$auth['key_in']] ?? $auth['key_in']) : '' ?><?php if (isset($auth['key_name'])): ?> <code><?= e($auth['key_name']) ?></code><?php endif ?>
                <?php endif ?>
              <?php endif ?>
              <?php if (isset($auth['demo_key'])): ?><br><span class="muted">Demo key</span> <code><?= e($auth['demo_key']) ?></code><?php endif ?>
              <?php if (isset($links['signup'])): ?><br><a href="<?= e($links['signup']) ?>" rel="noopener">Get a free key</a> <span class="muted">at <?= e(View::host($links['signup'])) ?></span><?php endif ?>
              <?php if (isset($auth['notes'])): ?><p class="note"><?= e($auth['notes']) ?></p><?php endif ?>
            </td></tr>
            <?php if (!empty($api['required_headers'])): ?>
              <tr><th scope="row">Required headers</th><td>
                <ul class="plain">
                  <?php foreach ($api['required_headers'] as $h): ?>
                    <li><code><?= e($h['name']) ?><?= isset($h['example']) ? ': ' . e($h['example']) : '' ?></code><?php if (isset($h['notes'])): ?> <span class="note-inline"><?= e($h['notes']) ?></span><?php endif ?></li>
                  <?php endforeach ?>
                </ul>
              </td></tr>
            <?php endif ?>
            <tr><th scope="row">CORS</th><td><?= e(View::label('cors', $api['cors'])) ?> <span class="muted">— <?= $browser ? 'callable from browser JavaScript' : ($api['cors'] === 'unknown' ? 'browser use not confirmed' : 'use a server or proxy') ?></span></td></tr>
            <tr><th scope="row">Rate limit</th><td><?= e($api['rate_limit']['summary']) ?><?php if (($b = View::rateBudget($api['rate_limit'])) !== null): ?><br><span class="muted">Budget</span> <span class="num"><?= e($b) ?></span><?php endif ?></td></tr>
            <tr><th scope="row">Pagination</th><td><?= e(View::label('pagination', $api['pagination'])) ?><?php if (isset($api['max_page_size'])): ?> <span class="muted">· max page size</span> <span class="num"><?= e(number_format((int) $api['max_page_size'])) ?></span><?php endif ?></td></tr>
            <tr><th scope="row">Formats</th><td><?= View::formatBadges($api['formats']) ?></td></tr>
            <tr><th scope="row">License</th><td>
              <?php if (isset($license['url'])): ?><a href="<?= e($license['url']) ?>" rel="noopener license"><?= e($license['name']) ?></a><?php else: ?><?= e($license['name']) ?><?php endif ?>
              <?php if (isset($license['spdx'])): ?> <code class="spdx"><?= e($license['spdx']) ?></code><?php endif ?>
              <br><span class="muted">Commercial use</span> <?= e(strtolower(View::label('commercial_use', $license['commercial_use']))) ?><?php if (isset($license['attribution_required'])): ?><span class="muted"> · attribution</span> <?= $license['attribution_required'] ? 'required' : 'not required' ?><?php endif ?>
            </td></tr>
            <?php if (!empty($coverage['geographic']) || !empty($coverage['temporal'])): ?>
              <tr><th scope="row">Coverage</th><td><?php if (!empty($coverage['geographic'])): ?><?= e($coverage['geographic']) ?><?php endif ?><?php if (!empty($coverage['geographic']) && !empty($coverage['temporal'])): ?><br><?php endif ?><?php if (!empty($coverage['temporal'])): ?><span class="muted">Time</span> <?= e($coverage['temporal']) ?><?php endif ?></td></tr>
            <?php endif ?>
            <tr><th scope="row">Updated</th><td><?= e(ucfirst($d['data']['update_frequency'])) ?></td></tr>
            <tr><th scope="row">Status</th><td><?= e(ucfirst($d['status'])) ?><?= $d['status'] === 'unstable' ? ' <span class="muted">— works, but undocumented, unofficial or flaky</span>' : '' ?><?= $d['status'] === 'deprecated' ? ' <span class="muted">— end of life announced</span>' : '' ?></td></tr>
            <tr><th scope="row">Last verified</th><td><time datetime="<?= e($ver['last_verified']) ?>"><?= e($ver['last_verified']) ?></time> <span class="muted">· HTTP</span> <span class="num"><?= e($ver['http_status']) ?></span><?php if (!$ver['with_key']): ?> <span class="muted">· only the unauthenticated response was observed</span><?php endif ?><?php if (isset($ver['notes'])): ?><p class="note"><?= e($ver['notes']) ?></p><?php endif ?></td></tr>
          </tbody>
        </table>
      </section>

      <section class="section" id="endpoints" aria-labelledby="h-endpoints">
        <h2 id="h-endpoints">Endpoints</h2>
        <div class="table-wrap">
          <table class="endpoints">
            <thead><tr><th scope="col">Method</th><th scope="col">Path</th><th scope="col">What it returns</th></tr></thead>
            <tbody>
              <?php foreach ($d['endpoints'] as $ep): ?>
                <tr>
                  <td class="method"><?= e($ep['method']) ?></td>
                  <td class="path"><code><?= e($ep['path']) ?></code></td>
                  <td><?= e($ep['summary']) ?><?php if (!empty($ep['params'])): ?><span class="params"><?php foreach ($ep['params'] as $p): ?><code><?= e($p) ?></code> <?php endforeach ?></span><?php endif ?></td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
        <p class="aside">Relative paths are under <code class="url"><?= e($api['base_url']) ?></code>.</p>
      </section>

      <section class="section" id="example" aria-labelledby="h-example">
        <h2 id="h-example">Try it</h2>
        <?php if ($xMode === 'off' || $xMode === 'direct'): ?>
          <p class="x-note"><?php if ($d['status'] === 'deprecated'): ?>This API is marked deprecated, so the live explorer is turned off.<?php else: ?>This API needs your own free key<?php if (isset($links['signup'])): ?> — get one at <a href="<?= e($links['signup']) ?>" rel="noopener"><?= e(View::host($links['signup'])) ?></a><?php endif ?>. Requests with personal keys are not sent through this site<?= $xMode === 'direct' ? '; the explorer calls the API directly from your browser' : ', and this API is not known to accept calls from browser JavaScript, so there is no live explorer here. Use the curl command below with your key' ?>.<?php endif ?></p>
        <?php endif ?>
        <?php if ($xMode !== 'off'): ?>
          <p class="x-note" data-x-nojs>The live explorer needs JavaScript. Below is the recorded example request and what it returned.</p>
          <form class="explorer" data-explorer hidden novalidate>
            <script type="application/json" data-explorer-config><?= json_encode($xConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
            <p class="aside x-intro">Edit the request and send it live. It goes straight from your browser when the API allows that, otherwise through a small proxy on this site that only talks to this API’s own hosts.</p>
            <div class="x-line">
              <?php if (count($xConfig['methods']) > 1): ?>
                <label class="sr" for="x-method">Method</label>
                <select id="x-method" class="x-method" data-x-method>
                  <?php foreach ($xConfig['methods'] as $m): ?><option<?= $m === $d['example']['method'] ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach ?>
                </select>
              <?php else: ?>
                <span class="x-method x-method-fixed" title="Method">GET</span>
              <?php endif ?>
              <label class="sr" for="x-url">Request URL</label>
              <input id="x-url" class="x-url" data-x-url type="text" inputmode="url" autocomplete="off" autocapitalize="off" spellcheck="false" value="<?= e($d['example']['url']) ?>">
              <button type="submit" class="x-send" data-x-send>Send</button>
            </div>
            <?php if (in_array('POST', $xConfig['methods'], true)): ?>
              <div class="x-body-wrap" data-x-body-wrap<?= $d['example']['method'] === 'POST' ? '' : ' hidden' ?>>
                <label class="micro" for="x-body">Body <span class="x-body-type"><?= e($xConfig['postType']) ?></span></label>
                <textarea id="x-body" class="x-body" data-x-body rows="3" spellcheck="false" autocomplete="off"><?= e($d['example']['body'] ?? '') ?></textarea>
              </div>
            <?php endif ?>
            <div class="x-endpoints">
              <span class="micro">Endpoints</span>
              <ul>
                <?php foreach ($xConfig['endpoints'] as $i => $ep): ?>
                  <li><button type="button" data-x-endpoint="<?= e($i) ?>" title="<?= e($ep['summary']) ?>"><span class="verb"><?= e($ep['method']) ?></span> <?= e($ep['path']) ?></button></li>
                <?php endforeach ?>
              </ul>
            </div>
            <div class="x-meta">
              <div class="x-transport" data-x-transport></div>
              <button type="button" class="copy" data-x-curl>Copy as curl</button>
            </div>
            <p class="x-hint" data-x-hint aria-live="polite"></p>
          </form>
        <?php endif ?>
        <div class="x-static" data-x-static>
          <p class="aside">Executed on <?= e($ver['last_verified']) ?>, returned HTTP <?= e($ver['http_status']) ?><?= $ver['with_key'] ? '' : ' (without a key)' ?>.<?php if ($d['example']['method'] === 'POST'): ?> This API takes a POST body.<?php endif ?></p>
          <div class="code">
            <div class="code-head"><span class="micro">Request · shell</span><button type="button" class="copy" data-copy="#curl" hidden>Copy</button></div>
            <pre id="curl"><code><?= e(View::curl($d)) ?></code></pre>
          </div>
        </div>
        <div class="x-panel" data-x-panel aria-live="polite">
          <div class="x-recorded" data-x-recorded>
            <div class="code">
              <div class="code-head"><span class="micro">Response excerpt<?= $excerpt['json'] ? ' · JSON' : '' ?></span><button type="button" class="copy" data-copy="#response" hidden>Copy</button></div>
              <pre id="response" class="response"><code><?= e($excerpt['text']) ?></code></pre>
            </div>
          </div>
          <div class="x-result" data-x-result hidden></div>
        </div>
      </section>

      <?php if (isset($sections['notes'])): ?>
        <section class="section" id="notes" aria-labelledby="h-notes">
          <h2 id="h-notes">Notes &amp; caveats</h2>
          <?php if (!empty($d['agent_notes'])): ?>
            <div class="agent-notes">
              <h3 class="micro">For agents and developers</h3>
              <p><?= e($d['agent_notes']) ?></p>
            </div>
          <?php endif ?>
          <?php if (!empty($d['caveats'])): ?>
            <h3 class="micro">Caveats</h3>
            <ul class="caveats">
              <?php foreach ($d['caveats'] as $c): ?><li><?= e($c) ?></li><?php endforeach ?>
            </ul>
          <?php endif ?>
        </section>
      <?php endif ?>

      <section class="section" id="links" aria-labelledby="h-links">
        <h2 id="h-links">Links</h2>
        <ul class="links">
          <?php foreach ($linkLabels as $key => $label): if (!isset($links[$key])) { continue; } ?>
            <li><a href="<?= e($links[$key]) ?>" rel="noopener"><?= e($label) ?></a> <span class="host"><?= e(View::host($links[$key])) ?></span></li>
          <?php endforeach ?>
        </ul>
      </section>

      <?php if (!empty($d['hn'])): ?>
        <section class="section" id="discussion" aria-labelledby="h-discussion">
          <h2 id="h-discussion">Discussion</h2>
          <ul class="hn">
            <?php foreach ($d['hn'] as $item): ?>
              <li><a href="<?= e($item['url']) ?>" rel="noopener"><?= e($item['title'] ?? 'Hacker News thread') ?></a> <span class="host">Hacker News<?php if (isset($item['points'])): ?> · <span class="num"><?= e($item['points']) ?> <?= $item['points'] === 1 ? 'point' : 'points' ?></span><?php endif ?></span></li>
            <?php endforeach ?>
          </ul>
        </section>
      <?php endif ?>

      <?php if ($related !== []): ?>
        <section class="section" id="related" aria-labelledby="h-related">
          <h2 id="h-related">Related</h2>
          <ul class="cards">
            <?php foreach ($related as $r): $rAuth = $r['api']['auth']['type']; $rBrowser = View::browserUsable($r); ?>
              <li class="card">
                <h3 class="card-title"><a href="<?= e(url('/d/' . $r['slug'])) ?>"><?= e($r['name']) ?></a><?php if ($r['status'] !== 'active'): ?> <span class="flag"><?= e($r['status']) ?></span><?php endif ?></h3>
                <p class="card-summary"><?= e($r['summary']) ?></p>
                <p class="card-cat"><?= e($categoryNames[$r['category']] ?? $r['category']) ?></p>
                <dl class="card-facts">
                  <div><dt class="sr">Auth</dt><dd class="<?= $rAuth === 'none' ? 'ok' : 'warn' ?>"><?= e($rAuth === 'none' ? 'No key' : ($rAuth === 'api_key' ? 'Free key' : 'OAuth')) ?></dd></div>
                  <div><dt class="sr">Browser</dt><dd class="<?= $rBrowser ? 'ok' : 'off' ?>" title="CORS: <?= e(View::label('cors', $r['api']['cors'])) ?>"><?= e($rBrowser ? 'CORS' : ($r['api']['cors'] === 'unknown' ? 'CORS ?' : 'No CORS')) ?></dd></div>
                  <div><dt class="sr">Formats</dt><dd><?= View::formatBadges($r['api']['formats'], 2) ?></dd></div>
                </dl>
              </li>
            <?php endforeach ?>
          </ul>
        </section>
      <?php endif ?>

      <section class="section" id="record" aria-labelledby="h-record">
        <h2 id="h-record">Record</h2>
        <dl class="record-meta">
          <div><dt>Tags</dt><dd class="tags"><?php foreach ($d['tags'] as $t): ?><a href="<?= e($tagUrl($t)) ?>"><?= e($t) ?></a> <?php endforeach ?></dd></div>
          <div><dt>This record as JSON</dt><dd class="record-json"><a href="<?= e($apiUrl) ?>"><code><span class="verb">GET</span> <?= e($apiUrl) ?></code></a></dd></div>
        </dl>
        <p class="aside">Added <?= e($d['added']) ?>. Source file <a href="https://github.com/janoelze/datasets/blob/main/datasets/<?= e(rawurlencode($d['slug'])) ?>.json"><code>datasets/<?= e($d['slug']) ?>.json</code></a> — corrections welcome.</p>
      </section>
    </article>
<?php include __DIR__ . '/partials/footer.php';

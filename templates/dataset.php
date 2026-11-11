<?php
declare(strict_types=1);
/** Dataset detail. Vars: $catalog, $request, $urls, $dataset, $category, $related, $apiUrl */
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
$formats = implode(', ', array_map(static fn ($f) => View::label('format', $f), $api['formats']));

$title = $d['name'];
$description = $d['summary'];
$canonical = $pageUrl;
$current = '';
$ogType = 'article';
$headExtra = '<link rel="alternate" type="application/json" href="' . e($apiUrl) . '" title="This record as JSON">' . "\n"
    . '<script type="application/ld+json">' . View::jsonLd($d, $pageUrl, $apiUrl) . '</script>' . "\n";

$authText = match ($auth['type']) {
    'none' => !empty($auth['key_optional']) ? 'None required (a free key raises limits)' : 'None — no key, no signup',
    'api_key' => 'Free API key',
    'oauth2' => 'OAuth 2',
    default => $auth['type'],
};

$sections = ['access' => 'Access', 'endpoints' => 'Endpoints', 'example' => 'Example request'];
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

$rail = static function () use ($sections, $d, $categoryName): void { ?>
    <div class="rail-section">
      <h2 class="micro">On this page</h2>
      <ol>
        <?php foreach ($sections as $id => $label): ?>
          <li><a href="#<?= e($id) ?>"><?= e($label) ?></a></li>
        <?php endforeach ?>
      </ol>
      <p class="rail-back"><a href="<?= e(url('/', ['category' => $d['category']])) ?>">More in <?= e($categoryName) ?></a></p>
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
        <p class="kicker micro"><a href="<?= e(url('/', ['category' => $d['category']])) ?>"><?= e($categoryName) ?></a><?php if ($d['status'] !== 'active'): ?> <span class="flag"><?= e($d['status']) ?></span><?php endif ?></p>
        <h1><?= e($d['name']) ?></h1>
        <p class="d-provider">by <?php if (isset($d['provider']['url'])): ?><a href="<?= e($d['provider']['url']) ?>" rel="noopener"><?= e($d['provider']['name']) ?></a><?php else: ?><?= e($d['provider']['name']) ?><?php endif ?>
          <span class="muted">(<?= e($d['provider']['type']) ?>)</span></p>
        <p class="lede"><?= e($d['summary']) ?></p>
      </header>

      <dl class="glance" aria-label="At a glance">
        <div><dt>Auth</dt><dd class="<?= $auth['type'] === 'none' ? 'ok' : 'warn' ?>"><?= e($auth['type'] === 'none' ? 'No key' : View::label('auth', $auth['type'])) ?></dd></div>
        <div><dt>Browser</dt><dd class="<?= $browser ? 'ok' : 'off' ?>"><?= e($browser ? 'CORS ' . ($api['cors'] === 'any' ? '*' : 'reflect') : View::label('cors', $api['cors'])) ?></dd></div>
        <div><dt>Rate limit</dt><dd><?= e(View::rateBudget($api['rate_limit']) ?? (stripos($api['rate_limit']['summary'], 'not documented') !== false ? 'Not documented' : 'See notes')) ?></dd></div>
        <div><dt>License</dt><dd><?= e($license['spdx'] ?? $license['name']) ?></dd></div>
        <div><dt>Commercial use</dt><dd class="<?= $license['commercial_use'] === 'allowed' ? 'ok' : ($license['commercial_use'] === 'prohibited' ? 'warn' : '') ?>"><?= e(View::label('commercial_use', $license['commercial_use'])) ?></dd></div>
        <div><dt>Formats</dt><dd><?= e($formats) ?></dd></div>
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
            <tr><th scope="row">Formats</th><td><?= e($formats) ?></td></tr>
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
        <h2 id="h-example">Example request</h2>
        <p class="aside">Executed on <?= e($ver['last_verified']) ?>, returned HTTP <?= e($ver['http_status']) ?>.<?php if ($d['example']['method'] === 'POST'): ?> This API takes a POST body.<?php endif ?></p>
        <div class="code">
          <div class="code-head"><span class="micro">Request · shell</span><button type="button" class="copy" data-copy="#curl" hidden>Copy</button></div>
          <pre id="curl"><code><?= e(View::curl($d)) ?></code></pre>
        </div>
        <div class="code">
          <div class="code-head"><span class="micro">Response excerpt<?= $excerpt['json'] ? ' · JSON' : '' ?></span><button type="button" class="copy" data-copy="#response" hidden>Copy</button></div>
          <pre id="response" class="response"><code><?= e($excerpt['text']) ?></code></pre>
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
              <li><a href="<?= e($item['url']) ?>" rel="noopener"><?= e($item['title'] ?? 'Hacker News thread') ?></a><?php if (isset($item['points'])): ?> <span class="num muted"><?= e($item['points']) ?> <?= $item['points'] === 1 ? 'point' : 'points' ?></span><?php endif ?> <span class="host">Hacker News</span></li>
            <?php endforeach ?>
          </ul>
        </section>
      <?php endif ?>

      <?php if ($related !== []): ?>
        <section class="section" id="related" aria-labelledby="h-related">
          <h2 id="h-related">Related</h2>
          <ul class="related">
            <?php foreach ($related as $r): ?>
              <li><a href="<?= e(url('/d/' . $r['slug'])) ?>"><?= e($r['name']) ?></a> <span class="muted"><?= e($r['summary']) ?></span></li>
            <?php endforeach ?>
          </ul>
        </section>
      <?php endif ?>

      <section class="section" id="record" aria-labelledby="h-record">
        <h2 id="h-record">Record</h2>
        <p class="tags"><span class="micro">Tags</span>
          <?php foreach ($d['tags'] as $t): ?><a href="<?= e(url('/', ['tag' => $t])) ?>"><?= e($t) ?></a> <?php endforeach ?></p>
        <p class="record-json"><span class="micro">This record as JSON</span> <a href="<?= e($apiUrl) ?>"><code><span class="verb">GET</span> <?= e($apiUrl) ?></code></a></p>
        <p class="aside">Added <?= e($d['added']) ?>. Source file <a href="https://github.com/janoelze/datasets/blob/main/datasets/<?= e(rawurlencode($d['slug'])) ?>.json"><code>datasets/<?= e($d['slug']) ?>.json</code></a> — corrections welcome.</p>
      </section>
    </article>
<?php include __DIR__ . '/partials/footer.php';

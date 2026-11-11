<?php
declare(strict_types=1);
/** About: inclusion criteria, verification, schema, contributing. Vars: $catalog, $request, $urls */
use Datasets\Web\View;

$schema = $catalog->schema();
$fields = View::schemaFields($schema);
$repo = 'https://github.com/janoelze/datasets';

$title = 'About';
$description = 'What qualifies for this library of free datasets with open REST APIs, how each record is verified, and how to contribute one.';
$canonical = abs_url('/about');
$current = 'about';
$sections = ['inclusion' => 'What qualifies', 'verification' => 'Verification', 'schema' => 'Schema', 'contributing' => 'Contributing', 'fields' => 'Field reference'];
$rail = static function () use ($sections): void { ?>
    <div class="rail-section">
      <h2 class="micro">On this page</h2>
      <ol><?php foreach ($sections as $id => $label): ?><li><a href="#<?= e($id) ?>"><?= e($label) ?></a></li><?php endforeach ?></ol>
    </div>
<?php };

include __DIR__ . '/partials/header.php';
?>
    <article class="doc">
      <header class="d-head">
        <p class="kicker micro">About</p>
        <h1>A small, checked library</h1>
        <p class="lede"><?= e($catalog->count()) ?> datasets you can use for free through an open REST API, described precisely enough that a developer — or an agent — can decide in seconds whether one fits, and make the first call without reading the provider’s docs.</p>
      </header>

      <section class="section" id="inclusion" aria-labelledby="h-inclusion">
        <h2 id="h-inclusion">What qualifies</h2>
        <ol class="numbered">
          <li><strong>Free data.</strong> The data can be used at no cost. License restrictions (attribution, non-commercial) are allowed but recorded.</li>
          <li><strong>A free, open API.</strong> An HTTP API anyone can call: either with no credential at all, or with a key that is free and self-service. No sales calls, no credit card, no waiting list.</li>
          <li><strong>A verified example request.</strong> Every entry carries one request that was actually executed, with the date, the status code and a trimmed excerpt of what came back.</li>
          <li><strong>Worth knowing.</strong> Substantial, maintained data. Wrappers around other entries, scraped mirrors and toy APIs without real data are left out.</li>
        </ol>
      </section>

      <section class="section" id="verification" aria-labelledby="h-verification">
        <h2 id="h-verification">How records are verified</h2>
        <p>Each record’s example request is executed by <code>bin/verify.php</code>, sending the record’s own headers and required headers, and has to come back with a 2xx status — or, for a keyed API checked without a key, the 401/403 the record says to expect. Facts that matter for a first call — the auth type, where the key goes, a required <code>User-Agent</code>, the rate limit, CORS — come from the provider’s documentation checked against what the response actually did. Where they disagree, the record says so in its caveats.</p>
        <p><code>verification.last_verified</code> is the date of the last run; <code>verification.with_key</code> is false when an API needs a key and only the unauthenticated error was observed. CORS values are taken from real response headers; <code>unknown</code> means it could not be confirmed.</p>
      </section>

      <section class="section" id="schema" aria-labelledby="h-schema">
        <h2 id="h-schema">How the schema is enforced</h2>
        <p>Each dataset is one file, <code>datasets/&lt;slug&gt;.json</code>, validated against a <a href="<?= e(url('/api/v1/schema')) ?>">JSON Schema</a> (draft 2020-12, unknown properties rejected) plus semantic rules: the slug must equal the file name, the category must exist, <code>related</code> must point at real entries, every URL must be https, dates must not lie in the future, and a keyed API must say where the key goes and where to get one. CI runs the validator on every pull request, so nothing malformed is merged; the site skips any file that fails to load.</p>
      </section>

      <section class="section" id="contributing" aria-labelledby="h-contributing">
        <h2 id="h-contributing">Contributing</h2>
        <p>Open a pull request on <a href="<?= e($repo) ?>">github.com/janoelze/datasets</a> that adds <code>datasets/&lt;slug&gt;.json</code>. Copy an existing record as a template, run the example request yourself and paste a trimmed excerpt of the response, then check it locally:</p>
        <div class="code">
          <div class="code-head"><span class="micro">Validate</span><button type="button" class="copy" data-copy="#validate" hidden>Copy</button></div>
          <pre id="validate"><code>php bin/validate.php datasets/&lt;slug&gt;.json
php bin/verify.php &lt;slug&gt;</code></pre>
        </div>
        <p>Corrections are just as welcome: a changed rate limit, a dead endpoint, a CORS header that turned out different. Each dataset page links to its source file.</p>
      </section>

      <section class="section" id="fields" aria-labelledby="h-fields">
        <h2 id="h-fields">Field reference</h2>
        <p class="aside">Rendered from <a href="<?= e(url('/api/v1/schema')) ?>"><code>schema/dataset.schema.json</code></a>. <span class="req">Required</span> fields are marked.</p>
        <div class="table-wrap">
          <table class="fields-table">
            <thead><tr><th scope="col">Field</th><th scope="col">Type</th><th scope="col">Description</th></tr></thead>
            <tbody>
              <?php foreach ($fields as $f): $depth = substr_count($f['path'], '.'); ?>
                <tr class="depth-<?= e(min($depth, 3)) ?>">
                  <td class="field"><code><?= e($f['path']) ?></code><?php if ($f['required']): ?> <span class="req" title="Required">req</span><?php endif ?></td>
                  <td class="type"><?= e($f['type']) ?></td>
                  <td><?= e($f['description']) ?>
                    <?php if ($f['enum'] !== [] && count($f['enum']) <= 25): ?>
                      <span class="enum"><?php foreach ($f['enum'] as $v): ?><code><?= e($v) ?></code> <?php endforeach ?></span>
                    <?php endif ?>
                  </td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      </section>
    </article>
<?php include __DIR__ . '/partials/footer.php';

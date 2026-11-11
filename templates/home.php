<?php
declare(strict_types=1);
/**
 * Catalogue. Vars: $catalog, $request, $urls, $params (ListParams), $results (ResultPage), $facets,
 * $categories, $tags, $keylessCount, $apiQueryUrl
 *
 * Filtering works without JavaScript (GET form, parameter names = API parameter names);
 * assets/app.js re-fetches this page and swaps #results, #rail-cats and #hidden-filters.
 */
use Datasets\Web\View;

$crit = $params->criteria;
$canon = $params->canonical;
$enums = $catalog->enums();
$categoryNames = array_column($categories, 'name', 'id');
$activeCategory = count($crit['category'] ?? []) === 1 ? $crit['category'][0] : null;
$hasFilters = $canon !== [] && array_diff(array_keys($canon), ['sort']) !== [];

/** Link that keeps the current query but changes some keys (null removes). */
$with = static function (array $changes) use ($canon): string {
    $q = $canon;
    unset($q['offset']);
    foreach ($changes as $k => $v) {
        if ($v === null) {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }

    return url('/', $q);
};

// Visible controls; everything else in the query is kept in hidden inputs so submitting the form
// never silently drops a filter.
$checks = [
    'keyless' => ['true', 'No key needed'],
    'browser' => ['true', 'Works in the browser (CORS)'],
    'has_openapi' => ['true', 'Has an OpenAPI spec'],
    'commercial_use' => ['allowed', 'Commercial use allowed'],
];
$isChecked = static fn (string $name, string $value): bool => ($canon[$name] ?? null) === $value;
$hidden = [];
foreach ($canon as $name => $value) {
    if ($name === 'q' || $name === 'sort' || $name === 'auth' || $name === 'format') {
        continue;
    }
    if (isset($checks[$name]) && $isChecked($name, $checks[$name][0])) {
        continue;
    }
    $hidden[$name] = $value;
}
if (isset($request->query['limit'])) {
    $hidden['limit'] = (string) $results->limit;
}

$selects = [
    'auth' => ['Auth', ['' => 'Any auth'] + array_combine($enums['auth'], array_map(static fn ($v) => View::label('auth', $v), $enums['auth']))],
    'format' => ['Format', ['' => 'Any format'] + array_combine($enums['format'], array_map(static fn ($v) => View::label('format', $v), $enums['format']))],
    'sort' => ['Sort', View::SORTS],
];
foreach (['auth', 'format'] as $name) {
    if (isset($canon[$name]) && !isset($selects[$name][1][$canon[$name]])) {
        $selects[$name][1][$canon[$name]] = str_replace(',', ' or ', $canon[$name]);
    }
}

// Active-filter chips, each with a link that removes just that filter.
$chips = [];
foreach ($canon as $name => $value) {
    if ($name === 'sort' || $name === 'fields') {
        continue;
    }
    if ($name === 'tag') {
        $all = explode(',', $value);
        foreach ($all as $t) {
            $rest = array_values(array_diff($all, [$t]));
            $chips[] = ['tag', $t, $with(['tag' => $rest === [] ? null : implode(',', $rest)])];
        }
        continue;
    }
    $shown = match ($name) {
        'category' => implode(' or ', array_map(static fn ($c) => $categoryNames[$c] ?? $c, explode(',', $value))),
        'q' => '“' . $value . '”',
        default => $value,
    };
    $chips[] = [$name, $shown, $with([$name => null])];
}

$title = null;
$description = sprintf('A curated library of %d free datasets with free, open REST APIs: auth, CORS, rate limits, license and a verified example request for each.', $catalog->count());
if ($activeCategory !== null) {
    $title = ($categoryNames[$activeCategory] ?? $activeCategory) . ' datasets with free APIs';
    $description = ($catalog->category($activeCategory)['description'] ?? '') . ' ' . $description;
} elseif (isset($crit['q'])) {
    $title = 'Search: ' . $crit['q'];
}
$canonical = abs_url('/', $canon);
$current = 'home';

$rail = static function () use ($categories, $activeCategory, $with, $catalog): void { ?>
    <div class="rail-section" id="rail-cats">
      <h2 class="micro">Categories</h2>
      <ol>
        <li><a href="<?= e($with(['category' => null])) ?>" data-filter-link<?= $activeCategory === null ? ' class="current" aria-current="true"' : '' ?>>All<span><?= e($catalog->count()) ?></span></a></li>
        <?php foreach ($categories as $c): ?>
          <li><a href="<?= e($with(['category' => $c['id']])) ?>" data-filter-link<?= $activeCategory === $c['id'] ? ' class="current" aria-current="true"' : '' ?>><?= e($c['name']) ?><span><?= e($c['count']) ?></span></a></li>
        <?php endforeach ?>
      </ol>
    </div>
<?php };

include __DIR__ . '/partials/header.php';
?>
    <header class="masthead">
      <h1>Free datasets with free, open REST APIs</h1>
      <p class="lede">Public data you can query over HTTP today. Each entry says whether you need a key, whether a browser can call it, how fast you may go and what the license allows — with a request that was actually run.</p>
      <p class="counts"><span><strong><?= e($catalog->count()) ?></strong> datasets</span><span><strong><?= e(count($categories)) ?></strong> categories</span><span><strong><?= e($keylessCount) ?></strong> keyless</span></p>
      <p class="agents"><span class="micro">For agents</span> Start with <a href="<?= e(url('/llms.txt')) ?>"><code>/llms.txt</code></a>, query the same catalogue as JSON at <a href="<?= e(url('/api/v1')) ?>"><code>/api/v1</code></a>, described by <a href="<?= e(url('/api/v1/openapi.json')) ?>"><code>/api/v1/openapi.json</code></a>. No key, CORS open.</p>
    </header>

    <form class="filters" role="search" method="get" action="<?= e(url('/')) ?>" data-filter-form aria-label="Filter datasets">
      <div id="hidden-filters">
        <?php foreach ($hidden as $name => $value): ?>
          <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
        <?php endforeach ?>
      </div>
      <div class="search">
        <label for="q" class="sr">Search datasets</label>
        <input type="search" id="q" name="q" value="<?= e($crit['q'] ?? '') ?>" placeholder="Search names, providers, topics, endpoints" autocomplete="off" spellcheck="false" maxlength="200">
        <kbd class="search-key" aria-hidden="true">/</kbd>
        <button type="submit" class="apply">Filter</button>
      </div>
      <div class="controls">
        <fieldset class="checks">
          <legend class="sr">Requirements</legend>
          <?php foreach ($checks as $name => [$value, $label]): ?>
            <label class="check"><input type="checkbox" name="<?= e($name) ?>" value="<?= e($value) ?>"<?= $isChecked($name, $value) ? ' checked' : '' ?>> <?= e($label) ?></label>
          <?php endforeach ?>
        </fieldset>
        <div class="selects">
          <?php foreach ($selects as $name => [$label, $options]):
              $sel = $canon[$name] ?? ($name === 'sort' ? 'name' : ''); ?>
            <label class="select"><span class="micro"><?= e($label) ?></span>
              <select name="<?= e($name) ?>">
                <?php foreach ($options as $v => $l): ?>
                  <option value="<?= e($v) ?>"<?= (string) $v === $sel ? ' selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach ?>
              </select>
            </label>
          <?php endforeach ?>
        </div>
      </div>
    </form>

    <section id="results" class="results" aria-labelledby="results-count">
      <div class="results-head">
        <h2 id="results-count" class="count" role="status"><strong><?= e($results->total) ?></strong> <?= $results->total === 1 ? 'dataset' : 'datasets' ?><?php if ($activeCategory !== null): ?> in <?= e($categoryNames[$activeCategory] ?? $activeCategory) ?><?php endif ?></h2>
        <?php if ($chips !== []): ?>
          <ul class="chips" aria-label="Active filters">
            <?php foreach ($chips as [$name, $shown, $href]): ?>
              <li><a href="<?= e($href) ?>" data-filter-link title="Remove this filter"><span class="chip-k"><?= e(str_replace('_', ' ', $name)) ?></span> <?= e($shown) ?> <span aria-hidden="true">×</span><span class="sr"> (remove)</span></a></li>
            <?php endforeach ?>
            <?php if ($hasFilters): ?><li><a class="clear" href="<?= e(url('/')) ?>" data-filter-link>Clear all</a></li><?php endif ?>
          </ul>
        <?php endif ?>
      </div>

      <?php if ($results->items === []): ?>
        <div class="empty">
          <p>No dataset matches <?= $hasFilters ? 'these filters' : 'this' ?>.</p>
          <p><a href="<?= e(url('/')) ?>" data-filter-link>Show all <?= e($catalog->count()) ?> datasets</a><?php if (isset($crit['q'])): ?>, or try fewer search words — every word has to match<?php endif ?>.</p>
        </div>
      <?php else: ?>
        <div class="rows-head" aria-hidden="true"><span>Dataset</span><span>Auth</span><span>Browser</span><span>Formats</span><span>License</span></div>
        <ol class="rows">
          <?php foreach ($results->items as $d) { include __DIR__ . '/partials/row.php'; } ?>
        </ol>
      <?php endif ?>

      <?php if ($results->pageCount() > 1): ?>
        <nav class="pager" aria-label="Pages">
          <?php if ($results->hasPrev()): ?><a rel="prev" href="<?= e(url('/', $canon + ['limit' => $results->limit, 'offset' => $results->prevOffset() ?: null])) ?>">Previous</a><?php endif ?>
          <span>Page <?= e($results->pageNumber()) ?> of <?= e($results->pageCount()) ?></span>
          <?php if ($results->hasNext()): ?><a rel="next" href="<?= e(url('/', $canon + ['limit' => $results->limit, 'offset' => $results->nextOffset()])) ?>">Next</a><?php endif ?>
        </nav>
      <?php endif ?>

      <p class="bridge"><span class="micro">Same query via API</span>
        <a href="<?= e($apiQueryUrl) ?>"><code><span class="verb">GET</span> <?= e($apiQueryUrl) ?></code></a></p>
    </section>
<?php include __DIR__ . '/partials/footer.php';

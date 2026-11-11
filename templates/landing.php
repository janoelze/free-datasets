<?php
declare(strict_types=1);
/**
 * Landing page for one category (/c/{id}) or one tag (/t/{tag}): a crawlable, self-canonical
 * listing built from the same row partial as the catalogue.
 *
 * Vars: $catalog, $request, $urls, $kind ('category' | 'tag'), $landingKey (id or tag; not $key, which the header partial overwrites), $datasets (sorted by name),
 * $categories (all, with counts); for categories $category; for tags $count, $nearTags (tag => count)
 */
use Datasets\Seo\IndexPolicy;
use Datasets\Seo\Meta;
use Datasets\Seo\StructuredData;
use Datasets\Web\View;

$categoryNames = array_column($categories, 'name', 'id');
$n = count($datasets);
$keyless = count(array_filter($datasets, static fn (array $d): bool => $d['api']['auth']['type'] === 'none'));
$browserReady = count(array_filter($datasets, static fn (array $d): bool => View::browserUsable($d)));
$plural = static fn (int $k, string $one, string $many): string => $k === 1 ? $one : $many;
$facts = sprintf('%d of them need no key and %d can be called from browser JavaScript (CORS). Each entry lists auth, rate limits, license and a request that was actually run.',
    $keyless, $browserReady);

if ($kind === 'category') {
    $pageUrl = abs_url('/c/' . $landingKey);
    $h1 = $category['name'] . ' APIs';
    $title = $category['name'];
    $metaTitle = Meta::categoryTitle($category);
    $ogTitle = 'Free ' . $category['name'] . ' APIs';
    $description = Meta::categoryDescription($category, $keyless);
    $intro = rtrim((string) $category['description'], '.') . '. ' . sprintf('%d %s with free APIs. ', $n, $plural($n, 'dataset', 'datasets')) . $facts;
    $filterUrl = url('/', ['category' => $landingKey]);
    $apiUrl = abs_url('/api/v1/datasets', ['category' => $landingKey]);
    $crumbs = [['Catalogue', abs_url('/')], [(string) $category['name'], $pageUrl]];
    // Tags used in this category that have pages of their own.
    $moreTags = array_slice(array_intersect_key($catalog->tags($datasets), IndexPolicy::landingTags($catalog)), 0, 16, true);
    $moreTagsHeading = 'Tags in ' . $category['name'];
} else {
    $pageUrl = abs_url('/t/' . $landingKey);
    $label = Meta::tagLabel($landingKey);
    $h1 = 'Datasets tagged “' . $landingKey . '”';
    $title = $landingKey;
    $metaTitle = Meta::tagTitle($landingKey, $n);
    $ogTitle = 'Free ' . $label . ' APIs';
    $byCategory = array_count_values(array_map(static fn (array $d): string => (string) $d['category'], $datasets));
    arsort($byCategory);
    $catList = array_map(static fn (string $id): string => $categoryNames[$id] ?? $id, array_keys($byCategory));
    $description = Meta::tagDescription($landingKey, $n, array_map(static fn (array $d): string => (string) $d['name'], array_slice($datasets, 0, 3)));
    $intro = sprintf('%d free %s tagged “%s”, in %s. ', $n, $plural($n, 'dataset', 'datasets'), $landingKey,
        count($catList) === 1 ? $catList[0] : implode(', ', array_slice($catList, 0, -1)) . ' and ' . end($catList)) . $facts;
    $filterUrl = url('/', ['tag' => $landingKey]);
    $apiUrl = abs_url('/api/v1/datasets', ['tag' => $landingKey]);
    $crumbs = [['Catalogue', abs_url('/')], ['Tags', abs_url('/t')], [$landingKey, $pageUrl]];
    $moreTags = $nearTags;
    $moreTagsHeading = 'Related tags';
}
$canonical = $pageUrl;
$current = '';
$jsonLd = (new StructuredData($urls))->collection($pageUrl, $h1, $description, $datasets, $crumbs);
$activeCategory = $kind === 'category' ? $landingKey : null;

$rail = static function () use ($categories, $activeCategory, $catalog): void { ?>
    <div class="rail-section">
      <h2 class="micro">Categories</h2>
      <ol>
        <li><a href="<?= e(url('/')) ?>">All<span><?= e($catalog->count()) ?></span></a></li>
        <?php foreach ($categories as $c): if ($c['count'] === 0) { continue; } ?>
          <li><a href="<?= e(url('/c/' . $c['id'])) ?>"<?= $activeCategory === $c['id'] ? ' class="current" aria-current="page"' : '' ?>><?= e($c['name']) ?><span><?= e($c['count']) ?></span></a></li>
        <?php endforeach ?>
      </ol>
    </div>
    <div class="rail-section">
      <p class="rail-back"><a href="<?= e(url('/t')) ?>">Browse by tag</a></p>
    </div>
<?php };

include __DIR__ . '/partials/header.php';
?>
    <header class="masthead">
      <nav class="kicker micro" aria-label="Breadcrumb">
        <?php foreach (array_slice($crumbs, 0, -1) as $i => [$crumbName, $crumbUrl]): ?><?= $i > 0 ? ' <span aria-hidden="true">/</span> ' : '' ?><a href="<?= e($crumbUrl) ?>"><?= e($crumbName) ?></a><?php endforeach ?>
      </nav>
      <h1><?= e($h1) ?></h1>
      <p class="lede"><?= e($intro) ?></p>
      <p class="counts"><span><strong><?= e($n) ?></strong> <?= e($plural($n, 'dataset', 'datasets')) ?></span><span><strong><?= e($keyless) ?></strong> keyless</span><span><strong><?= e($browserReady) ?></strong> browser-ready</span></p>
      <p class="agents"><span class="micro">Filter</span> <a href="<?= e($filterUrl) ?>">Filter and sort these in the catalogue</a>, or get them as JSON:
        <a href="<?= e($apiUrl) ?>"><code><?= e($apiUrl) ?></code></a></p>
    </header>

    <section class="results" aria-labelledby="results-count">
      <div class="results-head">
        <h2 id="results-count" class="count"><strong><?= e($n) ?></strong> <?= e($plural($n, 'dataset', 'datasets')) ?><?= $kind === 'category' ? ' in ' . e($category['name']) : ' tagged ' . e($landingKey) ?></h2>
      </div>
      <div class="rows-head" aria-hidden="true"><span>Dataset</span><span>Auth</span><span>Browser</span><span>Formats</span><span>License</span></div>
      <ol class="rows">
        <?php foreach ($datasets as $d) { include __DIR__ . '/partials/row.php'; } ?>
      </ol>
    </section>

    <?php if ($moreTags !== []): ?>
      <section class="section" aria-labelledby="h-more-tags">
        <h2 id="h-more-tags"><?= e($moreTagsHeading) ?></h2>
        <p class="tags"><?php foreach ($moreTags as $t => $tc): ?><a href="<?= e(url('/t/' . $t)) ?>"><?= e($t) ?> <span class="muted"><?= e($tc) ?></span></a> <?php endforeach ?></p>
      </section>
    <?php endif ?>

    <section class="section" aria-labelledby="h-other-cats">
      <h2 id="h-other-cats"><?= $kind === 'category' ? 'Other categories' : 'Categories' ?></h2>
      <p class="tags"><?php foreach ($categories as $c): if ($c['id'] === $activeCategory || $c['count'] === 0) { continue; } ?><a href="<?= e(url('/c/' . $c['id'])) ?>"><?= e($c['name']) ?> <span class="muted"><?= e($c['count']) ?></span></a> <?php endforeach ?></p>
    </section>
<?php include __DIR__ . '/partials/footer.php';

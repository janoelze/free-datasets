<?php
declare(strict_types=1);
/**
 * /t: every tag with a landing page (Seo\IndexPolicy::MIN_TAG_DATASETS or more datasets).
 * Vars: $catalog, $request, $urls, $tags (tag => count, most used first), $categories
 */
use Datasets\Seo\IndexPolicy;
use Datasets\Seo\Meta;
use Datasets\Seo\StructuredData;

$byName = $tags;
ksort($byName, SORT_STRING);
$pageUrl = abs_url('/t');
$title = 'Tags';
$metaTitle = Meta::withSite(sprintf('Browse free dataset APIs by tag — %d topics', count($tags)));
$ogTitle = 'Browse free dataset APIs by tag';
$description = Meta::truncate(sprintf('%d topics across %d free datasets with open REST APIs, most used: %s. Each tag page lists auth, CORS, rate limits and license.',
    count($tags), $catalog->count(), implode(', ', array_slice(array_keys($tags), 0, 3))), Meta::DESCRIPTION_MAX);
$canonical = $pageUrl;
$current = '';
$items = [];
foreach ($byName as $t => $n) {
    $items[] = [(string) $t, abs_url('/t/' . $t)];
}
$jsonLd = (new StructuredData($urls))->collectionOf($pageUrl, 'Tags', $description, $items, [['Catalogue', abs_url('/')], ['Tags', $pageUrl]]);

$rail = static function () use ($categories, $catalog): void { ?>
    <div class="rail-section">
      <h2 class="micro">Categories</h2>
      <ol>
        <li><a href="<?= e(url('/')) ?>">All<span><?= e($catalog->count()) ?></span></a></li>
        <?php foreach ($categories as $c): if ($c['count'] === 0) { continue; } ?>
          <li><a href="<?= e(url('/c/' . $c['id'])) ?>"><?= e($c['name']) ?><span><?= e($c['count']) ?></span></a></li>
        <?php endforeach ?>
      </ol>
    </div>
<?php };

include __DIR__ . '/partials/header.php';
?>
    <header class="masthead">
      <nav class="kicker micro" aria-label="Breadcrumb"><a href="<?= e(url('/')) ?>">Catalogue</a></nav>
      <h1>Browse by tag</h1>
      <p class="lede">Every topic used by at least <?= e(IndexPolicy::MIN_TAG_DATASETS) ?> datasets, A–Z. Rarer tags are still searchable from the <a href="<?= e(url('/')) ?>">catalogue</a>.</p>
      <p class="counts"><span><strong><?= e(count($tags)) ?></strong> tags</span><span><strong><?= e($catalog->count()) ?></strong> datasets</span></p>
    </header>

    <section class="section" aria-labelledby="h-all-tags">
      <h2 id="h-all-tags">All tags</h2>
      <p class="tags"><?php foreach ($byName as $t => $n): ?><a href="<?= e(url('/t/' . $t)) ?>"><?= e($t) ?> <span class="muted"><?= e($n) ?></span></a> <?php endforeach ?></p>
    </section>
<?php include __DIR__ . '/partials/footer.php';

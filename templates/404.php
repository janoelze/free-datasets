<?php
declare(strict_types=1);
/** Not found. Vars: $catalog, $request, $urls, $path */
$title = 'Not found';
$description = 'This page does not exist.';
$canonical = null; // a 404 has no canonical URL
$current = '';
$headExtra = '<meta name="robots" content="noindex">' . "\n";
$guess = null;
if (preg_match('~^/d/([^/]+)~', $path, $m) === 1) {
    $guess = trim(str_replace('-', ' ', rawurldecode($m[1])));
}
include __DIR__ . '/partials/header.php';
?>
    <article class="doc">
      <header class="d-head">
        <p class="kicker micro">404</p>
        <h1>Not found</h1>
        <p class="lede">Nothing lives at <code class="url"><?= e(rawurldecode($path)) ?></code>.</p>
      </header>
      <div class="prose">
        <p><?php if ($guess !== null && $guess !== ''): ?><a href="<?= e(url('/', ['q' => $guess])) ?>">Search the catalogue for “<?= e($guess) ?>”</a>, or browse <?php else: ?>Browse <?php endif ?>
          <a href="<?= e(url('/')) ?>">all <?= e($catalog->count()) ?> datasets</a>. The API lives under <a href="<?= e(url('/api')) ?>"><code>/api/v1</code></a>.</p>
      </div>
    </article>
<?php include __DIR__ . '/partials/footer.php';

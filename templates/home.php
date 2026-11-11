<?php
declare(strict_types=1);
/** Placeholder (frontend worker replaces). Vars: $catalog, $request, $urls, $params, $results, $facets, $categories, $tags */
?><!doctype html>
<html lang="en"><meta charset="utf-8"><title>Free datasets with open APIs</title>
<h1>Free datasets with open APIs</h1>
<p><?= e($results->total) ?> datasets</p>
<ul>
<?php foreach ($results->items as $d): ?>
  <li><a href="<?= e(url('/d/' . $d['slug'])) ?>"><?= e($d['name']) ?></a>: <?= e($d['summary']) ?></li>
<?php endforeach ?>
</ul>
<p><a href="<?= e(url('/api')) ?>">API</a> · <a href="<?= e(url('/about')) ?>">About</a></p>
</html>

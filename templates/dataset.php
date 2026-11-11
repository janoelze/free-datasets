<?php
declare(strict_types=1);
/** Placeholder (frontend worker replaces). Vars: $catalog, $request, $urls, $dataset, $category, $related, $apiUrl */
?><!doctype html>
<html lang="en"><meta charset="utf-8"><title><?= e($dataset['name']) ?></title>
<h1><?= e($dataset['name']) ?></h1>
<p><?= e($dataset['summary']) ?></p>
<p>Category: <?= e($category['name'] ?? $dataset['category']) ?> · <a href="<?= e($apiUrl) ?>">JSON</a></p>
<p><a href="<?= e(url('/')) ?>">All datasets</a></p>
</html>

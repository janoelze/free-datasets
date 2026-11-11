<?php
declare(strict_types=1);
/**
 * Page head + opening of the shell and the left rail.
 *
 * Vars (set by the including template):
 *   $title        page title (without site suffix; null on the home page)
 *   $metaTitle    optional complete <title> (Seo\Meta); default "$title · site name"
 *   $ogTitle      optional title for social cards; default $title
 *   $description  meta description
 *   $canonical    absolute canonical URL, or null for none (404)
 *   $robots       optional robots meta content, e.g. 'noindex,follow' (Seo\IndexPolicy)
 *   $jsonLd       optional list of schema.org nodes, rendered as one JSON-LD @graph
 *   $current      'home' | 'api' | 'about' | '' (marks the rail nav)
 *   $rail         optional callable that echoes extra rail sections
 *   $headExtra    optional raw HTML for <head> (already escaped/encoded by the caller)
 *   $ogType       optional, default 'website'
 *   $agentPanel   'corner' (default: "Use with your agent" at the top of <main>), 'masthead' (the
 *                 template includes partials/agent-panel.php itself) or 'none'
 */
use Datasets\Seo\StructuredData;

$siteName = 'Free Dataset APIs';
$fullTitle = $metaTitle ?? (isset($title) && $title !== null ? $title . ' · ' . $siteName : $siteName . ' — free datasets with free, open REST APIs');
$current ??= '';
$ogType ??= 'website';
$canonical ??= null;
$robots ??= null;
$agentPanel ??= 'corner';
$navItems = ['home' => ['/', 'Catalogue'], 'api' => ['/api', 'API'], 'about' => ['/about', 'About']];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($fullTitle) ?></title>
<meta name="description" content="<?= e($description) ?>">
<?php if ($robots !== null): ?><meta name="robots" content="<?= e($robots) ?>">
<?php endif ?>
<?php if ($canonical !== null): ?><link rel="canonical" href="<?= e($canonical) ?>">
<?php endif ?>
<meta property="og:site_name" content="<?= e($siteName) ?>">
<meta property="og:type" content="<?= e($ogType) ?>">
<meta property="og:title" content="<?= e($ogTitle ?? $title ?? $siteName) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<?php if ($canonical !== null): ?><meta property="og:url" content="<?= e($canonical) ?>">
<?php endif ?>
<meta property="og:locale" content="en_US">
<meta property="og:image" content="<?= e(abs_url('/assets/og.png')) ?>">
<meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="<?= e($siteName) ?> — free datasets with free, open REST APIs">
<meta name="twitter:card" content="summary_large_image">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#fffff8" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#151513" media="(prefers-color-scheme: dark)">
<link rel="icon" href="<?= e(asset('assets/favicon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(asset('assets/apple-touch-icon.png')) ?>">
<link rel="alternate" type="application/atom+xml" title="Newly added datasets" href="<?= e(url('/feed.xml')) ?>">
<link rel="preload" href="<?= e(url('/assets/fonts/newsreader.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?= e(url('/assets/fonts/inter.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('assets/style.css')) ?>">
<link rel="alternate" type="text/plain" title="llms.txt" href="<?= e(url('/llms.txt')) ?>">
<link rel="service-desc" type="application/openapi+json" href="<?= e(url('/api/v1/openapi.json')) ?>">
<?= isset($jsonLd) && $jsonLd !== [] ? StructuredData::script($jsonLd) : '' ?>
<?= $headExtra ?? '' ?>
<script>try{var t=localStorage.getItem('theme');if(t==='light'||t==='dark')document.documentElement.dataset.theme=t}catch(e){}document.documentElement.classList.add('js')</script>
<script src="<?= e(asset('assets/app.js')) ?>" defer></script>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<div class="shell">
  <nav class="rail" aria-label="Site">
    <div class="rail-top">
      <a class="brand" href="<?= e(url('/')) ?>"><?= e($siteName) ?></a>
      <ul class="rail-nav">
        <?php foreach ($navItems as $key => [$path, $label]): ?>
          <li><a href="<?= e(url($path)) ?>"<?= $current === $key ? ' class="current" aria-current="page"' : '' ?>><?= e($label) ?></a></li>
        <?php endforeach ?>
      </ul>
    </div>
    <?php if (isset($rail) && is_callable($rail)) { $rail(); } ?>
    <div class="rail-meta">
      <p class="rail-agents">For agents:
        <a href="<?= e(url('/agents')) ?>"<?= $current === 'agents' ? ' aria-current="page"' : '' ?>>setup</a>,
        <a href="<?= e(url('/llms.txt')) ?>">llms.txt</a>,
        <a href="<?= e(url('/api/v1/openapi.json')) ?>">OpenAPI</a></p>
      <button type="button" class="theme-toggle" data-theme-toggle hidden aria-label="Switch colour theme">Dark mode</button>
    </div>
  </nav>
  <main id="main" tabindex="-1">
<?php if ($agentPanel === 'corner') { include __DIR__ . '/agent-panel.php'; } ?>

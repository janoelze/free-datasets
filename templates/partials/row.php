<?php
declare(strict_types=1);
/** One dataset row in the catalogue list. Vars: $d (dataset), $categoryNames (id => name) */
use Datasets\Web\View;

$auth = $d['api']['auth'];
$license = $d['data']['license'];
$licenseShort = $license['spdx'] ?? $license['name'];
$browser = View::browserUsable($d);
?>
<li class="row">
  <div class="row-main">
    <h3 class="row-title"><a href="<?= e(url('/d/' . $d['slug'])) ?>"><?= e($d['name']) ?></a><?php if ($d['status'] !== 'active'): ?> <span class="flag"><?= e($d['status']) ?></span><?php endif ?></h3>
    <p class="row-summary"><?= e($d['summary']) ?></p>
    <p class="row-by"><?= e($d['provider']['name']) ?> <span class="cat"><span class="sep">·</span> <a href="<?= e(url('/', ['category' => $d['category']])) ?>" data-filter-link><?= e($categoryNames[$d['category']] ?? $d['category']) ?></a></span></p>
  </div>
  <dl class="row-facts">
    <div class="f-auth"><dt>Auth</dt><dd class="<?= $auth['type'] === 'none' ? 'ok' : 'warn' ?>"><?= e($auth['type'] === 'none' ? 'No key' : ($auth['type'] === 'api_key' ? 'Free key' : 'OAuth')) ?></dd></div>
    <div class="f-cors"><dt>Browser</dt><dd class="<?= $browser ? 'ok' : 'off' ?>" title="CORS: <?= e(View::label('cors', $d['api']['cors'])) ?>"><?= e($browser ? 'CORS' : ($d['api']['cors'] === 'unknown' ? 'CORS ?' : 'No CORS')) ?></dd></div>
    <div class="f-fmt"><dt>Formats</dt><dd><?= View::formatBadges($d['api']['formats'], 4) ?></dd></div>
    <div class="f-lic"><dt>License</dt><dd title="<?= e($license['name'] . ' — commercial use: ' . $license['commercial_use']) ?>"><span class="lic"><?= e($licenseShort) ?></span><?php if ($license['commercial_use'] !== 'allowed'): ?> <span class="nc" title="Commercial use: <?= e($license['commercial_use']) ?>"><?= e(['restricted' => 'comm. restricted', 'prohibited' => 'non-comm.', 'unknown' => 'comm. ?'][$license['commercial_use']] ?? $license['commercial_use']) ?></span><?php endif ?></dd></div>
  </dl>
</li>

<?php

declare(strict_types=1);

/*
 * File-convention check for dataset records (the validator does not enforce these):
 * 2-space indent, unescaped slashes/unicode, trailing newline (Datasets\Json::encodeFile),
 * and object keys in the order the schema lists its properties.
 *
 *   php .claude/skills/add-datasets/check-format.php [--fix] FILE...
 *
 * Exit status 1 when a file is not in canonical form (without --fix). --fix rewrites files in place.
 */

$root = dirname(__DIR__, 3);
require $root . '/src/bootstrap.php';

use Datasets\Json;

$fix = false;
$files = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--fix') {
        $fix = true;
    } else {
        $files[] = $arg;
    }
}
if ($files === []) {
    fwrite(STDERR, "Usage: php .claude/skills/add-datasets/check-format.php [--fix] FILE...\n");
    exit(2);
}

$schema = json_decode((string) file_get_contents($root . '/schema/dataset.schema.json'), true, 512, JSON_THROW_ON_ERROR);

/** Reorder object keys to schema property order (unknown keys keep their place at the end). */
function reorder(mixed $v, array $s): mixed
{
    if (is_array($v)) {
        return array_map(static fn ($x) => reorder($x, is_array($s['items'] ?? null) ? $s['items'] : []), $v);
    }
    if (!$v instanceof stdClass) {
        return $v;
    }
    $props = is_array($s['properties'] ?? null) ? $s['properties'] : [];
    $extra = is_array($s['additionalProperties'] ?? null) ? $s['additionalProperties'] : [];
    $out = new stdClass();
    foreach (array_keys($props) as $k) {
        if (property_exists($v, $k)) {
            $out->{$k} = reorder($v->{$k}, $props[$k]);
        }
    }
    foreach (get_object_vars($v) as $k => $x) {
        if (!property_exists($out, $k)) {
            $out->{$k} = reorder($x, $props[$k] ?? $extra);
        }
    }

    return $out;
}

$bad = 0;
foreach ($files as $f) {
    $raw = @file_get_contents($f);
    $doc = is_string($raw) ? json_decode($raw, false) : null;
    if (!$doc instanceof stdClass) {
        fwrite(STDOUT, "$f: not a JSON object\n");
        $bad++;
        continue;
    }
    $canonical = Json::encodeFile(reorder($doc, $schema));
    if ($canonical === $raw) {
        continue;
    }
    if ($fix) {
        file_put_contents($f, $canonical);
        fwrite(STDOUT, "$f: rewritten\n");
    } else {
        fwrite(STDOUT, "$f: not canonical (key order or formatting); run with --fix\n");
        $bad++;
    }
}
if ($bad === 0 && !$fix) {
    fwrite(STDOUT, sprintf("OK: %d file%s canonical.\n", count($files), count($files) === 1 ? '' : 's'));
}
exit($bad === 0 ? 0 : 1);

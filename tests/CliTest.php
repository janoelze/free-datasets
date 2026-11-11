<?php

declare(strict_types=1);

$root = dirname(__DIR__);

test('cli: validate.php passes on valid fixtures', function () use ($root) {
    [$code, $out] = run_php("$root/bin/validate.php", ['--dir=' . fixtures() . '/datasets']);
    assert_same(0, $code, $out);
    assert_contains('OK: 4 files valid.', $out);
    [$code, $out] = run_php("$root/bin/validate.php", ['--quiet'], ['APP_DATASETS_DIR' => fixtures() . '/datasets']);
    assert_same(0, $code);
    assert_same('', $out, '--quiet prints nothing on success');
});

test('cli: validate.php reports file: pointer: message and exits 1', function () use ($root) {
    [$code, $out] = run_php("$root/bin/validate.php", ['--dir=' . fixtures() . '/invalid']);
    assert_same(1, $code);
    assert_contains('tests/fixtures/invalid/broken.json: /: invalid JSON', $out);
    assert_contains('tests/fixtures/invalid/wrong-name.json: /slug: must equal the file name "wrong-name"', $out);
    assert_contains('tests/fixtures/invalid/wrong-name.json: /links/docs: must use https', $out);
    assert_contains('FAIL: 5 errors in 2 of 3 files.', $out);
    [$code, $out] = run_php("$root/bin/validate.php", ['--quiet', '--dir=' . fixtures() . '/invalid']);
    assert_same(1, $code);
    assert_same(5, substr_count($out, "\n"), '--quiet prints only the error lines');
    [$code, $out] = run_php("$root/bin/validate.php", [fixtures() . '/invalid/usgs-earthquakes.json']);
    assert_same(0, $code, 'single file');
});

test('cli: build.php writes the sorted catalogue, refuses on errors', function () use ($root) {
    $dir = temp_dir();
    $cache = "$dir/catalog.json";
    [$code, $out, $err] = run_php("$root/bin/build.php", [], ['APP_DATASETS_DIR' => fixtures() . '/datasets', 'APP_CACHE_FILE' => $cache]);
    assert_same(0, $code, $err);
    assert_contains('Wrote 4 datasets', $out);
    $c = json_decode((string) file_get_contents($cache), true);
    assert_same(['nasa-apod', 'open-meteo', 'usgs-earthquakes', 'wikidata'], array_column($c['datasets'], 'slug'));

    $cache2 = "$dir/catalog2.json";
    [$code, , $err] = run_php("$root/bin/build.php", [], ['APP_DATASETS_DIR' => fixtures() . '/invalid', 'APP_CACHE_FILE' => $cache2]);
    assert_same(1, $code);
    assert_contains('Build aborted', $err);
    assert_false(file_exists($cache2));
});

test('cli: verify.php rejects unknown slugs', function () use ($root) {
    [$code, , $err] = run_php("$root/bin/verify.php", ['no-such-slug'], ['APP_DATASETS_DIR' => fixtures() . '/datasets']);
    assert_same(2, $code);
    assert_contains('no such dataset', $err);
});

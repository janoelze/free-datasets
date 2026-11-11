<?php

declare(strict_types=1);

namespace Datasets;

use Datasets\Schema\Linter;
use Datasets\Schema\ValidationError;

/**
 * The dataset catalogue: every valid datasets/<slug>.json, plus categories and the schema.
 *
 * Loading: Catalog::load() uses the compiled cache (var/catalog.json, written by bin/build.php)
 * when it exists, was built from the same datasets directory, and is newer than every file in
 * datasets/ and schema/. Otherwise it reads and validates the files directly; invalid files are
 * skipped (see skipped()).
 *
 * Datasets are plain associative arrays exactly as in the JSON files (no computed fields).
 *
 * Filter criteria (all optional, every given criterion must match):
 *   q              string        all whitespace-separated terms must occur (case-insensitive) in
 *                                name, provider name, summary, description, tags or endpoint summaries
 *   category       list|string   OR   (comma-separated strings are split)
 *   tag            list|string   AND
 *   auth, cors, format, style, provider_type, commercial_use, status   list|string  OR
 *   keyless        bool          true = api.auth.type is "none"; false = it is not
 *   browser        bool          true = api.cors is "any" or "reflect"; false = neither
 *   has_openapi    bool          links.openapi present / absent
 */
final class Catalog
{
    public const CACHE_FORMAT = 1;

    /** Sort keys accepted by sort()/query(); "-" prefix = descending. */
    public const SORTS = ['name', '-name', 'added', '-added', 'verified', '-verified'];

    /** Multi-value facets: name => [path into the dataset, is-list]. */
    public const FACETS = [
        'category' => [['category'], false],
        'tag' => [['tags'], true],
        'auth' => [['api', 'auth', 'type'], false],
        'cors' => [['api', 'cors'], false],
        'format' => [['api', 'formats'], true],
        'style' => [['api', 'style'], false],
        'provider_type' => [['provider', 'type'], false],
        'commercial_use' => [['data', 'license', 'commercial_use'], false],
        'status' => [['status'], false],
    ];

    /** Where each enumerated facet's allowed values live in the dataset schema. */
    private const ENUM_PATHS = [
        'auth' => ['properties', 'api', 'properties', 'auth', 'properties', 'type', 'enum'],
        'cors' => ['properties', 'api', 'properties', 'cors', 'enum'],
        'format' => ['properties', 'api', 'properties', 'formats', 'items', 'enum'],
        'style' => ['properties', 'api', 'properties', 'style', 'enum'],
        'provider_type' => ['properties', 'provider', 'properties', 'type', 'enum'],
        'commercial_use' => ['properties', 'data', 'properties', 'license', 'properties', 'commercial_use', 'enum'],
        'status' => ['properties', 'status', 'enum'],
        'pagination' => ['properties', 'api', 'properties', 'pagination', 'enum'],
        'update_frequency' => ['properties', 'data', 'properties', 'update_frequency', 'enum'],
    ];

    /** @var array<string, array<string, mixed>> slug => dataset, sorted by slug */
    private array $datasets = [];

    /** @var list<array{id: string, name: string, description: string}> */
    private array $categories;

    /** @var array<string, mixed> */
    private array $schema;

    /** @var array<string, list<ValidationError>> */
    private array $skipped;

    private string $source;

    /** @var array<string, string> slug => lower-cased search text */
    private array $haystacks = [];

    /**
     * @param list<array<string, mixed>>               $datasets
     * @param list<array{id: string, name: string, description: string}> $categories
     * @param array<string, mixed>                     $schema
     * @param array<string, list<ValidationError>>     $skipped
     */
    private function __construct(array $datasets, array $categories, array $schema, array $skipped, string $source)
    {
        foreach ($datasets as $d) {
            $this->datasets[(string) $d['slug']] = $d;
        }
        ksort($this->datasets, SORT_STRING);
        $this->categories = $categories;
        $this->schema = $schema;
        $this->skipped = $skipped;
        $this->source = $source;
    }

    // ================================================================ construction

    /** Load using the paths in a Config. */
    public static function fromConfig(Config $config): self
    {
        return self::load($config->datasetsDir, $config->schemaDir, $config->cacheFile);
    }

    /**
     * Load from the compiled cache when fresh, else from the dataset files.
     *
     * @param string|null $cacheFile compiled catalogue path (null = never use a cache)
     */
    public static function load(string $datasetsDir, string $schemaDir, ?string $cacheFile = null): self
    {
        $schema = self::readJson($schemaDir . '/dataset.schema.json');
        $categories = self::readJson($schemaDir . '/categories.json');

        if ($cacheFile !== null && self::cacheIsFresh($cacheFile, $datasetsDir, $schemaDir)) {
            $cache = json_decode((string) @file_get_contents($cacheFile), true);
            if (
                is_array($cache)
                && ($cache['format'] ?? null) === self::CACHE_FORMAT
                && ($cache['datasets_dir'] ?? null) === self::realDir($datasetsDir)
                && is_array($cache['datasets'] ?? null)
            ) {
                return new self($cache['datasets'], $categories, $schema, [], 'cache');
            }
        }

        $linter = Linter::fromSchemaDir($schemaDir);
        $datasets = [];
        $skipped = [];
        // `related` is not enforced at load time: one broken file must not hide its neighbours.
        foreach ($linter->lintDirectory($datasetsDir, false) as $file => $result) {
            if ($result['errors'] === [] && $result['data'] !== null) {
                $datasets[] = $result['data'];
            } else {
                $skipped[$file] = $result['errors'];
            }
        }

        return new self($datasets, $categories, $schema, $skipped, 'files');
    }

    /**
     * Build a catalogue from in-memory data (tests, tools). Datasets are trusted as given.
     *
     * @param list<array<string, mixed>> $datasets
     * @param list<array{id: string, name: string, description: string}> $categories
     * @param array<string, mixed> $schema
     */
    public static function fromArrays(array $datasets, array $categories, array $schema): self
    {
        return new self($datasets, $categories, $schema, [], 'memory');
    }

    /**
     * Write the compiled catalogue atomically (used by bin/build.php).
     *
     * @param list<array<string, mixed>> $datasets
     */
    public static function writeCache(string $cacheFile, string $datasetsDir, array $datasets): void
    {
        usort($datasets, static fn (array $a, array $b): int => strcmp((string) $a['slug'], (string) $b['slug']));
        $dir = dirname($cacheFile);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory $dir");
        }
        $payload = [
            'format' => self::CACHE_FORMAT,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'datasets_dir' => self::realDir($datasetsDir),
            'count' => count($datasets),
            'datasets' => $datasets,
        ];
        $tmp = $cacheFile . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, Json::encode($payload) . "\n");
        if (!rename($tmp, $cacheFile)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write $cacheFile");
        }
    }

    /** True when the cache exists and is strictly newer than every dataset and schema file. */
    public static function cacheIsFresh(string $cacheFile, string $datasetsDir, string $schemaDir): bool
    {
        $cacheTime = @filemtime($cacheFile);
        if ($cacheTime === false) {
            return false;
        }
        clearstatcache();
        // The directory mtime changes when files are added or removed.
        $paths = array_merge([$datasetsDir], glob(rtrim($datasetsDir, '/') . '/*.json') ?: [], glob($schemaDir . '/*.json') ?: []);
        foreach ($paths as $p) {
            $t = @filemtime($p);
            if ($t !== false && $t >= $cacheTime) {
                return false;
            }
        }

        return true;
    }

    // ================================================================ lookup

    /** @return list<array<string, mixed>> all datasets, sorted by slug */
    public function all(): array
    {
        return array_values($this->datasets);
    }

    /** @return list<string> */
    public function slugs(): array
    {
        return array_keys($this->datasets);
    }

    public function count(): int
    {
        return count($this->datasets);
    }

    public function has(string $slug): bool
    {
        return isset($this->datasets[$slug]);
    }

    /** @return array<string, mixed>|null */
    public function get(string $slug): ?array
    {
        return $this->datasets[$slug] ?? null;
    }

    /**
     * Datasets listed in $slug's `related` that exist in the catalogue, in the listed order.
     *
     * @return list<array<string, mixed>>
     */
    public function related(string $slug): array
    {
        $out = [];
        foreach ($this->datasets[$slug]['related'] ?? [] as $r) {
            if (isset($this->datasets[$r])) {
                $out[] = $this->datasets[$r];
            }
        }

        return $out;
    }

    /**
     * Categories in categories.json order, each with its dataset count.
     *
     * @return list<array{id: string, name: string, description: string, count: int}>
     */
    public function categories(): array
    {
        $counts = $this->facets()['category'];
        $out = [];
        foreach ($this->categories as $c) {
            $out[] = $c + ['count' => $counts[$c['id']] ?? 0];
        }

        return $out;
    }

    /** @return array{id: string, name: string, description: string, count: int}|null */
    public function category(string $id): ?array
    {
        foreach ($this->categories() as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function categoryIds(): array
    {
        return array_column($this->categories, 'id');
    }

    /**
     * Tag => count, by count descending then tag ascending.
     *
     * @param list<array<string, mixed>>|null $datasets subset to count (default: all)
     * @return array<string, int>
     */
    public function tags(?array $datasets = null): array
    {
        $tags = $this->facets($datasets)['tag'];
        uksort($tags, static fn ($a, $b): int => [$tags[$b], (string) $a] <=> [$tags[$a], (string) $b]);

        return $tags;
    }

    /** @return array<string, mixed> the dataset JSON Schema as in schema/dataset.schema.json */
    public function schema(): array
    {
        return $this->schema;
    }

    /**
     * Allowed values of every enumerated field, read from the schema (plus "category").
     * Keys: category, auth, cors, format, style, provider_type, commercial_use, status,
     * pagination, update_frequency.
     *
     * @return array<string, list<string>>
     */
    public function enums(): array
    {
        $out = ['category' => $this->categoryIds()];
        foreach (self::ENUM_PATHS as $name => $path) {
            $node = $this->schema;
            foreach ($path as $key) {
                $node = is_array($node) ? ($node[$key] ?? null) : null;
            }
            $out[$name] = is_array($node) ? array_values($node) : [];
        }

        return $out;
    }

    /** "cache", "files" or "memory": where the data came from. */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * Files skipped while loading from disk because they failed validation.
     *
     * @return array<string, list<ValidationError>> path => errors
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    // ================================================================ filter / sort / paginate

    /**
     * Filter, sort and paginate in one call.
     *
     * @param array<string, mixed> $criteria see class doc
     */
    public function query(array $criteria = [], string $sort = 'name', int $limit = 50, int $offset = 0): ResultPage
    {
        return $this->paginate($this->sort($this->filter($criteria), $sort), $limit, $offset);
    }

    /**
     * @param array<string, mixed> $criteria see class doc
     * @param list<array<string, mixed>>|null $datasets subset to filter (default: all)
     * @return list<array<string, mixed>> in input order
     */
    public function filter(array $criteria, ?array $datasets = null): array
    {
        $c = self::normaliseCriteria($criteria);
        $out = [];
        foreach ($datasets ?? $this->datasets as $d) {
            if ($this->matches($d, $c)) {
                $out[] = $d;
            }
        }

        return $out;
    }

    /**
     * Normalise loose criteria: comma-separated strings become lists, empty values are
     * dropped, "true"/"false" strings become booleans, q is trimmed.
     *
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    public static function normaliseCriteria(array $criteria): array
    {
        $out = [];
        foreach ($criteria as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if ($key === 'q') {
                $q = trim((string) $value);
                if ($q !== '') {
                    $out['q'] = $q;
                }
            } elseif (in_array($key, ['keyless', 'browser', 'has_openapi'], true)) {
                $out[$key] = is_bool($value) ? $value : in_array(strtolower((string) $value), ['1', 'true', 'yes'], true);
            } elseif (isset(self::FACETS[$key])) {
                $list = [];
                foreach ((array) $value as $v) {
                    foreach (explode(',', (string) $v) as $part) {
                        $part = trim($part);
                        if ($part !== '') {
                            $list[] = $part;
                        }
                    }
                }
                if ($list !== []) {
                    $out[$key] = array_values(array_unique($list));
                }
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $c normalised criteria */
    private function matches(array $d, array $c): bool
    {
        foreach ($c as $key => $want) {
            switch ($key) {
                case 'q':
                    $hay = $this->haystack($d);
                    foreach (preg_split('/\s+/u', self::lower($want)) ?: [] as $term) {
                        if ($term !== '' && !str_contains($hay, $term)) {
                            return false;
                        }
                    }
                    break;
                case 'tag':
                    $have = $d['tags'] ?? [];
                    foreach ($want as $t) {
                        if (!in_array($t, $have, true)) {
                            return false;
                        }
                    }
                    break;
                case 'keyless':
                    if ((($d['api']['auth']['type'] ?? null) === 'none') !== $want) {
                        return false;
                    }
                    break;
                case 'browser':
                    if (in_array($d['api']['cors'] ?? null, ['any', 'reflect'], true) !== $want) {
                        return false;
                    }
                    break;
                case 'has_openapi':
                    if (isset($d['links']['openapi']) !== $want) {
                        return false;
                    }
                    break;
                default:
                    if (!array_intersect(self::facetValues($d, $key), $want)) {
                        return false;
                    }
            }
        }

        return true;
    }

    /**
     * Sort datasets. Ties are broken by name, then slug. Missing dates sort last ascending.
     *
     * @param list<array<string, mixed>> $datasets
     * @return list<array<string, mixed>>
     */
    public function sort(array $datasets, string $sort = 'name'): array
    {
        if (!in_array($sort, self::SORTS, true)) {
            throw new \InvalidArgumentException("Unknown sort \"$sort\"");
        }
        $desc = $sort[0] === '-';
        $field = ltrim($sort, '-');
        $byName = static fn (array $a, array $b): int => strnatcasecmp((string) $a['name'], (string) $b['name']) ?: strcmp((string) $a['slug'], (string) $b['slug']);
        usort($datasets, static function (array $a, array $b) use ($field, $desc, $byName): int {
            if ($field === 'name') {
                $cmp = $byName($a, $b);

                return $desc ? -$cmp : $cmp;
            }
            $va = (string) ($field === 'added' ? ($a['added'] ?? '') : ($a['verification']['last_verified'] ?? ''));
            $vb = (string) ($field === 'added' ? ($b['added'] ?? '') : ($b['verification']['last_verified'] ?? ''));
            $cmp = strcmp($va, $vb);

            return ($desc ? -$cmp : $cmp) ?: $byName($a, $b);
        });

        return $datasets;
    }

    /**
     * @param list<array<string, mixed>> $datasets
     * @param int $limit  >= 1
     * @param int $offset >= 0 (past the end yields an empty page)
     */
    public function paginate(array $datasets, int $limit = 50, int $offset = 0): ResultPage
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        return new ResultPage(array_slice($datasets, $offset, $limit), count($datasets), $limit, $offset);
    }

    /**
     * Counts per facet value. Enumerated facets list every allowed value (zeros included) in
     * schema/categories order; "tag" lists only tags that occur, alphabetically.
     *
     * @param list<array<string, mixed>>|null $datasets subset to count (default: all)
     * @return array<string, array<string, int>> facet => value => count
     */
    public function facets(?array $datasets = null): array
    {
        $enums = $this->enums();
        $out = [];
        foreach (array_keys(self::FACETS) as $facet) {
            $out[$facet] = $facet === 'tag' ? [] : array_fill_keys($enums[$facet], 0);
        }
        foreach ($datasets ?? $this->datasets as $d) {
            foreach (array_keys(self::FACETS) as $facet) {
                foreach (array_unique(self::facetValues($d, $facet)) as $v) {
                    $out[$facet][$v] = ($out[$facet][$v] ?? 0) + 1;
                }
            }
        }
        ksort($out['tag'], SORT_STRING);

        return $out;
    }

    /**
     * Values of one facet for a dataset (e.g. facetValues($d, 'format') = ["json", "csv"]).
     *
     * @return list<string>
     */
    public static function facetValues(array $d, string $facet): array
    {
        [$path, $isList] = self::FACETS[$facet] ?? throw new \InvalidArgumentException("Unknown facet \"$facet\"");
        $node = $d;
        foreach ($path as $key) {
            $node = is_array($node) ? ($node[$key] ?? null) : null;
        }
        if ($node === null) {
            return [];
        }

        return $isList ? array_values(array_map('strval', (array) $node)) : [(string) $node];
    }

    // ================================================================ internals

    private function haystack(array $d): string
    {
        $slug = (string) $d['slug'];
        if (!isset($this->haystacks[$slug])) {
            $parts = [$d['name'] ?? '', $d['provider']['name'] ?? '', $d['summary'] ?? '', $d['description'] ?? ''];
            $parts = array_merge($parts, $d['tags'] ?? []);
            foreach ($d['endpoints'] ?? [] as $ep) {
                $parts[] = $ep['summary'] ?? '';
            }
            $this->haystacks[$slug] = self::lower(implode("\n", array_map('strval', $parts)));
        }

        return $this->haystacks[$slug];
    }

    private static function lower(string $s): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    /** @return mixed */
    private static function readJson(string $path): mixed
    {
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("Cannot read $path");
        }

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function realDir(string $dir): string
    {
        return realpath($dir) ?: rtrim($dir, '/');
    }
}

<?php

declare(strict_types=1);

namespace Datasets\Api;

use Datasets\Catalog;

/**
 * Parses and validates the query parameters of GET /api/v1/datasets (and the filter subset
 * accepted by /api/v1/facets). The HTML home page can reuse it in lenient mode.
 */
final class ListParams
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    /** Filter parameters with enumerated values (comma-separated or repeated = OR). */
    public const ENUM_FILTERS = ['category', 'auth', 'cors', 'format', 'style', 'provider_type', 'commercial_use', 'status'];

    public const BOOL_FILTERS = ['keyless', 'browser', 'has_openapi'];

    public const FILTERS = ['q', 'category', 'tag', 'auth', 'cors', 'format', 'style', 'provider_type', 'commercial_use', 'status', 'keyless', 'browser', 'has_openapi'];

    public const PAGING = ['sort', 'limit', 'offset', 'fields'];

    /** Always present on API dataset objects regardless of `fields`. */
    public const ALWAYS_FIELDS = ['slug', 'url', 'html_url'];

    /**
     * @param array<string, mixed>                 $criteria normalised Catalog criteria
     * @param list<string>|null                    $fields   null = all fields
     * @param list<array{parameter: string, detail: string}> $errors
     * @param array<string, string>                $canonical canonical query (for links), in a stable order
     */
    private function __construct(
        public readonly array $criteria,
        public readonly string $sort,
        public readonly int $limit,
        public readonly int $offset,
        public readonly ?array $fields,
        public readonly array $errors,
        public readonly array $canonical,
    ) {
    }

    /**
     * @param array<string, list<string>> $query parsed query (Request::parseQuery)
     * @param bool $paging  accept sort/limit/offset/fields (false for /facets)
     * @param bool $strict  report unknown parameters and invalid values as errors; when false,
     *                      invalid values are silently dropped (for HTML pages)
     */
    public static function parse(array $query, Catalog $catalog, bool $paging = true, bool $strict = true): self
    {
        $errors = [];
        $criteria = [];
        $canonical = [];
        $enums = $catalog->enums();
        $allowed = $paging ? array_merge(self::FILTERS, self::PAGING) : self::FILTERS;
        $err = static function (string $param, string $detail) use (&$errors): void {
            $errors[] = ['parameter' => $param, 'detail' => $detail];
        };

        foreach (array_keys($query) as $name) {
            if (!in_array($name, $allowed, true)) {
                $err((string) $name, 'unknown parameter; allowed: ' . implode(', ', $allowed));
            }
        }

        $single = static function (string $name) use ($query, $err): ?string {
            $values = $query[$name] ?? [];
            if (count($values) > 1) {
                $err($name, 'must not be repeated');
            }

            return $values === [] ? null : $values[count($values) - 1];
        };
        $list = static function (string $name) use ($query): array {
            $out = [];
            foreach ($query[$name] ?? [] as $v) {
                foreach (explode(',', $v) as $part) {
                    $part = trim($part);
                    if ($part !== '' && !in_array($part, $out, true)) {
                        $out[] = $part;
                    }
                }
            }

            return $out;
        };

        // q
        $q = $single('q');
        if ($q !== null) {
            $q = trim($q);
            if (self::len($q) > 200) {
                $err('q', 'must be at most 200 characters');
            } elseif ($q !== '') {
                $criteria['q'] = $q;
                $canonical['q'] = $q;
            }
        }

        // enumerated filters
        foreach (self::ENUM_FILTERS as $name) {
            $values = $list($name);
            if ($values === []) {
                continue;
            }
            $bad = array_values(array_diff($values, $enums[$name]));
            if ($bad !== []) {
                $err($name, sprintf('invalid value%s %s; allowed: %s', count($bad) > 1 ? 's' : '', implode(', ', $bad), implode(', ', $enums[$name])));
                $values = array_values(array_intersect($values, $enums[$name]));
            }
            if ($values !== []) {
                $criteria[$name] = $values;
                $canonical[$name] = implode(',', $values);
            }
        }

        // tag (AND)
        $tags = $list('tag');
        if ($tags !== []) {
            foreach ($tags as $t) {
                if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $t) !== 1) {
                    $err('tag', "invalid tag \"$t\"; tags are lower-case kebab-case");
                }
            }
            $tags = array_values(array_filter($tags, static fn ($t) => preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $t) === 1));
            if ($tags !== []) {
                $criteria['tag'] = $tags;
                $canonical['tag'] = implode(',', $tags);
            }
        }

        // booleans
        foreach (self::BOOL_FILTERS as $name) {
            $v = $single($name);
            if ($v === null || $v === '') {
                continue;
            }
            $b = match (strtolower($v)) {
                'true', '1', 'yes' => true,
                'false', '0', 'no' => false,
                default => null,
            };
            if ($b === null) {
                $err($name, 'must be true or false');
            } else {
                $criteria[$name] = $b;
                $canonical[$name] = $b ? 'true' : 'false';
            }
        }

        $sort = 'name';
        $limit = self::DEFAULT_LIMIT;
        $offset = 0;
        $fields = null;
        if ($paging) {
            $s = $single('sort');
            if ($s !== null && $s !== '') {
                if (in_array($s, Catalog::SORTS, true)) {
                    $sort = $s;
                } else {
                    $err('sort', 'must be one of: ' . implode(', ', Catalog::SORTS));
                }
            }
            $l = $single('limit');
            if ($l !== null && $l !== '') {
                if (preg_match('/^\d{1,4}$/', $l) === 1 && (int) $l >= 1 && (int) $l <= self::MAX_LIMIT) {
                    $limit = (int) $l;
                } else {
                    $err('limit', 'must be an integer between 1 and ' . self::MAX_LIMIT);
                }
            }
            $o = $single('offset');
            if ($o !== null && $o !== '') {
                if (preg_match('/^\d{1,9}$/', $o) === 1) {
                    $offset = (int) $o;
                } else {
                    $err('offset', 'must be a non-negative integer');
                }
            }
            $f = $list('fields');
            if ($f !== []) {
                $known = array_merge(array_keys($catalog->schema()['properties'] ?? []), ['url', 'html_url']);
                $bad = array_values(array_diff($f, $known));
                if ($bad !== []) {
                    $err('fields', 'unknown field' . (count($bad) > 1 ? 's ' : ' ') . implode(', ', $bad) . '; allowed: ' . implode(', ', $known));
                }
                $fields = array_values(array_intersect($f, $known));
            }
            if ($sort !== 'name') {
                $canonical['sort'] = $sort;
            }
            if ($fields !== null && $fields !== []) {
                $canonical['fields'] = implode(',', $fields);
            }
        }

        return new self($criteria, $sort, $limit, $offset, $fields === [] ? null : $fields, $strict ? $errors : [], $canonical);
    }

    private static function len(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    }
}

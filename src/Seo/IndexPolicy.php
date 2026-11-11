<?php

declare(strict_types=1);

namespace Datasets\Seo;

use Datasets\Api\ListParams;
use Datasets\Catalog;
use Datasets\Url;

/**
 * Which catalogue URLs search engines should index, and what each one canonicalises to.
 *
 *   /                          indexable, canonical /
 *   /?sort=… (only)            same set as / in another order: canonical /
 *   /?category=x (only)        same set as /c/x: canonical /c/x (kept, not redirected: it is the
 *                              in-place filter view the home page's JS and form produce)
 *   /?tag=x (only, ≥3 sets)    canonical /t/x
 *   unknown/invalid params     dropped before deciding (?utm_source=… -> canonical /)
 *   everything else            q, other facets, several filters, limit/offset, thin tags:
 *                              noindex,follow; canonical = the normalised URL itself
 *
 * "Normalised" = known parameters only, keys in a fixed order, comma lists sorted, default sort
 * removed, so ?tag=b,a&category=x and ?category=x&tag=a,b name the same URL.
 */
final class IndexPolicy
{
    /** Tags need at least this many datasets for a landing page (fewer = thin content). */
    public const MIN_TAG_DATASETS = 3;

    private function __construct(
        public readonly string $canonical,
        public readonly ?string $robots,
        /** 'home' | 'category' | 'tag' | null (not a landing view) */
        public readonly ?string $landing,
    ) {
    }

    public function indexable(): bool
    {
        return $this->robots === null;
    }

    /** @param array<string, list<string>> $rawQuery */
    public static function home(array $rawQuery, ListParams $params, Catalog $catalog, Url $url): self
    {
        $canon = self::normalise($params->canonical);
        unset($canon['fields']); // the HTML page ignores fields
        $paged = isset($rawQuery['limit']) || isset($rawQuery['offset']);
        $facets = $canon;
        unset($facets['sort']);

        if (!$paged) {
            if ($facets === []) {
                return new self($url->absolute('/'), null, 'home');
            }
            if (array_keys($facets) === ['category'] && !str_contains($facets['category'], ',')) {
                return new self($url->absolute('/c/' . rawurlencode($facets['category'])), null, 'category');
            }
            if (array_keys($facets) === ['tag'] && !str_contains($facets['tag'], ',') && self::tagHasPage($catalog, $facets['tag'])) {
                return new self($url->absolute('/t/' . rawurlencode($facets['tag'])), null, 'tag');
            }
        }
        if ($paged) {
            $canon['limit'] = (string) $params->limit;
            if ($params->offset > 0) {
                $canon['offset'] = (string) $params->offset;
            }
        }

        return new self($url->absolute('/', $canon), 'noindex,follow', null);
    }

    public static function tagHasPage(Catalog $catalog, string $tag): bool
    {
        return ($catalog->tags()[$tag] ?? 0) >= self::MIN_TAG_DATASETS;
    }

    /**
     * Tags that get a landing page, tag => count, most used first.
     *
     * @return array<string, int>
     */
    public static function landingTags(Catalog $catalog): array
    {
        return array_filter($catalog->tags(), static fn (int $n): bool => $n >= self::MIN_TAG_DATASETS);
    }

    /**
     * Stable parameter order and sorted comma lists.
     *
     * @param array<string, string> $query
     * @return array<string, string>
     */
    public static function normalise(array $query): array
    {
        $order = array_flip(array_merge(ListParams::FILTERS, ListParams::PAGING));
        $out = [];
        foreach ($query as $k => $v) {
            if (!isset($order[$k])) {
                continue;
            }
            if (in_array($k, ListParams::ENUM_FILTERS, true) || $k === 'tag') {
                $parts = explode(',', $v);
                sort($parts, SORT_STRING);
                $v = implode(',', $parts);
            }
            $out[$k] = $v;
        }
        uksort($out, static fn ($a, $b) => $order[$a] <=> $order[$b]);

        return $out;
    }
}

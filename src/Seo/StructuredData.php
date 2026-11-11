<?php

declare(strict_types=1);

namespace Datasets\Seo;

use Datasets\Url;

/**
 * schema.org JSON-LD. Every page gets one <script type="application/ld+json"> with an @graph;
 * shared nodes (WebSite, Organization, DataCatalog) are referenced by @id so they are defined once
 * per page and agree across pages. Nothing is invented: properties we cannot fill truthfully from
 * the record are left out (see docs/ARCHITECTURE.md, "SEO").
 */
final class StructuredData
{
    public const REPO = 'https://github.com/janoelze/datasets';

    public function __construct(private readonly Url $url)
    {
    }

    public function websiteId(): string
    {
        return $this->url->absolute('/') . '#website';
    }

    public function organizationId(): string
    {
        return $this->url->absolute('/') . '#organization';
    }

    public function catalogId(): string
    {
        return $this->url->absolute('/') . '#catalog';
    }

    /** @return array<string, mixed> */
    public function website(string $description): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => $this->websiteId(),
            'url' => $this->url->absolute('/'),
            'name' => Meta::SITE,
            'description' => $description,
            'inLanguage' => 'en',
            'publisher' => ['@id' => $this->organizationId()],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $this->url->absolute('/') . '?q={search_term_string}'],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function organization(): array
    {
        return [
            '@type' => 'Organization',
            '@id' => $this->organizationId(),
            'name' => Meta::SITE,
            'url' => $this->url->absolute('/'),
            'logo' => ['@type' => 'ImageObject', 'url' => $this->url->absolute('/assets/apple-touch-icon.png'), 'width' => 180, 'height' => 180],
            'sameAs' => [self::REPO],
        ];
    }

    /** @return array<string, mixed> */
    public function dataCatalog(string $description): array
    {
        return [
            '@type' => 'DataCatalog',
            '@id' => $this->catalogId(),
            'name' => Meta::SITE,
            'url' => $this->url->absolute('/'),
            'description' => $description,
            'inLanguage' => 'en',
            'isAccessibleForFree' => true,
            'publisher' => ['@id' => $this->organizationId()],
        ];
    }

    /**
     * CollectionPage whose main entity is an ItemList of dataset pages.
     *
     * @param list<array<string, mixed>> $datasets in display order
     * @param list<array{0: string, 1: string}> $crumbs [name, absolute url] from the home page down
     * @return list<array<string, mixed>>
     */
    public function collection(string $pageUrl, string $name, string $description, array $datasets, array $crumbs = [], bool $withNames = true): array
    {
        $items = [];
        foreach (array_values($datasets) as $d) {
            $items[] = [$withNames ? (string) $d['name'] : null, $this->url->absolute('/d/' . rawurlencode((string) $d['slug']))];
        }

        return $this->collectionOf($pageUrl, $name, $description, $items, $crumbs);
    }

    /**
     * @param list<array{0: ?string, 1: string}> $items  [name or null, absolute url]
     * @param list<array{0: string, 1: string}>  $crumbs [name, absolute url]
     * @return list<array<string, mixed>>
     */
    public function collectionOf(string $pageUrl, string $name, string $description, array $items, array $crumbs = []): array
    {
        $list = [];
        foreach ($items as $i => [$itemName, $itemUrl]) {
            $list[] = array_filter(['@type' => 'ListItem', 'position' => $i + 1, 'url' => $itemUrl, 'name' => $itemName], static fn ($v) => $v !== null);
        }
        $page = [
            '@type' => 'CollectionPage',
            '@id' => $pageUrl . '#page',
            'url' => $pageUrl,
            'name' => $name,
            'description' => $description,
            'inLanguage' => 'en',
            'isPartOf' => ['@id' => $this->websiteId()],
            'mainEntity' => [
                '@type' => 'ItemList',
                'numberOfItems' => count($list),
                'itemListElement' => $list,
            ],
        ];
        $graph = [$page];
        if ($crumbs !== []) {
            $graph[0]['breadcrumb'] = ['@id' => $pageUrl . '#breadcrumb'];
            $graph[] = $this->breadcrumbs($pageUrl, $crumbs);
        }

        return $graph;
    }

    /**
     * @param list<array{0: string, 1: string}> $crumbs [name, absolute url]
     * @return array<string, mixed>
     */
    public function breadcrumbs(string $pageUrl, array $crumbs): array
    {
        $items = [];
        foreach ($crumbs as $i => [$name, $itemUrl]) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $itemUrl];
        }

        return ['@type' => 'BreadcrumbList', '@id' => $pageUrl . '#breadcrumb', 'itemListElement' => $items];
    }

    /**
     * Dataset page: the page itself (ItemPage, with the record's dates), the Dataset it
     * describes, the WebAPI serving it, and the breadcrumb trail.
     *
     * @param array<string, mixed>      $d
     * @param array<string, mixed>|null $category
     * @return list<array<string, mixed>>
     */
    public function dataset(array $d, ?array $category, string $description): array
    {
        $pageUrl = $this->url->absolute('/d/' . rawurlencode((string) $d['slug']));
        $apiUrl = $this->url->absolute('/api/v1/datasets/' . rawurlencode((string) $d['slug']));
        $provider = array_filter([
            '@type' => ($d['provider']['type'] ?? '') === 'individual' ? 'Person' : 'Organization',
            'name' => $d['provider']['name'] ?? null,
            'url' => $d['provider']['url'] ?? null,
        ]);
        $license = $d['data']['license'] ?? [];
        $coverage = $d['data']['coverage'] ?? [];
        $categoryName = (string) ($category['name'] ?? $d['category']);
        $temporal = isset($coverage['temporal']) && self::isIsoInterval((string) $coverage['temporal']) ? $coverage['temporal'] : null;

        $dataset = array_filter([
            '@type' => 'Dataset',
            '@id' => $pageUrl . '#dataset',
            'name' => $d['name'],
            'description' => $d['description'],
            'url' => $pageUrl,
            'sameAs' => $d['links']['homepage'] ?? null,
            'keywords' => array_values(array_unique(array_merge([$categoryName], (array) ($d['tags'] ?? [])))),
            'creator' => $provider,
            'publisher' => $provider,
            'license' => $license['url'] ?? (isset($license['spdx']) ? 'https://spdx.org/licenses/' . $license['spdx'] . '.html' : ($license['name'] ?? null)),
            'isAccessibleForFree' => true,
            'spatialCoverage' => $coverage['geographic'] ?? null,
            'temporalCoverage' => $temporal,
            'includedInDataCatalog' => ['@type' => 'DataCatalog', '@id' => $this->catalogId(), 'name' => Meta::SITE, 'url' => $this->url->absolute('/')],
            'distribution' => isset($d['links']['bulk_download'])
                ? [['@type' => 'DataDownload', 'contentUrl' => $d['links']['bulk_download'], 'name' => 'Bulk download']]
                : null,
            'subjectOf' => ['@type' => 'DataFeed', 'url' => $apiUrl, 'encodingFormat' => 'application/json', 'name' => 'Catalogue record (JSON)'],
        ], static fn ($v) => $v !== null && $v !== [] && $v !== '');

        $api = array_filter([
            '@type' => 'WebAPI',
            '@id' => $pageUrl . '#api',
            'name' => $d['name'],
            'description' => $d['summary'],
            'url' => $d['api']['base_url'],
            'documentation' => $d['links']['docs'] ?? null,
            'termsOfService' => $d['links']['terms'] ?? null,
            'provider' => $provider,
        ], static fn ($v) => $v !== null && $v !== []);

        $dates = array_filter([(string) ($d['added'] ?? ''), (string) ($d['verification']['last_verified'] ?? '')]);
        $page = array_filter([
            '@type' => 'ItemPage',
            '@id' => $pageUrl . '#page',
            'url' => $pageUrl,
            'name' => $d['name'],
            'description' => $description,
            'inLanguage' => 'en',
            'isPartOf' => ['@id' => $this->websiteId()],
            'mainEntity' => ['@id' => $pageUrl . '#dataset'],
            // WebAPI is a Service: it has no isAccessibleForFree/about, so the page ties the two together.
            'about' => [['@id' => $pageUrl . '#dataset'], ['@id' => $pageUrl . '#api']],
            'breadcrumb' => ['@id' => $pageUrl . '#breadcrumb'],
            'datePublished' => $d['added'] ?? null,
            'dateModified' => $dates === [] ? null : max($dates),
        ], static fn ($v) => $v !== null);

        $crumbs = [
            ['Catalogue', $this->url->absolute('/')],
            [$categoryName, $this->url->absolute('/c/' . rawurlencode((string) $d['category']))],
            [(string) $d['name'], $pageUrl],
        ];

        return [$page, $dataset, $api, $this->breadcrumbs($pageUrl, $crumbs)];
    }

    /** ISO 8601 date or interval ("2001", "1940-01-01/..", "2010/2020"): what Google expects in temporalCoverage. */
    public static function isIsoInterval(string $s): bool
    {
        $date = '\d{4}(-\d{2}(-\d{2})?)?';

        return preg_match('~^' . $date . '(/(' . $date . '|\.\.))?$~', $s) === 1;
    }

    /**
     * One <script type="application/ld+json"> for a graph; safe to inline (no "</script>", no "&").
     *
     * @param list<array<string, mixed>> $graph
     */
    public static function script(array $graph): string
    {
        $json = json_encode(['@context' => 'https://schema.org', '@graph' => $graph],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

        return '<script type="application/ld+json">' . ($json === false ? '{}' : $json) . '</script>' . "\n";
    }
}

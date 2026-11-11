<?php

declare(strict_types=1);

namespace Datasets\Api;

use Datasets\Catalog;
use Datasets\Url;

/** Renders /llms.txt (https://llmstxt.org/ style Markdown) and /robots.txt. */
final class LlmsTxt
{
    public function __construct(private readonly Catalog $catalog, private readonly Url $url)
    {
    }

    public function render(): string
    {
        $api = $this->url->absolute('/api/v1');
        $enums = $this->catalog->enums();
        $out = [];
        $out[] = '# ' . ApiController::NAME;
        $out[] = '';
        $out[] = '> ' . ApiController::DESCRIPTION;
        $out[] = '';
        $out[] = sprintf('The catalogue currently lists %d datasets in %d categories. Every entry records the API base URL, '
            . 'authentication (most need no key), CORS behaviour, rate limits, pagination, licence, a few representative '
            . 'endpoints and one request that was actually executed, with a trimmed response excerpt.', $this->catalog->count(), count($enums['category']));
        $out[] = '';
        $out[] = '## How to query';
        $out[] = '';
        $out[] = "- API root: $api (JSON, no key, `Access-Control-Allow-Origin: *`)";
        $out[] = "- OpenAPI 3.1: $api/openapi.json";
        $out[] = "- Dataset JSON Schema: $api/schema";
        $out[] = "- List / search: `GET $api/datasets?q=earthquake&keyless=true&limit=10`";
        $out[] = "- One dataset: `GET $api/datasets/{slug}`";
        $out[] = "- Categories: $api/categories, tags: $api/tags, facet counts: $api/facets";
        $out[] = '';
        $out[] = '## Key filters for /datasets';
        $out[] = '';
        $out[] = '- `q`: full-text, all terms must match (name, provider, summary, description, tags, endpoint summaries)';
        $out[] = '- `category`: ' . implode(', ', $enums['category']) . ' (comma = OR)';
        $out[] = '- `tag`: comma-separated, all must match';
        $out[] = '- `auth`: ' . implode(', ', $enums['auth']) . '; `keyless=true` is shorthand for auth=none';
        $out[] = '- `cors`: ' . implode(', ', $enums['cors']) . '; `browser=true` means any or reflect';
        $out[] = '- `format`: ' . implode(', ', $enums['format']);
        $out[] = '- `style`: ' . implode(', ', $enums['style']);
        $out[] = '- `provider_type`, `commercial_use`, `status`, `has_openapi=true|false`';
        $out[] = '- `sort`: ' . implode(', ', Catalog::SORTS) . '; `limit` (1-' . ListParams::MAX_LIMIT . ', default ' . ListParams::DEFAULT_LIMIT . '), `offset`; `fields` for sparse responses';
        $out[] = '';
        $out[] = 'Responses are `{"data": ..., "meta": ..., "links": ...}`; errors are RFC 9457 `application/problem+json`.';

        foreach ($this->catalog->categories() as $cat) {
            $items = $this->catalog->sort($this->catalog->filter(['category' => [$cat['id']]]), 'name');
            if ($items === []) {
                continue;
            }
            $out[] = '';
            $out[] = '## ' . $cat['name'];
            $out[] = '';
            foreach ($items as $d) {
                $flags = [];
                $flags[] = ($d['api']['auth']['type'] ?? '') === 'none' ? 'no key' : (string) ($d['api']['auth']['type'] ?? '');
                if (in_array($d['api']['cors'] ?? '', ['any', 'reflect'], true)) {
                    $flags[] = 'CORS';
                }
                $out[] = sprintf(
                    '- [%s](%s): %s (%s)',
                    self::md((string) $d['name']),
                    $api . '/datasets/' . rawurlencode((string) $d['slug']),
                    self::md((string) $d['summary']),
                    implode(', ', $flags),
                );
            }
        }
        $out[] = '';
        $out[] = '## Optional';
        $out[] = '';
        $out[] = '- [Website](' . $this->url->absolute('/') . '): browsable HTML version';
        $out[] = '- [API documentation](' . $this->url->absolute('/api') . ')';

        return implode("\n", $out) . "\n";
    }

    /**
     * robots.txt for the app's own path. Crawlers only read /robots.txt at the host root, so on a
     * prefixed install this file is advisory (it documents intent and is what tools fetch when
     * pointed at the app); the real controls are the noindex headers and meta tags.
     *
     * @param string|null $explorePath root-relative path of the explorer proxy, kept out of crawlers
     * @param string|null $sitemapUrl  absolute URL of the sitemap
     */
    public static function robots(?string $explorePath = null, ?string $sitemapUrl = null): string
    {
        return "User-agent: *\nAllow: /\n" . ($explorePath !== null ? "Disallow: $explorePath\n" : '')
            . ($sitemapUrl !== null ? "\nSitemap: $sitemapUrl\n" : '');
    }

    private static function md(string $s): string
    {
        return str_replace(['[', ']', "\n"], ['\\[', '\\]', ' '], $s);
    }
}

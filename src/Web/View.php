<?php

declare(strict_types=1);

namespace Datasets\Web;

/**
 * Small, pure view helpers for the HTML templates: human labels for enum values, the
 * copy-able curl command for a dataset's example request, response pretty-printing and
 * JSON-LD. Everything returns plain strings; templates escape with e().
 */
final class View
{
    public const AUTH = ['none' => 'No key', 'api_key' => 'Free API key', 'oauth2' => 'OAuth 2'];

    public const CORS = [
        'any' => 'Any origin (*)',
        'reflect' => 'Reflects origin',
        'restricted' => 'Restricted origins',
        'none' => 'No CORS headers',
        'unknown' => 'Not confirmed',
    ];

    public const COMMERCIAL = ['allowed' => 'Allowed', 'restricted' => 'Restricted', 'prohibited' => 'Prohibited', 'unknown' => 'Unknown'];

    public const PAGINATION = [
        'none' => 'None', 'offset' => 'Offset / limit', 'page' => 'Page number', 'cursor' => 'Cursor',
        'link-header' => 'Link header', 'other' => 'Other', 'unknown' => 'Unknown',
    ];

    public const STYLE = [
        'rest' => 'REST', 'graphql' => 'GraphQL', 'sparql' => 'SPARQL', 'odata' => 'OData',
        'sdmx' => 'SDMX', 'static-file' => 'Static files', 'rpc' => 'RPC / query language',
    ];

    public const SORTS = [
        'name' => 'Name A–Z', '-name' => 'Name Z–A', '-added' => 'Recently added', 'added' => 'Oldest added',
        '-verified' => 'Recently verified', 'verified' => 'Least recently verified',
    ];

    public const KEY_IN = ['query' => 'query parameter', 'header' => 'header', 'path' => 'URL path'];

    /** Glyph paths for the format badges, drawn in an 18x18 box. 'd' is stroked, 'dots' are filled circles [cx, cy, r]. */
    private const FORMAT_GLYPHS = [
        'json' => ['d' => 'M7.5 4.5c-1.2 0-1.5.6-1.5 1.5v1.5c0 .8-.4 1.2-1.2 1.5.8.3 1.2.7 1.2 1.5V12c0 .9.3 1.5 1.5 1.5M10.5 4.5c1.2 0 1.5.6 1.5 1.5v1.5c0 .8.4 1.2 1.2 1.5-.8.3-1.2.7-1.2 1.5V12c0 .9-.3 1.5-1.5 1.5'],
        'geojson' => ['d' => 'M9 14.2s3.6-3.4 3.6-6.1a3.6 3.6 0 0 0-7.2 0c0 2.7 3.6 6.1 3.6 6.1z', 'dots' => [[9, 8, 1.1]]],
        'json-ld' => ['d' => 'M9 5.5 5.5 12h7z', 'dots' => [[9, 5.5, 1.6], [5.5, 12, 1.6], [12.5, 12, 1.6]]],
        'csv' => ['d' => 'M4.5 5h9v8h-9zM4.5 9h9M9 5v8'],
        'xml' => ['d' => 'M6.5 6 3.5 9l3 3M11.5 6l3 3-3 3M10 5 8 13'],
        'html' => ['d' => 'M7.5 5.5 4 9l3.5 3.5M10.5 5.5 14 9l-3.5 3.5'],
        'rss' => ['d' => 'M4.8 8.6a4.6 4.6 0 0 1 4.6 4.6M4.8 5a8.2 8.2 0 0 1 8.2 8.2', 'dots' => [[5.6, 12.4, 1.1]]],
        'text' => ['d' => 'M5 6h8M5 9h8M5 12h5'],
        'yaml' => ['d' => 'M5 6h1.5M8 6h5M5 9h1.5M8 9h5M5 12h1.5M8 12h3'],
        'protobuf' => ['d' => 'M9 4.5l4 2.25v4.5L9 13.5l-4-2.25v-4.5z'],
        'parquet' => ['d' => 'M6 5v8M9 5v8M12 5v8'],
        'image' => ['d' => 'M4.5 5.5h9v7h-9zM5 11.5l2.5-2.5 2 2 1.5-1.5 2 2'],
        'other' => ['dots' => [[5.5, 9, 1.1], [9, 9, 1.1], [12.5, 9, 1.1]]],
    ];

    /**
     * A response format as a small self-contained inline SVG badge: a tinted cap with a glyph, then the
     * format name. Colours come from CSS (.fmt-<format> sets --fmt), so badges follow the theme.
     */
    public static function formatBadge(string $format): string
    {
        $label = self::label('format', $format);
        $glyph = self::FORMAT_GLYPHS[$format] ?? self::FORMAT_GLYPHS['other'];
        $textWidth = round(mb_strlen($label) * 6.3, 1);
        $width = 18 + 6 + $textWidth + 6;
        $class = preg_replace('/[^a-z0-9-]/', '', $format);

        $svg = '<svg class="fmt fmt-' . $class . '" width="' . $width . '" height="18" viewBox="0 0 ' . $width . ' 18" role="img" aria-label="' . e($label) . '">'
            . '<title>' . e($label) . '</title>'
            . '<rect class="fmt-frame" x=".5" y=".5" width="' . ($width - 1) . '" height="17" rx="3"/>'
            . '<path class="fmt-cap" d="M3.5 0H18v18H3.5A3.5 3.5 0 0 1 0 14.5v-11A3.5 3.5 0 0 1 3.5 0z"/>';
        if (isset($glyph['d'])) {
            $svg .= '<path class="fmt-glyph" d="' . $glyph['d'] . '"/>';
        }
        foreach ($glyph['dots'] ?? [] as [$cx, $cy, $r]) {
            $svg .= '<circle class="fmt-dot" cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '"/>';
        }

        return $svg . '<text class="fmt-text" x="24" y="12.6" textLength="' . $textWidth . '" lengthAdjust="spacingAndGlyphs">'
            . e(mb_strtoupper($label)) . '</text></svg>';
    }

    /** Badges for a list of formats, wrapped for layout. */
    public static function formatBadges(array $formats, ?int $max = null): string
    {
        $shown = $max === null ? $formats : array_slice($formats, 0, $max);
        $html = '<span class="fmts">' . implode('', array_map(self::formatBadge(...), $shown));
        if (count($formats) > count($shown)) {
            $rest = array_slice($formats, count($shown));
            $html .= '<span class="fmt-more" title="' . e(implode(', ', array_map(static fn ($f) => self::label('format', $f), $rest))) . '">+' . count($rest) . '</span>';
        }

        return $html . '</span>';
    }

    /** Label for an enum value of a facet, falling back to a readable form of the raw value. */
    public static function label(string $facet, string $value): string
    {
        $map = match ($facet) {
            'auth' => self::AUTH,
            'cors' => self::CORS,
            'commercial_use' => self::COMMERCIAL,
            'pagination' => self::PAGINATION,
            'style' => self::STYLE,
            'sort' => self::SORTS,
            'format' => ['json' => 'JSON', 'geojson' => 'GeoJSON', 'json-ld' => 'JSON-LD', 'csv' => 'CSV', 'xml' => 'XML',
                'rss' => 'RSS', 'text' => 'Text', 'html' => 'HTML', 'yaml' => 'YAML', 'protobuf' => 'Protobuf',
                'parquet' => 'Parquet', 'image' => 'Image', 'other' => 'Other'],
            default => [],
        };

        return $map[$value] ?? ucfirst(str_replace(['_', '-'], ' ', $value));
    }

    /** True when browsers can call the API directly (same rule as the `browser` filter). */
    public static function browserUsable(array $dataset): bool
    {
        return in_array($dataset['api']['cors'] ?? '', ['any', 'reflect'], true);
    }

    /** POSIX-shell single-quoted string. */
    public static function shellQuote(string $s): string
    {
        if ($s !== '' && preg_match('~^[A-Za-z0-9_./:=@%+,-]+$~', $s) === 1) {
            return $s;
        }

        return "'" . str_replace("'", "'\\''", $s) . "'";
    }

    /**
     * The example request as a curl command: method (when not GET), every example header,
     * required headers the example does not already send (with their documented example value),
     * and the body for POST. Lines are joined with " \\\n  " so it pastes into any POSIX shell.
     *
     * @param array<string, mixed> $dataset
     */
    public static function curl(array $dataset): string
    {
        $ex = $dataset['example'];
        $parts = ['curl'];
        $method = (string) ($ex['method'] ?? 'GET');
        if ($method !== 'GET') {
            $parts[0] .= ' -X ' . $method;
        }
        $headers = [];
        foreach ((array) ($ex['headers'] ?? []) as $name => $value) {
            $headers[strtolower((string) $name)] = [(string) $name, (string) $value];
        }
        foreach ((array) ($dataset['api']['required_headers'] ?? []) as $h) {
            $key = strtolower((string) $h['name']);
            if (!isset($headers[$key]) && isset($h['example'])) {
                $headers[$key] = [(string) $h['name'], (string) $h['example']];
            }
        }
        $lines = [$parts[0] . ' ' . self::shellQuote((string) $ex['url'])];
        foreach ($headers as [$name, $value]) {
            $lines[] = '-H ' . self::shellQuote($name . ': ' . $value);
        }
        if (isset($ex['body']) && $ex['body'] !== '') {
            $lines[] = '--data ' . self::shellQuote((string) $ex['body']);
        }

        return implode(" \\\n  ", $lines);
    }

    /**
     * Response excerpt for display: pretty-printed (2 spaces) when it parses as JSON,
     * otherwise as stored.
     *
     * @return array{text: string, json: bool}
     */
    public static function excerpt(string $excerpt): array
    {
        $decoded = json_decode($excerpt);
        if ($decoded === null && trim($excerpt) !== 'null') {
            return ['text' => $excerpt, 'json' => false];
        }
        $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($pretty === false) {
            return ['text' => $excerpt, 'json' => false];
        }

        return ['text' => preg_replace_callback('/^( {4})+/m', static fn ($m) => str_repeat('  ', intdiv(strlen($m[0]), 4)), $pretty) ?? $pretty, 'json' => true];
    }

    /** Endpoint path for display: relative paths are shown as is, absolute URLs as given. */
    public static function endpointUrl(array $dataset, string $path): string
    {
        return str_starts_with($path, 'https://') ? $path : rtrim((string) $dataset['api']['base_url'], '/') . $path;
    }

    /** Readable rate limit budget, e.g. "600 / minute per IP", or null. */
    public static function rateBudget(array $rate): ?string
    {
        if (!isset($rate['requests'], $rate['per'])) {
            return null;
        }
        $s = number_format((int) $rate['requests']) . ' / ' . $rate['per'];
        if (isset($rate['scope'])) {
            $s .= ' per ' . ($rate['scope'] === 'ip' ? 'IP' : $rate['scope']);
        }

        return $s;
    }

    /** Host of a URL without "www.", for compact link lists. */
    public static function host(string $url): string
    {
        $h = (string) parse_url($url, PHP_URL_HOST);

        return str_starts_with($h, 'www.') ? substr($h, 4) : $h;
    }

    /**
     * schema.org JSON-LD (@graph of a Dataset and the WebAPI serving it).
     *
     * @param array<string, mixed> $d
     */
    public static function jsonLd(array $d, string $pageUrl, string $apiUrl): string
    {
        $provider = array_filter([
            '@type' => in_array($d['provider']['type'] ?? '', ['individual'], true) ? 'Person' : 'Organization',
            'name' => $d['provider']['name'] ?? null,
            'url' => $d['provider']['url'] ?? null,
        ]);
        $license = $d['data']['license'] ?? [];
        $dataset = array_filter([
            '@type' => 'Dataset',
            '@id' => $pageUrl . '#dataset',
            'name' => $d['name'],
            'description' => $d['description'],
            'url' => $pageUrl,
            'sameAs' => $d['links']['homepage'] ?? null,
            'keywords' => $d['tags'] ?? [],
            'creator' => $provider,
            'license' => $license['url'] ?? (isset($license['spdx']) ? 'https://spdx.org/licenses/' . $license['spdx'] . '.html' : ($license['name'] ?? null)),
            'isAccessibleForFree' => true,
            'spatialCoverage' => $d['data']['coverage']['geographic'] ?? null,
            'temporalCoverage' => $d['data']['coverage']['temporal'] ?? null,
            'dateModified' => $d['verification']['last_verified'] ?? null,
            'distribution' => isset($d['links']['bulk_download'])
                ? ['@type' => 'DataDownload', 'contentUrl' => $d['links']['bulk_download']]
                : null,
            'subjectOf' => ['@type' => 'DataFeed', 'url' => $apiUrl, 'encodingFormat' => 'application/json', 'name' => 'Catalogue record'],
        ], static fn ($v) => $v !== null && $v !== []);
        $api = array_filter([
            '@type' => 'WebAPI',
            '@id' => $pageUrl . '#api',
            'name' => $d['name'],
            'description' => $d['summary'],
            'url' => $d['api']['base_url'],
            'documentation' => $d['links']['docs'] ?? null,
            'termsOfService' => $d['links']['terms'] ?? null,
            'provider' => $provider,
            'isAccessibleForFree' => true,
            'about' => ['@id' => $pageUrl . '#dataset'],
        ], static fn ($v) => $v !== null && $v !== []);

        $json = json_encode(['@context' => 'https://schema.org', '@graph' => [$dataset, $api]],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PRETTY_PRINT);

        return $json === false ? '{}' : $json;
    }

    /**
     * Flatten a JSON Schema's properties into rows for documentation:
     * [path, type, required, description, enum].
     *
     * @param array<string, mixed> $schema
     * @return list<array{path: string, type: string, required: bool, description: string, enum: list<string>}>
     */
    public static function schemaFields(array $schema, string $prefix = ''): array
    {
        $rows = [];
        $required = (array) ($schema['required'] ?? []);
        foreach ((array) ($schema['properties'] ?? []) as $name => $node) {
            $path = $prefix . $name;
            $type = $node['type'] ?? (isset($node['enum']) ? 'enum' : '');
            $items = $node['items'] ?? null;
            $enum = $node['enum'] ?? ($items['enum'] ?? []);
            if ($type === 'array') {
                $itemType = $items['type'] ?? (isset($items['enum']) ? 'enum' : 'any');
                $type = 'array of ' . ($itemType === 'object' ? 'objects' : $itemType . 's');
            }
            if (isset($node['format'])) {
                $type .= ' (' . $node['format'] . ')';
            }
            $rows[] = [
                'path' => $path,
                'type' => (string) $type,
                'required' => in_array($name, $required, true),
                'description' => (string) ($node['description'] ?? ''),
                'enum' => array_map('strval', (array) $enum),
            ];
            if (($node['type'] ?? '') === 'object' && isset($node['properties'])) {
                array_push($rows, ...self::schemaFields($node, $path . '.'));
            } elseif (is_array($items) && ($items['type'] ?? '') === 'object' && isset($items['properties'])) {
                array_push($rows, ...self::schemaFields($items, $path . '[].'));
            }
        }

        return $rows;
    }
}

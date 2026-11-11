<?php

declare(strict_types=1);

namespace Datasets\Mcp;

use Datasets\Api\ApiController;
use Datasets\Api\ListParams;
use Datasets\Catalog;

/**
 * The MCP server's three read-only tools. They read the in-memory Catalog (no upstream fetches)
 * and validate filters with Api\ListParams, the same code as GET /api/v1/datasets, so the two
 * cannot disagree about what a filter means.
 */
final class Tools
{
    public const MAX_LIMIT = 50;

    public const DEFAULT_LIMIT = 10;

    public function __construct(private readonly Catalog $catalog, private readonly ApiController $api)
    {
    }

    /** @return list<array<string, mixed>> tool definitions for tools/list */
    public function definitions(): array
    {
        $ro = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
        $bool = static fn (string $d): array => ['type' => 'boolean', 'description' => $d];

        return [
            [
                'name' => 'search_datasets',
                'title' => 'Search free APIs',
                'description' => 'Search the catalogue of free datasets with free REST APIs. Every query term must match. '
                    . 'Returns slug, name, summary, category, auth, CORS and status per match; call get_dataset for details.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'q' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Full-text terms, e.g. "earthquake" or "air quality"'],
                        'category' => ['type' => 'string', 'description' => 'Category id (comma = any of); see list_categories'],
                        'tag' => ['type' => 'string', 'description' => 'Tag, kebab-case (comma = all of)'],
                        'keyless' => $bool('Only APIs that need no key'),
                        'browser' => $bool('Only APIs a web page can call (CORS)'),
                        'has_openapi' => $bool('Only APIs with a published OpenAPI document'),
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT],
                        'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                    ],
                    'additionalProperties' => false,
                ],
                'annotations' => $ro,
            ],
            [
                'name' => 'get_dataset',
                'title' => 'Get one API record',
                'description' => 'Full record for one slug: base URL, auth and key signup, required headers, CORS, rate limit, '
                    . 'endpoints, a verified example request with response excerpt, licence, agent_notes and caveats.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['slug' => ['type' => 'string', 'pattern' => '^[a-z0-9]+(-[a-z0-9]+)*$', 'description' => 'From search_datasets']],
                    'required' => ['slug'],
                    'additionalProperties' => false,
                ],
                'annotations' => $ro,
            ],
            [
                'name' => 'list_categories',
                'title' => 'List categories',
                'description' => 'Category ids, names, descriptions and dataset counts, for the category filter of search_datasets.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
                'annotations' => $ro,
            ],
        ];
    }

    public function has(string $name): bool
    {
        return in_array($name, array_column($this->definitions(), 'name'), true);
    }

    /**
     * Run a tool. Bad arguments are tool errors (isError: true) so the model can correct itself.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed> CallToolResult
     */
    public function call(string $name, array $args): array
    {
        try {
            $data = match ($name) {
                'search_datasets' => $this->search($args),
                'get_dataset' => $this->getDataset($args),
                'list_categories' => $this->listCategories($args),
                default => throw new \LogicException("unknown tool $name"),
            };
        } catch (ToolError $e) {
            return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
        }

        return [
            'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]],
            'structuredContent' => $data,
            'isError' => false,
        ];
    }

    /** @param array<string, mixed> $args */
    private function search(array $args): array
    {
        $this->onlyKnown($args, ['q', 'category', 'tag', 'keyless', 'browser', 'has_openapi', 'limit', 'offset']);
        $query = [];
        foreach (['q', 'category', 'tag'] as $k) {
            if (array_key_exists($k, $args) && $args[$k] !== null) {
                if (!is_string($args[$k])) {
                    throw new ToolError("$k must be a string");
                }
                $query[$k] = [$args[$k]];
            }
        }
        foreach (['keyless', 'browser', 'has_openapi'] as $k) {
            if (array_key_exists($k, $args) && $args[$k] !== null) {
                if (!is_bool($args[$k])) {
                    throw new ToolError("$k must be true or false");
                }
                $query[$k] = [$args[$k] ? 'true' : 'false'];
            }
        }
        $limit = $this->int($args, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
        $offset = $this->int($args, 'offset', 0, 0, 999_999_999);
        $query['limit'] = [(string) $limit];
        $query['offset'] = [(string) $offset];

        $p = ListParams::parse($query, $this->catalog);
        if ($p->errors !== []) {
            throw new ToolError(implode('; ', array_map(static fn (array $e): string => $e['parameter'] . ': ' . $e['detail'], $p->errors)));
        }
        $page = $this->catalog->query($p->criteria, $p->sort, $p->limit, $p->offset);

        return [
            'total' => $page->total,
            'offset' => $page->offset,
            'items' => array_map(fn (array $d): array => [
                'slug' => $d['slug'],
                'name' => $d['name'],
                'summary' => $d['summary'],
                'category' => $d['category'],
                'auth' => $d['api']['auth']['type'] ?? null,
                'cors' => $d['api']['cors'] ?? null,
                'status' => $d['status'] ?? null,
                'html_url' => $this->api->htmlUrl((string) $d['slug']),
                'api_url' => $this->api->datasetUrl((string) $d['slug']),
            ], $page->items),
        ];
    }

    /** @param array<string, mixed> $args */
    private function getDataset(array $args): array
    {
        $this->onlyKnown($args, ['slug']);
        $slug = $args['slug'] ?? null;
        if (!is_string($slug) || $slug === '') {
            throw new ToolError('slug is required (a string from search_datasets)');
        }
        $d = $this->catalog->get($slug);
        if ($d === null) {
            throw new ToolError(sprintf('No dataset with slug "%s". Use search_datasets to find slugs.', $slug));
        }

        return $this->api->present($d);
    }

    /** @param array<string, mixed> $args */
    private function listCategories(array $args): array
    {
        $this->onlyKnown($args, []);

        return ['categories' => array_map(static fn (array $c): array => [
            'id' => $c['id'], 'name' => $c['name'], 'description' => $c['description'], 'count' => $c['count'],
        ], array_values(array_filter($this->catalog->categories(), static fn (array $c): bool => $c['count'] > 0)))];
    }

    /**
     * @param array<string, mixed> $args
     * @param list<string> $known
     */
    private function onlyKnown(array $args, array $known): void
    {
        $unknown = array_diff(array_map('strval', array_keys($args)), $known);
        if ($unknown !== []) {
            throw new ToolError('unknown argument' . (count($unknown) > 1 ? 's ' : ' ') . implode(', ', $unknown)
                . ($known === [] ? '; this tool takes none' : '; allowed: ' . implode(', ', $known)));
        }
    }

    /** @param array<string, mixed> $args */
    private function int(array $args, string $key, int $default, int $min, int $max): int
    {
        $v = $args[$key] ?? null;
        if ($v === null) {
            return $default;
        }
        if (is_float($v) && floor($v) === $v) {
            $v = (int) $v;
        }
        if (!is_int($v) || $v < $min || $v > $max) {
            throw new ToolError(sprintf('%s must be an integer between %d and %d', $key, $min, $max));
        }

        return $v;
    }
}

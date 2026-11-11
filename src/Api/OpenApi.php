<?php

declare(strict_types=1);

namespace Datasets\Api;

use Datasets\Catalog;
use Datasets\Url;

/**
 * Builds the OpenAPI 3.1.0 description of /api/v1. Enums come from the dataset schema and
 * categories.json (via Catalog::enums()) so the document cannot drift from the data model.
 */
final class OpenApi
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly Url $url,
        private readonly ApiController $api,
    ) {
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        $enums = $this->catalog->enums();
        $server = $this->url->publicUrl;

        return [
            'openapi' => '3.1.0',
            'jsonSchemaDialect' => 'https://json-schema.org/draft/2020-12/schema',
            'info' => [
                'title' => ApiController::NAME,
                'version' => '1.0.0',
                'summary' => 'Discover free datasets with free, open REST APIs.',
                'description' => ApiController::DESCRIPTION . "\n\n"
                    . "All endpoints are read-only (GET, HEAD, OPTIONS), need no authentication and send "
                    . "`Access-Control-Allow-Origin: *`. Responses carry a strong `ETag`; send `If-None-Match` "
                    . "to get `304 Not Modified`. Errors use RFC 9457 `application/problem+json`. "
                    . "List responses are paginated with `limit`/`offset` and include RFC 8288 `Link` headers.",
                'license' => ['name' => 'CC0 1.0', 'identifier' => 'CC0-1.0'],
                'contact' => ['name' => 'Endtime Instruments', 'url' => $this->url->absolute('/about')],
            ],
            'servers' => [['url' => $server, 'description' => 'This deployment']],
            'security' => [],
            'externalDocs' => ['description' => 'Human-readable documentation', 'url' => $this->url->absolute('/api')],
            'tags' => [
                ['name' => 'Meta', 'description' => 'API root, schema and this document.'],
                ['name' => 'Datasets', 'description' => 'The catalogue entries.'],
                ['name' => 'Categories', 'description' => 'Topical categories; every dataset has exactly one.'],
                ['name' => 'Tags', 'description' => 'Free-form topic tags.'],
                ['name' => 'Facets', 'description' => 'Value counts for building filters.'],
            ],
            'paths' => $this->paths(),
            'components' => [
                'schemas' => $this->schemas($enums),
                'parameters' => $this->parameters($enums),
                'headers' => $this->headers(),
                'responses' => $this->responses(),
            ],
        ];
    }

    // ---------------------------------------------------------------- paths

    /** @return array<string, mixed> */
    private function paths(): array
    {
        $filters = array_map(static fn (string $p) => ['$ref' => '#/components/parameters/' . $p], ListParams::FILTERS);
        $paging = array_map(static fn (string $p) => ['$ref' => '#/components/parameters/' . $p], ListParams::PAGING);
        $inm = ['$ref' => '#/components/parameters/If-None-Match'];

        $example = $this->catalog->all()[0] ?? null;
        $datasetExample = $example === null ? [] : ['examples' => [(string) $example['slug'] => [
            'summary' => (string) $example['name'],
            'value' => json_decode($this->api->getDataset((string) $example['slug'])->body),
        ]]];

        return [
            '/api/v1' => ['get' => $this->op('getApiRoot', 'Meta', 'API root', 'Name, description, counts and links to every resource.', [$inm], 'ApiRoot', false, false, [
                'example' => json_decode($this->api->root()->body),
            ])],
            '/api/v1/datasets' => ['get' => $this->op(
                'listDatasets',
                'Datasets',
                'List and search datasets',
                "Filter, search, sort and paginate the catalogue. Repeating a parameter or separating values "
                    . "with commas means OR, except for `tag`, where every listed tag must be present (AND). "
                    . "Unknown parameters and invalid values are rejected with 400.",
                array_merge($filters, $paging, [$inm]),
                'DatasetList',
                true,
                false,
                [],
                true,
            )],
            '/api/v1/datasets/{slug}' => ['get' => $this->op('getDataset', 'Datasets', 'Get one dataset', 'The full catalogue entry, with computed `url` and `html_url`.', [['$ref' => '#/components/parameters/slug'], $inm], 'DatasetDocument', false, true, $datasetExample)],
            '/api/v1/categories' => ['get' => $this->op('listCategories', 'Categories', 'List categories', 'All categories with dataset counts, in display order.', [$inm], 'CategoryList')],
            '/api/v1/categories/{id}' => ['get' => $this->op('getCategory', 'Categories', 'Get one category', 'A category with its dataset count and a link to its datasets.', [['$ref' => '#/components/parameters/categoryId'], $inm], 'CategoryDocument', false, true)],
            '/api/v1/tags' => ['get' => $this->op('listTags', 'Tags', 'List tags', 'Every tag in use, by dataset count descending.', [$inm], 'TagList')],
            '/api/v1/facets' => ['get' => $this->op('getFacets', 'Facets', 'Facet counts', 'Counts per value of category, auth, cors, format, style, provider_type, commercial_use and status, over the datasets matching the (optional) filters. Every allowed value is listed, zeros included.', array_merge($filters, [$inm]), 'FacetsDocument', true)],
            '/api/v1/schema' => ['get' => $this->op('getSchema', 'Meta', 'Dataset JSON Schema', 'The JSON Schema (2020-12) every catalogue entry validates against.', [$inm], 'JsonSchema', false, false, [], false, 'application/schema+json')],
            '/api/v1/openapi.json' => ['get' => $this->op('getOpenApi', 'Meta', 'OpenAPI document', 'This OpenAPI 3.1 description.', [$inm], 'OpenApiDocument')],
        ];
    }

    /**
     * @param list<array<string, mixed>> $params
     * @param array<string, mixed> $mediaExtra examples for the 200 media type
     * @return array<string, mixed>
     */
    private function op(
        string $id,
        string $tag,
        string $summary,
        string $description,
        array $params,
        string $schema,
        bool $badRequest = false,
        bool $notFound = false,
        array $mediaExtra = [],
        bool $linkHeader = false,
        string $mediaType = 'application/json',
    ): array {
        $headers = [
            'ETag' => ['$ref' => '#/components/headers/ETag'],
            'Cache-Control' => ['$ref' => '#/components/headers/Cache-Control'],
            'Access-Control-Allow-Origin' => ['$ref' => '#/components/headers/Access-Control-Allow-Origin'],
        ];
        if ($linkHeader) {
            $headers['Link'] = ['$ref' => '#/components/headers/Link'];
        }
        $responses = [
            '200' => [
                'description' => 'OK',
                'headers' => $headers,
                'content' => [$mediaType => ['schema' => ['$ref' => '#/components/schemas/' . $schema]] + $mediaExtra],
            ],
            '304' => ['$ref' => '#/components/responses/NotModified'],
        ];
        if ($badRequest) {
            $responses['400'] = ['$ref' => '#/components/responses/BadRequest'];
        }
        if ($notFound) {
            $responses['404'] = ['$ref' => '#/components/responses/NotFound'];
        }
        $responses['405'] = ['$ref' => '#/components/responses/MethodNotAllowed'];

        return [
            'operationId' => $id,
            'tags' => [$tag],
            'summary' => $summary,
            'description' => $description,
            'parameters' => $params,
            'responses' => $responses,
        ];
    }

    // ---------------------------------------------------------------- parameters

    /**
     * @param array<string, list<string>> $enums
     * @return array<string, mixed>
     */
    private function parameters(array $enums): array
    {
        $enumList = static fn (string $name, string $desc, array $values, string $example) => [
            'name' => $name,
            'in' => 'query',
            'description' => $desc . ' Comma-separated or repeated values are ORed.',
            'style' => 'form',
            'explode' => false,
            'schema' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $values], 'uniqueItems' => true],
            'example' => [$example],
        ];
        $bool = static fn (string $name, string $desc) => [
            'name' => $name,
            'in' => 'query',
            'description' => $desc,
            'schema' => ['type' => 'boolean'],
            'example' => true,
        ];
        $first = static fn (array $values): string => (string) ($values[0] ?? '');
        $fields = array_merge(array_keys($this->catalog->schema()['properties'] ?? []), ['url', 'html_url']);

        return [
            'q' => [
                'name' => 'q', 'in' => 'query',
                'description' => 'Case-insensitive full-text search over name, provider, summary, description, tags and endpoint summaries. Every whitespace-separated term must match.',
                'schema' => ['type' => 'string', 'maxLength' => 200], 'example' => 'earthquake',
            ],
            'category' => $enumList('category', 'Category id (see /categories).', $enums['category'], $first($enums['category'])),
            'tag' => [
                'name' => 'tag', 'in' => 'query',
                'description' => 'Tag(s), comma-separated or repeated. Every listed tag must be present (AND).',
                'style' => 'form', 'explode' => false,
                'schema' => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^[a-z0-9]+(-[a-z0-9]+)*$']],
                'example' => ['weather'],
            ],
            'auth' => $enumList('auth', 'Authentication needed for ordinary use (api.auth.type).', $enums['auth'], 'none'),
            'cors' => $enumList('cors', 'Browser CORS behaviour (api.cors).', $enums['cors'], 'any'),
            'format' => $enumList('format', 'Response format offered (api.formats); matches datasets offering any of them.', $enums['format'], 'json'),
            'style' => $enumList('style', 'Dominant query style (api.style).', $enums['style'], 'rest'),
            'provider_type' => $enumList('provider_type', 'Who runs the API (provider.type).', $enums['provider_type'], 'government'),
            'commercial_use' => $enumList('commercial_use', 'Whether the data licence allows commercial use (data.license.commercial_use).', $enums['commercial_use'], 'allowed'),
            'status' => $enumList('status', 'Lifecycle status.', $enums['status'], 'active'),
            'keyless' => $bool('keyless', '`true`: only APIs usable without any credential (auth = none). `false`: only APIs that need one.'),
            'browser' => $bool('browser', '`true`: only APIs callable from a browser (cors = any or reflect). `false`: the others.'),
            'has_openapi' => $bool('has_openapi', 'Whether the API publishes a machine-readable OpenAPI/Swagger document (links.openapi).'),
            'sort' => [
                'name' => 'sort', 'in' => 'query',
                'description' => 'Sort key; prefix with `-` for descending. `verified` = verification.last_verified.',
                'schema' => ['type' => 'string', 'enum' => Catalog::SORTS, 'default' => 'name'],
            ],
            'limit' => [
                'name' => 'limit', 'in' => 'query', 'description' => 'Page size.',
                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ListParams::MAX_LIMIT, 'default' => ListParams::DEFAULT_LIMIT],
            ],
            'offset' => [
                'name' => 'offset', 'in' => 'query', 'description' => 'Number of results to skip.',
                'schema' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
            ],
            'fields' => [
                'name' => 'fields', 'in' => 'query',
                'description' => 'Sparse fieldset: comma-separated top-level fields to return. `slug`, `url` and `html_url` are always included.',
                'style' => 'form', 'explode' => false,
                'schema' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $fields], 'uniqueItems' => true],
                'example' => ['name', 'summary', 'api'],
            ],
            'slug' => [
                'name' => 'slug', 'in' => 'path', 'required' => true, 'description' => 'Dataset slug.',
                'schema' => ['type' => 'string', 'pattern' => '^[a-z0-9]+(-[a-z0-9]+)*$'],
                'example' => $this->catalog->slugs()[0] ?? 'open-meteo',
            ],
            'categoryId' => [
                'name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Category id.',
                'schema' => ['type' => 'string', 'enum' => $enums['category']],
                'example' => $first($enums['category']),
            ],
            'If-None-Match' => [
                'name' => 'If-None-Match', 'in' => 'header', 'required' => false,
                'description' => 'ETag from a previous response; a match returns 304 Not Modified.',
                'schema' => ['type' => 'string'],
            ],
        ];
    }

    // ---------------------------------------------------------------- schemas

    /**
     * @param array<string, list<string>> $enums
     * @return array<string, mixed>
     */
    private function schemas(array $enums): array
    {
        $dataset = $this->catalog->schema();
        unset($dataset['$schema'], $dataset['$id']);

        $computed = [
            'url' => ['description' => 'Computed: this dataset in the API.', 'type' => 'string', 'format' => 'uri'],
            'html_url' => ['description' => 'Computed: this dataset on the website.', 'type' => 'string', 'format' => 'uri'],
        ];
        // Dataset has additionalProperties:false, so allOf cannot extend it; reference its
        // property schemas one by one instead.
        $props = [];
        foreach (array_keys($dataset['properties'] ?? []) as $name) {
            $props[$name] = ['$ref' => '#/components/schemas/Dataset/properties/' . $name];
        }
        $resource = [
            'title' => 'DatasetResource',
            'description' => 'A Dataset as returned by the API: the stored entry plus computed url and html_url.',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array_merge($dataset['required'] ?? [], ['url', 'html_url']),
            'properties' => $props + $computed,
        ];

        $listItem = $resource;
        $listItem['title'] = 'DatasetListItem';
        $listItem['description'] = 'A DatasetResource; with the `fields` parameter only the requested fields (plus slug, url, html_url) are present.';
        $listItem['required'] = ListParams::ALWAYS_FIELDS;

        $uri = ['type' => 'string', 'format' => 'uri'];
        $nullableUri = ['type' => ['string', 'null'], 'format' => 'uri'];
        $counts = static fn (array $values) => [
            'type' => 'object',
            'properties' => array_combine($values, array_fill(0, count($values), ['type' => 'integer', 'minimum' => 0])) ?: new \stdClass(),
            'additionalProperties' => false,
        ];
        $category = [
            'type' => 'object',
            'required' => ['id', 'name', 'description', 'count', 'links'],
            'properties' => [
                'id' => ['type' => 'string', 'enum' => $enums['category']],
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'count' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Number of datasets in this category.'],
                'links' => [
                    'type' => 'object', 'required' => ['self', 'datasets', 'html'],
                    'properties' => ['self' => $uri, 'datasets' => $uri, 'html' => $uri],
                ],
            ],
        ];

        return [
            'Dataset' => $dataset,
            'DatasetResource' => $resource,
            'DatasetListItem' => $listItem,
            'DatasetList' => [
                'type' => 'object',
                'required' => ['data', 'meta', 'links'],
                'properties' => [
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/DatasetListItem']],
                    'meta' => ['$ref' => '#/components/schemas/PageMeta'],
                    'links' => ['$ref' => '#/components/schemas/PageLinks'],
                ],
            ],
            'DatasetDocument' => [
                'type' => 'object',
                'required' => ['data', 'links'],
                'properties' => [
                    'data' => ['$ref' => '#/components/schemas/DatasetResource'],
                    'links' => [
                        'type' => 'object',
                        'required' => ['self', 'html', 'collection'],
                        'properties' => [
                            'self' => $uri, 'html' => $uri, 'collection' => $uri, 'category' => $uri,
                            'related' => ['type' => 'array', 'items' => $uri, 'description' => 'API URLs of related datasets.'],
                        ],
                    ],
                ],
            ],
            'PageMeta' => [
                'type' => 'object',
                'required' => ['total', 'limit', 'offset', 'count'],
                'properties' => [
                    'total' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Matches before pagination.'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ListParams::MAX_LIMIT],
                    'offset' => ['type' => 'integer', 'minimum' => 0],
                    'count' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Items on this page.'],
                ],
            ],
            'PageLinks' => [
                'type' => 'object',
                'required' => ['self', 'first', 'prev', 'next', 'last'],
                'properties' => ['self' => $uri, 'first' => $uri, 'prev' => $nullableUri, 'next' => $nullableUri, 'last' => $uri],
            ],
            'Category' => $category,
            'CategoryList' => [
                'type' => 'object',
                'required' => ['data', 'meta', 'links'],
                'properties' => [
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Category']],
                    'meta' => ['type' => 'object', 'properties' => ['total' => ['type' => 'integer']]],
                    'links' => ['type' => 'object', 'properties' => ['self' => $uri]],
                ],
            ],
            'CategoryDocument' => [
                'type' => 'object',
                'required' => ['data', 'links'],
                'properties' => [
                    'data' => ['$ref' => '#/components/schemas/Category'],
                    'links' => ['type' => 'object', 'properties' => ['self' => $uri, 'datasets' => $uri, 'collection' => $uri]],
                ],
            ],
            'Tag' => [
                'type' => 'object',
                'required' => ['tag', 'count', 'links'],
                'properties' => [
                    'tag' => ['type' => 'string', 'pattern' => '^[a-z0-9]+(-[a-z0-9]+)*$'],
                    'count' => ['type' => 'integer', 'minimum' => 1],
                    'links' => ['type' => 'object', 'properties' => ['datasets' => $uri]],
                ],
            ],
            'TagList' => [
                'type' => 'object',
                'required' => ['data', 'meta', 'links'],
                'properties' => [
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']],
                    'meta' => ['type' => 'object', 'properties' => ['total' => ['type' => 'integer']]],
                    'links' => ['type' => 'object', 'properties' => ['self' => $uri]],
                ],
            ],
            'FacetsDocument' => [
                'type' => 'object',
                'required' => ['data', 'meta', 'links'],
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => $counts($enums['category']),
                            'auth' => $counts($enums['auth']),
                            'cors' => $counts($enums['cors']),
                            'format' => $counts($enums['format']),
                            'style' => $counts($enums['style']),
                            'provider_type' => $counts($enums['provider_type']),
                            'commercial_use' => $counts($enums['commercial_use']),
                            'status' => $counts($enums['status']),
                        ],
                    ],
                    'meta' => ['type' => 'object', 'properties' => ['total' => ['type' => 'integer', 'description' => 'Datasets matching the filters.']]],
                    'links' => ['type' => 'object', 'properties' => ['self' => $uri, 'datasets' => $uri, 'tags' => $uri]],
                ],
            ],
            'ApiRoot' => [
                'type' => 'object',
                'required' => ['name', 'description', 'version', 'counts', 'links'],
                'properties' => [
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'version' => ['type' => 'string'],
                    'counts' => [
                        'type' => 'object',
                        'properties' => ['datasets' => ['type' => 'integer'], 'categories' => ['type' => 'integer'], 'tags' => ['type' => 'integer']],
                    ],
                    'links' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                ],
            ],
            'JsonSchema' => ['type' => 'object', 'description' => 'A JSON Schema 2020-12 document (the Dataset schema).'],
            'OpenApiDocument' => ['type' => 'object', 'description' => 'An OpenAPI 3.1 document.', 'required' => ['openapi', 'info', 'paths']],
            'Problem' => [
                'type' => 'object',
                'description' => 'RFC 9457 problem details.',
                'required' => ['type', 'title', 'status', 'detail', 'instance'],
                'properties' => [
                    'type' => ['type' => 'string', 'format' => 'uri-reference', 'example' => 'about:blank'],
                    'title' => ['type' => 'string', 'example' => 'Not Found'],
                    'status' => ['type' => 'integer', 'minimum' => 400, 'maximum' => 599],
                    'detail' => ['type' => 'string'],
                    'instance' => ['type' => 'string', 'format' => 'uri-reference'],
                    'errors' => [
                        'type' => 'array',
                        'description' => 'Present on 400: one entry per invalid query parameter.',
                        'items' => [
                            'type' => 'object',
                            'required' => ['parameter', 'detail'],
                            'properties' => ['parameter' => ['type' => 'string'], 'detail' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
        ];
    }

    // ---------------------------------------------------------------- headers / responses

    /** @return array<string, mixed> */
    private function headers(): array
    {
        return [
            'ETag' => ['description' => 'Strong validator of the representation.', 'schema' => ['type' => 'string']],
            'Cache-Control' => ['description' => 'Caching policy.', 'schema' => ['type' => 'string', 'example' => 'public, max-age=300']],
            'Access-Control-Allow-Origin' => ['description' => 'Always `*`.', 'schema' => ['type' => 'string', 'const' => '*']],
            'Link' => ['description' => 'RFC 8288 pagination links (self, first, prev, next, last).', 'schema' => ['type' => 'string']],
            'Allow' => ['description' => 'Methods allowed on the resource.', 'schema' => ['type' => 'string', 'example' => 'GET, HEAD, OPTIONS']],
        ];
    }

    /** @return array<string, mixed> */
    private function responses(): array
    {
        $problem = static fn (string $desc, array $example, array $headers = []) => array_filter([
            'description' => $desc,
            'headers' => $headers,
            'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem'], 'example' => $example]],
        ]);

        return [
            'NotModified' => ['description' => 'Not Modified: the If-None-Match ETag matches.', 'headers' => ['ETag' => ['$ref' => '#/components/headers/ETag']]],
            'BadRequest' => $problem('Invalid query parameters.', [
                'type' => 'about:blank', 'title' => 'Invalid query parameters', 'status' => 400,
                'detail' => 'Invalid query parameter "limit": must be an integer between 1 and 200',
                'instance' => $this->url->path('/api/v1/datasets') . '?limit=500',
                'errors' => [['parameter' => 'limit', 'detail' => 'must be an integer between 1 and 200']],
            ]),
            'NotFound' => $problem('No such resource.', [
                'type' => 'about:blank', 'title' => 'Not Found', 'status' => 404,
                'detail' => 'No dataset with slug "nope".', 'instance' => $this->url->path('/api/v1/datasets/nope'),
            ]),
            'MethodNotAllowed' => $problem('Only GET, HEAD and OPTIONS are supported.', [
                'type' => 'about:blank', 'title' => 'Method Not Allowed', 'status' => 405,
                'detail' => 'POST is not allowed. Allowed: GET, HEAD, OPTIONS', 'instance' => $this->url->path('/api/v1/datasets'),
            ], ['Allow' => ['$ref' => '#/components/headers/Allow']]),
        ];
    }
}

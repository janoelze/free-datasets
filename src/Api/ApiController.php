<?php

declare(strict_types=1);

namespace Datasets\Api;

use Datasets\Catalog;
use Datasets\Http\Http;
use Datasets\Http\Request;
use Datasets\Http\Response;
use Datasets\Json;
use Datasets\ResultPage;
use Datasets\Url;

/** Handlers for /api/v1/*. Each returns a Response; App adds the common headers. */
final class ApiController
{
    public const NAME = 'Free Datasets API Library';

    public const DESCRIPTION = 'A curated catalogue of free datasets that have a free, open REST API, '
        . 'for AI agents and developers who need to discover free APIs and understand what they can do. '
        . 'This catalogue itself is a free, open, read-only REST API: no key, CORS enabled.';

    private Url $url;

    public function __construct(private readonly Catalog $catalog, private readonly Request $request)
    {
        $this->url = $request->url();
    }

    // ---------------------------------------------------------------- URL builders

    public function api(string $path = '', array $query = []): string
    {
        return $this->url->absolute('/api/v1' . $path, $query);
    }

    public function datasetUrl(string $slug): string
    {
        return $this->api('/datasets/' . rawurlencode($slug));
    }

    public function htmlUrl(string $slug): string
    {
        return $this->url->absolute('/d/' . rawurlencode($slug));
    }

    // ---------------------------------------------------------------- handlers

    public function root(): Response
    {
        return Http::json([
            'name' => self::NAME,
            'description' => self::DESCRIPTION,
            'version' => 'v1',
            'counts' => [
                'datasets' => $this->catalog->count(),
                'categories' => count($this->catalog->categoryIds()),
                'tags' => count($this->catalog->facets()['tag']),
            ],
            'links' => [
                'self' => $this->api(),
                'datasets' => $this->api('/datasets'),
                'dataset' => $this->api('/datasets/{slug}'),
                'categories' => $this->api('/categories'),
                'category' => $this->api('/categories/{id}'),
                'tags' => $this->api('/tags'),
                'facets' => $this->api('/facets'),
                'schema' => $this->api('/schema'),
                'openapi' => $this->api('/openapi.json'),
                'html' => $this->url->absolute('/'),
                'docs' => $this->url->absolute('/api'),
                'llms_txt' => $this->url->absolute('/llms.txt'),
            ],
        ]);
    }

    public function listDatasets(): Response
    {
        $p = ListParams::parse($this->request->query, $this->catalog);
        if ($p->errors !== []) {
            return $this->invalidParams($p->errors);
        }
        $page = $this->catalog->query($p->criteria, $p->sort, $p->limit, $p->offset);
        $links = $this->pageLinks($page, $p);

        $r = Http::json([
            'data' => array_map(fn (array $d) => $this->present($d, $p->fields), $page->items),
            'meta' => [
                'total' => $page->total,
                'limit' => $page->limit,
                'offset' => $page->offset,
                'count' => $page->count(),
            ],
            'links' => $links,
        ]);
        $link = [];
        foreach ($links as $rel => $href) {
            if ($href !== null) {
                $link[] = '<' . $href . '>; rel="' . $rel . '"';
            }
        }

        return $r->withHeader('Link', implode(', ', $link));
    }

    public function getDataset(string $slug): Response
    {
        $d = $this->catalog->get($slug);
        if ($d === null) {
            return $this->notFound(sprintf('No dataset with slug "%s". List all datasets at %s', $slug, $this->api('/datasets')));
        }

        return Http::json([
            'data' => $this->present($d),
            'links' => [
                'self' => $this->datasetUrl($slug),
                'html' => $this->htmlUrl($slug),
                'collection' => $this->api('/datasets'),
                'category' => $this->api('/categories/' . rawurlencode((string) $d['category'])),
                'related' => array_map(fn (array $r) => $this->datasetUrl((string) $r['slug']), $this->catalog->related($slug)),
            ],
        ]);
    }

    public function categories(): Response
    {
        return Http::json([
            'data' => array_map(fn (array $c) => $this->presentCategory($c), $this->catalog->categories()),
            'meta' => ['total' => count($this->catalog->categoryIds())],
            'links' => ['self' => $this->api('/categories')],
        ]);
    }

    public function category(string $id): Response
    {
        $c = $this->catalog->category($id);
        if ($c === null) {
            return $this->notFound(sprintf('No category "%s". Known categories: %s', $id, implode(', ', $this->catalog->categoryIds())));
        }

        return Http::json([
            'data' => $this->presentCategory($c),
            'links' => [
                'self' => $this->api('/categories/' . rawurlencode($id)),
                'datasets' => $this->api('/datasets', ['category' => $id]),
                'collection' => $this->api('/categories'),
            ],
        ]);
    }

    public function tags(): Response
    {
        $data = [];
        foreach ($this->catalog->tags() as $tag => $count) {
            $data[] = ['tag' => (string) $tag, 'count' => $count, 'links' => ['datasets' => $this->api('/datasets', ['tag' => (string) $tag])]];
        }

        return Http::json([
            'data' => $data,
            'meta' => ['total' => count($data)],
            'links' => ['self' => $this->api('/tags')],
        ]);
    }

    public function facets(): Response
    {
        $p = ListParams::parse($this->request->query, $this->catalog, paging: false);
        if ($p->errors !== []) {
            return $this->invalidParams($p->errors);
        }
        $subset = $this->catalog->filter($p->criteria);
        $facets = $this->catalog->facets($subset);
        unset($facets['tag']);

        return Http::json([
            'data' => array_map(static fn (array $counts) => (object) $counts, $facets),
            'meta' => ['total' => count($subset)],
            'links' => [
                'self' => $this->api('/facets', $p->canonical),
                'datasets' => $this->api('/datasets', $p->canonical),
                'tags' => $this->api('/tags'),
            ],
        ]);
    }

    public function schema(): Response
    {
        $schema = $this->catalog->schema();
        $schema = ['$schema' => $schema['$schema'] ?? 'https://json-schema.org/draft/2020-12/schema', '$id' => $this->api('/schema')] + $schema;

        return Http::json($schema, 200, 'application/schema+json; charset=utf-8');
    }

    public function openapi(): Response
    {
        return Http::json((new OpenApi($this->catalog, $this->url, $this))->document());
    }

    // ---------------------------------------------------------------- presentation

    /**
     * Dataset as returned by the API: the stored object plus computed `url` and `html_url`,
     * optionally reduced to $fields (slug, url and html_url are always kept).
     *
     * @param list<string>|null $fields
     * @return array<string, mixed>
     */
    public function present(array $d, ?array $fields = null): array
    {
        $slug = (string) $d['slug'];
        $d = (array) Json::objectify($d, $this->catalog->schema());
        $d['url'] = $this->datasetUrl($slug);
        $d['html_url'] = $this->htmlUrl($slug);
        if ($fields !== null) {
            $keep = array_flip(array_merge(ListParams::ALWAYS_FIELDS, $fields));
            $d = array_intersect_key($d, $keep);
        }

        return $d;
    }

    /** @return array<string, mixed> */
    private function presentCategory(array $c): array
    {
        return [
            'id' => $c['id'],
            'name' => $c['name'],
            'description' => $c['description'],
            'count' => $c['count'],
            'links' => [
                'self' => $this->api('/categories/' . rawurlencode($c['id'])),
                'datasets' => $this->api('/datasets', ['category' => $c['id']]),
                'html' => $this->url->absolute('/', ['category' => $c['id']]),
            ],
        ];
    }

    /** @return array{self: string, first: string, prev: ?string, next: ?string, last: string} */
    private function pageLinks(ResultPage $page, ListParams $p): array
    {
        $at = function (int $offset) use ($p, $page): string {
            $q = $p->canonical;
            if ($page->limit !== ListParams::DEFAULT_LIMIT) {
                $q['limit'] = (string) $page->limit;
            }
            if ($offset > 0) {
                $q['offset'] = (string) $offset;
            }

            return $this->api('/datasets', $q);
        };

        return [
            'self' => $at($page->offset),
            'first' => $at(0),
            'prev' => $page->prevOffset() === null ? null : $at($page->prevOffset()),
            'next' => $page->nextOffset() === null ? null : $at($page->nextOffset()),
            'last' => $at($page->lastOffset()),
        ];
    }

    // ---------------------------------------------------------------- errors

    /** @param list<array{parameter: string, detail: string}> $errors */
    private function invalidParams(array $errors): Response
    {
        $detail = count($errors) === 1
            ? sprintf('Invalid query parameter "%s": %s', $errors[0]['parameter'], $errors[0]['detail'])
            : sprintf('%d query parameters are invalid.', count($errors));

        return Http::problem(400, $detail, $this->request->target(), ['errors' => $errors], 'Invalid query parameters');
    }

    private function notFound(string $detail): Response
    {
        return Http::problem(404, $detail, $this->request->target());
    }
}

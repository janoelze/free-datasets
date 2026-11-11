<?php

declare(strict_types=1);

namespace Datasets\Web;

use Datasets\Api\ListParams;
use Datasets\Catalog;
use Datasets\Http\Http;
use Datasets\Http\Request;
use Datasets\Http\Response;
use Datasets\Seo\IndexPolicy;
use Datasets\Url;

/**
 * HTML pages. Each renders templates/<name>.php (if it exists) with the variables documented
 * in docs/ARCHITECTURE.md. Every template receives $catalog, $request and $urls.
 */
final class PageController
{
    private Url $urls;

    public function __construct(
        private readonly Catalog $catalog,
        private readonly Request $request,
        private readonly string $templatesDir,
    ) {
        $this->urls = Url::current();
    }

    public function home(): Response
    {
        $params = ListParams::parse($this->request->query, $this->catalog, paging: true, strict: false);
        $matches = $this->catalog->filter($params->criteria);
        // The page shows the whole (filtered) catalogue at once unless ?limit= asks otherwise
        // (the API's MAX_LIMIT does not apply here: the catalogue outgrew it).
        $limit = isset($this->request->query['limit']) ? $params->limit : max(ListParams::MAX_LIMIT, $this->catalog->count());
        $results = $this->catalog->paginate($this->catalog->sort($matches, $params->sort), $limit, $params->offset);

        return $this->render('home', [
            'params' => $params,
            'policy' => IndexPolicy::home($this->request->query, $params, $this->catalog, $this->urls),
            'results' => $results,
            'facets' => $this->catalog->facets($matches),
            'categories' => $this->catalog->categories(),
            'tags' => $this->catalog->tags(),
            'keylessCount' => count($this->catalog->filter(['keyless' => true])),
            'apiQueryUrl' => $this->urls->absolute('/api/v1/datasets', $params->canonical),
        ]);
    }

    public function dataset(string $slug): Response
    {
        $dataset = $this->catalog->get($slug);
        if ($dataset === null) {
            return $this->notFound();
        }

        return $this->render('dataset', [
            'dataset' => $dataset,
            'category' => $this->catalog->category((string) $dataset['category']),
            'related' => $this->catalog->related($slug),
            'apiUrl' => $this->urls->absolute('/api/v1/datasets/' . rawurlencode($slug)),
        ]);
    }

    /** /c/{id}: one category as a crawlable landing page (the ?category= view stays the filter UI). */
    public function category(string $id): Response
    {
        $category = $this->catalog->category($id);
        if ($category === null || $category['count'] === 0) {
            return $this->notFound();
        }
        $datasets = $this->catalog->sort($this->catalog->filter(['category' => [$id]]), 'name');

        return $this->render('landing', [
            'kind' => 'category',
            'landingKey' => $id,
            'category' => $category,
            'datasets' => $datasets,
            'categories' => $this->catalog->categories(),
        ]);
    }

    /** /t/{tag}: tags with at least IndexPolicy::MIN_TAG_DATASETS datasets; thinner tags go to the filter view. */
    public function tag(string $tag): Response
    {
        $count = $this->catalog->tags()[$tag] ?? 0;
        if ($count === 0) {
            return $this->notFound();
        }
        if ($count < IndexPolicy::MIN_TAG_DATASETS) {
            // Temporary: the tag gets a page once it has enough datasets.
            return Http::redirect($this->urls->path('/', ['tag' => $tag]), 302);
        }
        $datasets = $this->catalog->sort($this->catalog->filter(['tag' => [$tag]]), 'name');
        // Tags that co-occur with this one and have pages of their own.
        $landing = IndexPolicy::landingTags($this->catalog);
        $near = array_intersect_key($this->catalog->tags($datasets), $landing);
        unset($near[$tag]);

        return $this->render('landing', [
            'kind' => 'tag',
            'landingKey' => $tag,
            'count' => $count,
            'datasets' => $datasets,
            'categories' => $this->catalog->categories(),
            'nearTags' => array_slice($near, 0, 12, true),
        ]);
    }

    /** /t: every tag that has a landing page. */
    public function tags(): Response
    {
        return $this->render('tags', [
            'tags' => IndexPolicy::landingTags($this->catalog),
            'categories' => $this->catalog->categories(),
        ]);
    }

    public function api(): Response
    {
        return $this->render('api', [
            'apiBase' => $this->urls->absolute('/api/v1'),
            'openapiUrl' => $this->urls->absolute('/api/v1/openapi.json'),
            'schemaUrl' => $this->urls->absolute('/api/v1/schema'),
        ]);
    }

    public function about(): Response
    {
        return $this->render('about', []);
    }

    public function notFound(): Response
    {
        return $this->render('404', ['path' => $this->request->path], 404);
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars, int $status = 200): Response
    {
        $file = $this->templatesDir . '/' . $template . '.php';
        $vars += ['catalog' => $this->catalog, 'request' => $this->request, 'urls' => $this->urls];
        if (!is_file($file)) {
            return Http::text($status === 404 ? "Not Found\n" : "Template $template.php is missing\n", $status);
        }
        $html = (static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                include $__file;

                return (string) ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        })($file, $vars);

        return Http::html($html, $status);
    }
}

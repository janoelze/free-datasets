<?php

declare(strict_types=1);

namespace Datasets\Web;

use Datasets\Api\ListParams;
use Datasets\Catalog;
use Datasets\Http\Http;
use Datasets\Http\Request;
use Datasets\Http\Response;
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
        $results = $this->catalog->paginate($this->catalog->sort($matches, $params->sort), $params->limit, $params->offset);

        return $this->render('home', [
            'params' => $params,
            'results' => $results,
            'facets' => $this->catalog->facets($matches),
            'categories' => $this->catalog->categories(),
            'tags' => $this->catalog->tags(),
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

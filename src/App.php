<?php

declare(strict_types=1);

namespace Datasets;

use Datasets\Api\ApiController;
use Datasets\Api\LlmsTxt;
use Datasets\Explore\ExploreController;
use Datasets\Http\Http;
use Datasets\Http\Request;
use Datasets\Http\Response;
use Datasets\Http\Router;
use Datasets\Web\PageController;

/** Application kernel: routing, dispatch and the cross-cutting response headers. */
final class App
{
    private ?Catalog $catalog = null;

    public function __construct(public readonly Config $config)
    {
    }

    public static function fromGlobals(): self
    {
        return new self(Config::fromEnvironment());
    }

    /** Lazily loaded catalogue (also used by templates via $catalog). */
    public function catalog(): Catalog
    {
        return $this->catalog ??= Catalog::fromConfig($this->config);
    }

    public function run(): void
    {
        $request = Request::fromGlobals();
        $this->handle($request)->send($request->method === 'HEAD');
    }

    public function handle(Request $req): Response
    {
        Url::setCurrent(new Url($req->basePath, $req->publicUrl, $this->config->publicDir));
        $isApi = $req->isApi();
        // The explorer proxy speaks JSON but is not part of the API: no CORS headers, ever.
        $isExplore = $req->path === '/explore';

        try {
            $response = $isExplore
                ? (new ExploreController($this->catalog(), $req, $this->config))->handle()
                : $this->dispatch($req, $isApi);
        } catch (\Throwable $e) {
            error_log('[datasets] ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            $response = $isApi || $isExplore
                ? Http::problem(500, 'An unexpected error occurred.', $req->target())
                : Http::text("Internal Server Error\n", 500);
            $response->withHeader('Cache-Control', 'no-store');
            if ($isExplore) {
                ExploreController::withHeaders($response);
            }
        }

        if ($isApi) {
            Http::withApiHeaders($response);
        } else {
            $response->withHeader('X-Content-Type-Options', 'nosniff');
            if ($response->header('Cache-Control') === null) {
                $response->withHeader('Cache-Control', $response->status >= 500 ? 'no-store' : Http::CACHE_CONTROL);
            }
        }
        Http::conditional($req, $response);

        return Http::finalize($req, $response);
    }

    private function dispatch(Request $req, bool $isApi): Response
    {
        // Canonical URLs have no trailing slash (except the root).
        if ($req->path !== '/' && str_ends_with($req->path, '/')) {
            $target = $req->basePath . rtrim($req->path, '/');

            return Http::redirect(($target === '' ? '/' : $target) . ($req->rawQuery !== '' ? '?' . $req->rawQuery : ''), 308);
        }

        $router = $this->router($req);
        $allowed = [];
        $match = $router->match($req->method === 'OPTIONS' ? 'GET' : $req->method, $req->path, $allowed);
        $allow = implode(', ', $allowed);

        if ($req->method === 'OPTIONS' && $allowed !== []) {
            return Http::preflight($req, $allow);
        }
        if ($match === null || $req->method === 'OPTIONS') {
            if ($allowed !== []) {
                $r = $isApi
                    ? Http::problem(405, sprintf('%s is not allowed. Allowed: %s', $req->method, $allow), $req->target())
                    : Http::text("Method Not Allowed\n", 405);

                return $r->withHeader('Allow', $allow);
            }

            return $isApi
                ? Http::problem(404, sprintf('No API resource at %s. Start at %s', $req->basePath . $req->path, $req->url()->absolute('/api/v1')), $req->target())
                : $this->pages($req)->notFound();
        }

        return ($match['handler'])(...array_values($match['params']));
    }

    private function router(Request $req): Router
    {
        $api = fn (): ApiController => new ApiController($this->catalog(), $req);
        $pages = fn (): PageController => $this->pages($req);

        return (new Router())
            ->get('/api/v1', fn () => $api()->root())
            ->get('/api/v1/datasets', fn () => $api()->listDatasets())
            ->get('/api/v1/datasets/{slug}', fn (string $slug) => $api()->getDataset($slug))
            ->get('/api/v1/categories', fn () => $api()->categories())
            ->get('/api/v1/categories/{id}', fn (string $id) => $api()->category($id))
            ->get('/api/v1/tags', fn () => $api()->tags())
            ->get('/api/v1/facets', fn () => $api()->facets())
            ->get('/api/v1/schema', fn () => $api()->schema())
            ->get('/api/v1/openapi.json', fn () => $api()->openapi())
            ->get('/llms.txt', fn () => Http::text((new LlmsTxt($this->catalog(), Url::current()))->render(), 200, 'text/plain; charset=utf-8'))
            ->get('/robots.txt', fn () => Http::text(LlmsTxt::robots(Url::current()->path('/explore'))))
            ->get('/', fn () => $pages()->home())
            ->get('/d/{slug}', fn (string $slug) => $pages()->dataset($slug))
            ->get('/api', fn () => $pages()->api())
            ->get('/about', fn () => $pages()->about());
    }

    private function pages(Request $req): PageController
    {
        return new PageController($this->catalog(), $req, $this->config->templatesDir);
    }
}

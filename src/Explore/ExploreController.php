<?php

declare(strict_types=1);

namespace Datasets\Explore;

use Datasets\Catalog;
use Datasets\Config;
use Datasets\Http\Http;
use Datasets\Http\Request;
use Datasets\Http\Response;

/**
 * POST {base}/explore: the API explorer's proxy. Not part of the public API (no CORS, not in
 * OpenAPI). JSON in: {"slug", "method", "url", "body"?}; JSON out: the upstream result, or an
 * RFC 9457 problem. See docs/ARCHITECTURE.md, "Explorer & proxy", for the threat model.
 */
final class ExploreController
{
    public const MAX_REQUEST_BYTES = 8192;

    public function __construct(
        private readonly Catalog $catalog,
        private readonly Request $request,
        private readonly Config $config,
        private readonly ?Fetcher $fetcher = null,
    ) {
    }

    /**
     * Test mode relaxes https-only and the loopback ban so tests can use a local upstream. It is
     * honoured only under PHP's built-in server, never under Apache/FPM.
     */
    public static function testMode(): bool
    {
        return PHP_SAPI === 'cli-server' && Config::env('APP_EXPLORE_TEST_MODE') === '1';
    }

    public function handle(): Response
    {
        try {
            $response = Http::json($this->explore());
        } catch (Refusal $e) {
            $response = Http::problem($e->status, $e->getMessage(), $this->request->target(), [], $e->title);
            foreach ($e->headers as $name => $value) {
                $response->withHeader($name, $value);
            }
        }

        return self::withHeaders($response);
    }

    /** Headers on every /explore response, including the 405 for other methods. */
    public static function withHeaders(Response $r): Response
    {
        return $r->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /** @return array<string, mixed> */
    private function explore(): array
    {
        $req = $this->request;
        if ($req->method !== 'POST') {
            throw new Refusal(405, 'Method Not Allowed', 'The explorer proxy only accepts POST.', ['Allow' => 'POST']);
        }
        $this->checkOrigin();

        $type = strtolower(trim(explode(';', (string) $req->header('content-type'))[0]));
        if ($type !== 'application/json') {
            throw new Refusal(415, 'Unsupported Media Type', 'Send the request as Content-Type: application/json.');
        }
        $length = $req->header('content-length');
        if ($length !== null && (int) $length > self::MAX_REQUEST_BYTES) {
            throw new Refusal(413, 'Request too large', sprintf('The request body may be at most %d bytes.', self::MAX_REQUEST_BYTES));
        }
        $raw = $req->body(self::MAX_REQUEST_BYTES + 1);
        if (strlen($raw) > self::MAX_REQUEST_BYTES) {
            throw new Refusal(413, 'Request too large', sprintf('The request body may be at most %d bytes.', self::MAX_REQUEST_BYTES));
        }

        $this->rateLimiter()->hit($req->remoteAddr);

        $in = json_decode($raw, true, 4);
        if (!is_array($in) || array_is_list($in)) {
            throw new Refusal(400, 'Invalid request', 'Expected a JSON object: {"slug", "method", "url", "body"}.');
        }
        foreach (['slug', 'method', 'url'] as $k) {
            if (!isset($in[$k]) || !is_string($in[$k])) {
                throw new Refusal(400, 'Invalid request', sprintf('"%s" must be a string.', $k));
            }
        }
        if (isset($in['body']) && !is_string($in['body'])) {
            throw new Refusal(400, 'Invalid request', '"body" must be a string.');
        }

        $dataset = $this->catalog->get($in['slug']);
        if ($dataset === null) {
            throw new Refusal(404, 'Unknown dataset', 'No dataset with that slug.');
        }
        $policy = new Policy($dataset);
        if (($why = $policy->proxyRefusal()) !== null) {
            throw new Refusal(403, 'Not available through the proxy', $why);
        }

        $method = $in['method'];
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new Refusal(400, 'Method not supported', 'Only GET and POST are supported.');
        }
        if (!in_array($method, $policy->methods(), true)) {
            throw new Refusal(403, 'Method not allowed for this API', 'This API is only explored with GET.');
        }
        $body = null;
        if ($method === 'POST') {
            $body = (string) ($in['body'] ?? '');
            if (strlen($body) > Policy::MAX_POST_BODY) {
                throw new Refusal(413, 'Body too large', sprintf('POST bodies may be at most %d bytes.', Policy::MAX_POST_BODY));
            }
        }

        $allowed = Target::allowedHosts($dataset);
        $target = Target::parse($in['url'], $allowed, self::testMode());
        if ($policy->personalKeyIn($target->url())) {
            throw new Refusal(403, 'Personal keys are not proxied', 'Only the documented demo key may be sent through this site. Call the API directly to use your own key.');
        }

        $storage = new Storage($this->config->varDir . '/explore-cache');
        $cacheKey = $method === 'GET' ? $dataset['slug'] . ' ' . $target->url() : null;
        if ($cacheKey !== null && ($hit = $storage->get($cacheKey)) !== null) {
            return ['cached' => true] + $hit;
        }
        $result = $this->fetcher()->fetch($target, $allowed, $method, $body, $policy);
        if ($cacheKey !== null) {
            $storage->put($cacheKey, $result);
        }

        return $result;
    }

    /**
     * Only this site's own pages may call the proxy. Browsers always send Sec-Fetch-Site and/or
     * Origin on a fetch() POST; requests with neither (curl, tests) are left to the rate limit.
     */
    private function checkOrigin(): void
    {
        $site = $this->request->header('sec-fetch-site');
        if ($site !== null && strtolower($site) !== 'same-origin') {
            throw new Refusal(403, 'Cross-site request refused', 'The explorer proxy only answers requests from this site\'s own pages.');
        }
        $origin = $this->request->header('origin');
        if ($origin !== null && strtolower(rtrim($origin, '/')) !== self::origin($this->request->publicUrl)) {
            throw new Refusal(403, 'Cross-origin request refused', 'The explorer proxy only answers requests from this site\'s own pages.');
        }
    }

    /** "https://host[:port]" of an absolute URL. */
    public static function origin(string $url): string
    {
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
            return '';
        }
        $scheme = strtolower($p['scheme']);
        $port = isset($p['port']) && !(($scheme === 'https' && $p['port'] === 443) || ($scheme === 'http' && $p['port'] === 80)) ? ':' . $p['port'] : '';

        return $scheme . '://' . strtolower($p['host']) . $port;
    }

    private function rateLimiter(): RateLimiter
    {
        $limits = [20, 200, 300];
        $override = self::testMode() ? Config::env('APP_EXPLORE_TEST_LIMITS') : null;
        if ($override !== null && preg_match('/^\d+,\d+,\d+$/', $override) === 1) {
            $limits = array_map('intval', explode(',', $override));
        }

        return new RateLimiter($this->config->varDir . '/ratelimit', ...$limits);
    }

    private function fetcher(): Fetcher
    {
        if ($this->fetcher !== null) {
            return $this->fetcher;
        }
        if (!self::testMode()) {
            return new Fetcher();
        }
        // APP_EXPLORE_TEST_RESOLVE="up.test=127.0.0.1,other.test=10.0.0.1": fake DNS for tests.
        $map = [];
        foreach (explode(',', (string) Config::env('APP_EXPLORE_TEST_RESOLVE')) as $pair) {
            [$host, $ip] = array_pad(explode('=', $pair, 2), 2, '');
            if ($host !== '' && $ip !== '') {
                $map[strtolower($host)][] = $ip;
            }
        }

        return new Fetcher(static fn (string $host): array => $map[$host] ?? Fetcher::dns($host), true);
    }
}

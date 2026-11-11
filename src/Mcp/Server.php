<?php

declare(strict_types=1);

namespace Datasets\Mcp;

use Datasets\Api\ApiController;
use Datasets\Catalog;
use Datasets\Config;
use Datasets\Explore\RateLimiter;
use Datasets\Explore\Refusal;
use Datasets\Http\Request;
use Datasets\Http\Response;

/**
 * {base}/mcp: a stateless, read-only MCP server over Streamable HTTP, answering every request
 * with one plain application/json response (no SSE, no sessions). Dual-era:
 *
 *  - Modern (2026-07-28): no handshake; every request carries
 *    params._meta["io.modelcontextprotocol/protocolVersion"] and clientCapabilities, mirrored in
 *    the MCP-Protocol-Version / Mcp-Method / Mcp-Name headers, which must match the body
 *    (else 400 + -32020 HeaderMismatch). server/discover is implemented; results carry
 *    resultType and _meta serverInfo; unknown methods are 404 + -32601.
 *  - Legacy (2025-03-26 .. 2025-11-25): initialize is answered without minting an
 *    Mcp-Session-Id, notifications get 202, and later requests are served statelessly.
 *
 * Public and credential-free: any well-formed Origin is accepted (browsers may call it, CORS
 * "*", never with credentials); rate-limited per client; no upstream fetches. See
 * docs/ARCHITECTURE.md, "MCP server".
 */
final class Server
{
    public const MODERN = ['2026-07-28'];

    public const LEGACY = ['2025-11-25', '2025-06-18', '2025-03-26'];

    public const MAX_BODY = 65536;

    public const SERVER_INFO = ['name' => 'free-datasets', 'title' => 'Free Datasets API Library', 'version' => '1.0.0'];

    public const INSTRUCTIONS = 'Catalogue of free datasets with free REST APIs, each verified with a real request. '
        . 'Use search_datasets to find candidates, then get_dataset for the full record (auth, CORS, rate limit, endpoints, '
        . 'a verified example request, agent_notes, caveats). Call the upstream API yourself; this server only describes APIs.';

    /** Request headers a browser may send cross-origin (CORS preflight). */
    public const ALLOW_HEADERS = 'Content-Type, Accept, MCP-Protocol-Version, Mcp-Method, Mcp-Name, Mcp-Session-Id, Last-Event-ID';

    /** Per client per minute, per client per day, all clients per minute. */
    public const LIMITS = [120, 5000, 1200];

    private const META_VERSION = 'io.modelcontextprotocol/protocolVersion';
    private const META_CAPS = 'io.modelcontextprotocol/clientCapabilities';
    private const META_SERVER = 'io.modelcontextprotocol/serverInfo';

    public function __construct(
        private readonly Catalog $catalog,
        private readonly Request $request,
        private readonly Config $config,
    ) {
    }

    /** All supported protocol versions, newest first. */
    public static function supported(): array
    {
        return array_merge(self::MODERN, self::LEGACY);
    }

    public function handle(): Response
    {
        $req = $this->request;
        if ($req->method === 'OPTIONS') {
            return self::withHeaders($this->preflight());
        }
        if ($req->method !== 'POST') {
            // No GET stream and no DELETE (there are no sessions to end): 405, as the spec asks.
            return self::withHeaders(new Response(405, ['Allow' => 'POST, OPTIONS', 'Content-Type' => 'text/plain; charset=utf-8'],
                "The MCP endpoint accepts POST only (stateless Streamable HTTP, JSON responses).\n"));
        }

        $id = null;
        try {
            $this->checkOrigin();
            $this->rateLimit();
            $msg = $this->parse();
            $id = $msg['id'] ?? null;
            if (array_key_exists('params', $msg) && (!is_array($msg['params']) || ($msg['params'] !== [] && array_is_list($msg['params'])))) {
                throw new RpcError(400, RpcError::INVALID_REQUEST, 'Invalid Request: params must be an object.');
            }
            if (!array_key_exists('id', $msg)) {
                return self::withHeaders(new Response(202)); // notification (e.g. notifications/initialized): accepted, no body
            }
            $modern = $this->era($msg);
            $result = $this->dispatch((string) $msg['method'], is_array($msg['params'] ?? null) ? $msg['params'] : [], $modern);
            if ($modern) {
                $result = ['resultType' => 'complete'] + $result + ['_meta' => [self::META_SERVER => self::SERVER_INFO]];
            }

            return self::withHeaders(self::json(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result === [] ? new \stdClass() : $result]));
        } catch (RpcError $e) {
            $error = ['code' => $e->rpcCode, 'message' => $e->getMessage()];
            if ($e->data !== null) {
                $error['data'] = $e->data;
            }
            $body = ['jsonrpc' => '2.0'] + ($id !== null ? ['id' => $id] : []) + ['error' => $error];
            $r = self::json($e->status, $body);
            foreach ($e->headers as $name => $value) {
                $r->withHeader($name, $value);
            }

            return self::withHeaders($r);
        }
    }

    /** Headers on every /mcp response (App adds nothing else: /mcp bypasses the generic dispatcher). */
    public static function withHeaders(Response $r): Response
    {
        return $r->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Expose-Headers', 'Retry-After')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    // ---------------------------------------------------------------- transport checks

    private function preflight(): Response
    {
        $requested = $this->request->header('access-control-request-headers');
        $allow = ($requested !== null && preg_match('/^[A-Za-z0-9\-, ]{1,500}$/', $requested) === 1) ? $requested : self::ALLOW_HEADERS;

        return new Response(204, [
            'Allow' => 'POST, OPTIONS',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => $allow,
            'Access-Control-Max-Age' => '86400',
        ]);
    }

    /**
     * The spec asks servers to validate Origin (against DNS rebinding of local servers). This is a
     * public, read-only, credential-free endpoint, so every well-formed origin is valid; malformed
     * values are refused with 403. Credentials are never allowed (no Allow-Credentials header).
     */
    private function checkOrigin(): void
    {
        $origin = $this->request->header('origin');
        if ($origin === null || $origin === 'null') {
            return;
        }
        if (preg_match('~^https?://[A-Za-z0-9.\-]+(:\d{1,5})?$|^https?://\[[0-9A-Fa-f:.]+\](:\d{1,5})?$~', $origin) !== 1) {
            throw new RpcError(403, RpcError::INVALID_REQUEST, 'Origin not allowed: malformed Origin header');
        }
    }

    private function rateLimit(): void
    {
        $limits = self::LIMITS;
        $override = PHP_SAPI === 'cli-server' ? Config::env('APP_MCP_TEST_LIMITS') : null;
        if ($override !== null && preg_match('/^\d+,\d+,\d+$/', $override) === 1) {
            $limits = array_map('intval', explode(',', $override));
        }
        try {
            (new RateLimiter($this->config->varDir . '/ratelimit-mcp', ...$limits))->hit($this->request->remoteAddr);
        } catch (Refusal $e) {
            $message = $e->status === 429 ? 'Rate limit reached for this MCP server; retry after the Retry-After delay.' : 'Rate limiter unavailable.';
            throw new RpcError($e->status, RpcError::RATE_LIMITED, $message, null, $e->headers);
        }
    }

    /** @return array<string, mixed> one JSON-RPC request or notification */
    private function parse(): array
    {
        $type = strtolower(trim(explode(';', (string) $this->request->header('content-type'))[0]));
        if ($type !== 'application/json') {
            throw new RpcError(415, RpcError::INVALID_REQUEST, 'Send the JSON-RPC message as Content-Type: application/json.');
        }
        $length = $this->request->header('content-length');
        $raw = ($length !== null && (int) $length > self::MAX_BODY) ? str_repeat(' ', self::MAX_BODY + 1) : $this->request->body(self::MAX_BODY + 1);
        if (strlen($raw) > self::MAX_BODY) {
            throw new RpcError(413, RpcError::INVALID_REQUEST, sprintf('Request body too large (max %d bytes).', self::MAX_BODY));
        }
        try {
            $msg = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RpcError(400, RpcError::PARSE_ERROR, 'Parse error: the body is not valid JSON.');
        }
        if (is_array($msg) && array_is_list($msg) && $msg !== []) {
            throw new RpcError(400, RpcError::INVALID_REQUEST, 'Batch requests are not supported: send one JSON-RPC message per POST.');
        }
        if (!is_array($msg) || $msg === [] || ($msg['jsonrpc'] ?? null) !== '2.0' || !is_string($msg['method'] ?? null) || $msg['method'] === '') {
            throw new RpcError(400, RpcError::INVALID_REQUEST, 'Invalid Request: expected one JSON-RPC 2.0 request or notification object.');
        }
        if (array_key_exists('id', $msg) && !is_string($msg['id']) && !is_int($msg['id'])) {
            throw new RpcError(400, RpcError::INVALID_REQUEST, 'Invalid Request: id must be a string or an integer.');
        }
        return $msg;
    }

    /**
     * Decide the protocol era of a request and enforce that era's rules.
     *
     * @param array<string, mixed> $msg
     * @return bool true = modern (2026-07-28 per-request metadata)
     */
    private function era(array $msg): bool
    {
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $bodyVersion = $meta[self::META_VERSION] ?? null;
        $header = $this->request->header('mcp-protocol-version');
        $method = (string) $msg['method'];

        if ($bodyVersion === null && !in_array($header, self::MODERN, true)) {
            // Legacy: initialize negotiates; later requests may name their version in the header.
            if ($header !== null && $method !== 'initialize' && !in_array($header, self::LEGACY, true)) {
                throw new RpcError(400, RpcError::UNSUPPORTED_VERSION, 'Unsupported protocol version', ['supported' => self::supported(), 'requested' => $header]);
            }

            return false;
        }

        if (!is_string($bodyVersion)) {
            throw new RpcError(400, RpcError::INVALID_PARAMS, 'Missing params._meta["' . self::META_VERSION . '"] (required with MCP-Protocol-Version ' . $header . ').');
        }
        if (!in_array($bodyVersion, self::supported(), true)) {
            throw new RpcError(400, RpcError::UNSUPPORTED_VERSION, 'Unsupported protocol version', ['supported' => self::supported(), 'requested' => $bodyVersion]);
        }
        if (!is_array($meta[self::META_CAPS] ?? null)) {
            throw new RpcError(400, RpcError::INVALID_PARAMS, 'Missing params._meta["' . self::META_CAPS . '"].');
        }
        $this->checkHeader('MCP-Protocol-Version', $header, $bodyVersion, false);
        $this->checkHeader('Mcp-Method', $this->request->header('mcp-method'), $method, false);
        if (in_array($method, ['tools/call', 'prompts/get', 'resources/read'], true)) {
            $source = $method === 'resources/read' ? ($params['uri'] ?? null) : ($params['name'] ?? null);
            $this->checkHeader('Mcp-Name', $this->request->header('mcp-name'), is_string($source) ? $source : '', true);
        }

        return true;
    }

    private function checkHeader(string $name, ?string $value, string $expected, bool $base64): void
    {
        if ($value === null) {
            throw new RpcError(400, RpcError::HEADER_MISMATCH, "Header mismatch: required header $name is missing");
        }
        if (preg_match('/^[\x20-\x7E\t]*$/', $value) !== 1) {
            throw new RpcError(400, RpcError::HEADER_MISMATCH, "Header mismatch: $name contains invalid characters");
        }
        if ($base64 && str_starts_with($value, '=?base64?') && str_ends_with($value, '?=') && strlen($value) >= 11) {
            $decoded = base64_decode(substr($value, 9, -2), true);
            if ($decoded === false) {
                throw new RpcError(400, RpcError::HEADER_MISMATCH, "Header mismatch: $name is not valid base64");
            }
            $value = $decoded;
        }
        if ($value !== $expected) {
            throw new RpcError(400, RpcError::HEADER_MISMATCH, sprintf("Header mismatch: %s header value '%s' does not match body value '%s'",
                $name, self::clip($value), self::clip($expected)));
        }
    }

    // ---------------------------------------------------------------- methods

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function dispatch(string $method, array $params, bool $modern): array
    {
        $caps = ['tools' => ['listChanged' => false]];
        $tools = new Tools($this->catalog, new ApiController($this->catalog, $this->request));
        $cache = $modern ? ['ttlMs' => 3_600_000, 'cacheScope' => 'public'] : [];

        switch ($method) {
            case 'initialize':
                if ($modern) {
                    break; // not a method in the stateless revision
                }
                $requested = $params['protocolVersion'] ?? null;

                return [
                    'protocolVersion' => in_array($requested, self::LEGACY, true) ? $requested : self::LEGACY[0],
                    'capabilities' => $caps,
                    'serverInfo' => self::SERVER_INFO,
                    'instructions' => self::INSTRUCTIONS,
                ];
            case 'ping':
                return [];
            case 'server/discover':
                return ['supportedVersions' => self::supported(), 'capabilities' => $caps, 'instructions' => self::INSTRUCTIONS]
                    + ($modern ? [] : ['serverInfo' => self::SERVER_INFO]) + $cache;
            case 'tools/list':
                return ['tools' => $tools->definitions()] + $cache;
            case 'tools/call':
                $name = $params['name'] ?? null;
                if (!is_string($name) || !$tools->has($name)) {
                    throw new RpcError(200, RpcError::INVALID_PARAMS, 'Unknown tool: ' . (is_string($name) ? self::clip($name) : '(missing name)')
                        . '. Available: search_datasets, get_dataset, list_categories');
                }
                $args = $params['arguments'] ?? [];
                if ($args !== [] && (!is_array($args) || array_is_list($args))) {
                    throw new RpcError(200, RpcError::INVALID_PARAMS, 'arguments must be an object');
                }

                return $tools->call($name, $args);
        }

        throw new RpcError($modern ? 404 : 200, RpcError::METHOD_NOT_FOUND, 'Method not found: ' . self::clip($method));
    }

    // ---------------------------------------------------------------- helpers

    private static function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }

    private static function clip(string $s): string
    {
        $s = preg_replace('/[^\x20-\x7E]/', '?', $s) ?? '';

        return strlen($s) > 80 ? substr($s, 0, 80) . '…' : $s;
    }
}

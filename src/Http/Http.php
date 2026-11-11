<?php

declare(strict_types=1);

namespace Datasets\Http;

use Datasets\Json;

/** Response factories and the cross-cutting header logic (CORS, caching, ETag/304). */
final class Http
{
    public const CACHE_CONTROL = 'public, max-age=300';

    public const ALLOWED_METHODS = 'GET, HEAD, OPTIONS';

    private const REASONS = [
        200 => 'OK', 204 => 'No Content', 301 => 'Moved Permanently', 304 => 'Not Modified',
        308 => 'Permanent Redirect', 400 => 'Bad Request', 404 => 'Not Found',
        405 => 'Method Not Allowed', 500 => 'Internal Server Error',
    ];

    public static function reason(int $status): string
    {
        return self::REASONS[$status] ?? 'Error';
    }

    public static function json(mixed $data, int $status = 200, string $contentType = 'application/json; charset=utf-8'): Response
    {
        return new Response($status, ['Content-Type' => $contentType], Json::encode($data) . "\n");
    }

    /**
     * RFC 9457 problem details.
     *
     * @param array<string, mixed> $extra extension members, e.g. ['errors' => [...]]
     */
    public static function problem(int $status, string $detail, string $instance, array $extra = [], ?string $title = null): Response
    {
        $body = [
            'type' => 'about:blank',
            'title' => $title ?? self::reason($status),
            'status' => $status,
            'detail' => $detail,
            'instance' => $instance,
        ] + $extra;

        return self::json($body, $status, 'application/problem+json; charset=utf-8');
    }

    public static function text(string $body, int $status = 200, string $contentType = 'text/plain; charset=utf-8'): Response
    {
        return new Response($status, ['Content-Type' => $contentType], $body);
    }

    public static function html(string $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    public static function redirect(string $location, int $status = 308): Response
    {
        return new Response($status, ['Location' => $location, 'Content-Type' => 'text/plain; charset=utf-8'], "Redirecting to $location\n");
    }

    /** Headers every API response carries. */
    public static function withApiHeaders(Response $r): Response
    {
        $r->withHeader('Access-Control-Allow-Origin', '*');
        $r->withHeader('Access-Control-Expose-Headers', 'ETag, Link, Allow, Content-Length');
        $r->withHeader('X-Content-Type-Options', 'nosniff');
        if ($r->header('Cache-Control') === null) {
            $r->withHeader('Cache-Control', $r->status >= 500 ? 'no-store' : self::CACHE_CONTROL);
        }

        return $r;
    }

    /** CORS preflight / OPTIONS answer for a resource that allows $allow. */
    public static function preflight(Request $req, string $allow = self::ALLOWED_METHODS): Response
    {
        $requested = $req->header('access-control-request-headers');
        $allowHeaders = ($requested !== null && preg_match('/^[A-Za-z0-9\-, ]{1,500}$/', $requested) === 1)
            ? $requested
            : 'Accept, Content-Type, If-None-Match, If-Modified-Since';

        return new Response(204, [
            'Allow' => $allow,
            'Access-Control-Allow-Methods' => $allow,
            'Access-Control-Allow-Headers' => $allowHeaders,
            'Access-Control-Max-Age' => '86400',
            'Cache-Control' => self::CACHE_CONTROL,
        ]);
    }

    /**
     * Add a strong ETag (hash of the body) and turn a matching If-None-Match into 304.
     * Only for 200 responses to GET/HEAD.
     */
    public static function conditional(Request $req, Response $r): Response
    {
        if ($r->status !== 200 || !in_array($req->method, ['GET', 'HEAD'], true)) {
            return $r;
        }
        $etag = '"' . substr(hash('sha256', $r->body), 0, 32) . '"';
        $r->withHeader('ETag', $etag);
        $inm = $req->header('if-none-match');
        if ($inm !== null && self::etagMatches($inm, $etag)) {
            $r->status = 304;
            $r->body = '';
            $r->withoutHeader('Content-Type');
            $r->withoutHeader('Content-Length');
        }

        return $r;
    }

    /** Weak comparison per RFC 9110 §13.1.2; tolerates the "-gzip" suffix mod_deflate appends. */
    public static function etagMatches(string $ifNoneMatch, string $etag): bool
    {
        if (trim($ifNoneMatch) === '*') {
            return true;
        }
        $norm = static fn (string $t): string => preg_replace('/-(gzip|br|deflate)"$/', '"', preg_replace('/^W\//', '', trim($t)) ?? '') ?? '';
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            if ($norm($candidate) === $norm($etag)) {
                return true;
            }
        }

        return false;
    }

    /** Final touches before sending: Content-Length; HEAD keeps headers but drops the body later. */
    public static function finalize(Request $req, Response $r): Response
    {
        if ($r->status !== 204 && $r->status !== 304) {
            $r->withHeader('Content-Length', (string) strlen($r->body));
        }

        return $r;
    }
}

<?php

declare(strict_types=1);

namespace Datasets\Explore;

use Datasets\Web\View;

/**
 * What the API explorer may do for one record: whether the proxy may carry its requests,
 * whether a browser can call it directly, which methods are allowed and which headers go
 * upstream. Shared by the proxy (ExploreController) and the dataset page, so both agree.
 */
final class Policy
{
    public const USER_AGENT = 'datasets-library-explorer/1.0 (+https://endtime-instruments.org/datasets/)';

    public const DEFAULT_ACCEPT = 'application/json, */*;q=0.5';

    public const FORM_TYPE = 'application/x-www-form-urlencoded';

    public const MAX_POST_BODY = 4000;

    /** Header names a record can never make the proxy send (ours or hop-by-hop or credentials). */
    private const NEVER_SEND = [
        'user-agent', 'host', 'cookie', 'cookie2', 'authorization', 'proxy-authorization', 'content-length',
        'transfer-encoding', 'connection', 'keep-alive', 'upgrade', 'te', 'trailer', 'expect', 'accept-encoding',
        'origin', 'referer', 'forwarded', 'via', 'x-real-ip',
    ];

    /** @param array<string, mixed> $dataset */
    public function __construct(public readonly array $dataset)
    {
    }

    /** "proxy" (proxy and maybe direct), "direct" (browser only: personal key needed) or "off". */
    public function mode(): string
    {
        if ($this->proxyRefusal() === null) {
            return 'proxy';
        }
        if (($this->dataset['status'] ?? '') !== 'deprecated' && $this->directPossible('GET')) {
            return 'direct';
        }

        return 'off';
    }

    /** Why the proxy will not carry this record's requests, or null when it will. */
    public function proxyRefusal(): ?string
    {
        if (($this->dataset['status'] ?? '') === 'deprecated') {
            return 'This API is marked deprecated, so the explorer is turned off.';
        }
        $auth = (array) ($this->dataset['api']['auth'] ?? []);
        if (($auth['type'] ?? '') === 'none' || (string) ($auth['demo_key'] ?? '') !== '') {
            return null;
        }

        return 'This API needs your own free key; requests with personal keys are not sent through this site.';
    }

    /** A browser can make this request itself: CORS allows it, no special headers, GET. */
    public function directPossible(string $method): bool
    {
        return $method === 'GET' && View::browserUsable($this->dataset) && empty($this->dataset['api']['required_headers']);
    }

    /** @return list<string> GET, plus POST when the example or an endpoint uses it */
    public function methods(): array
    {
        $methods = ['GET'];
        $listed = [(string) ($this->dataset['example']['method'] ?? 'GET')];
        foreach ((array) ($this->dataset['endpoints'] ?? []) as $ep) {
            $listed[] = (string) ($ep['method'] ?? 'GET');
        }
        if (in_array('POST', $listed, true)) {
            $methods[] = 'POST';
        }

        return $methods;
    }

    /** The record's example headers plus required headers' examples, example headers winning. @return array<string, array{0: string, 1: string}> */
    public function recordHeaders(): array
    {
        $out = [];
        foreach ((array) ($this->dataset['api']['required_headers'] ?? []) as $h) {
            if (isset($h['name'], $h['example'])) {
                $out[strtolower((string) $h['name'])] = [(string) $h['name'], (string) $h['example']];
            }
        }
        foreach ((array) ($this->dataset['example']['headers'] ?? []) as $name => $value) {
            $out[strtolower((string) $name)] = [(string) $name, (string) $value];
        }

        return $out;
    }

    /** Content-Type for POST bodies: the record's example Content-Type, else form encoding. */
    public function postContentType(): string
    {
        foreach ((array) ($this->dataset['example']['headers'] ?? []) as $name => $value) {
            if (strcasecmp((string) $name, 'content-type') === 0 && self::safeValue((string) $value)) {
                return (string) $value;
            }
        }

        return self::FORM_TYPE;
    }

    /**
     * Exactly the headers sent upstream: our User-Agent, Accept (unless the record sets one),
     * the record's own safe headers, Content-Type for POST. Nothing from the visitor.
     *
     * @return list<string> "Name: value"
     */
    public function upstreamHeaders(string $method): array
    {
        $headers = ['user-agent' => 'User-Agent: ' . self::USER_AGENT, 'accept' => 'Accept: ' . self::DEFAULT_ACCEPT];
        foreach ($this->recordHeaders() as $key => [$name, $value]) {
            if (in_array($key, self::NEVER_SEND, true) || str_starts_with($key, 'proxy-') || str_starts_with($key, 'x-forwarded')
                || preg_match('/^[A-Za-z0-9-]{1,60}$/', $name) !== 1 || !self::safeValue($value)) {
                continue;
            }
            $headers[$key] = $name . ': ' . $value;
        }
        if ($method === 'POST') {
            $headers['content-type'] = 'Content-Type: ' . $this->postContentType();
        }
        $headers['expect'] = 'Expect:'; // no 100-continue round trip

        return array_values($headers);
    }

    /**
     * Refuse personal keys: when the key travels in the query string, only the documented demo
     * key (or no key) may be sent through the proxy.
     */
    public function personalKeyIn(string $url): bool
    {
        $auth = (array) ($this->dataset['api']['auth'] ?? []);
        $name = (string) ($auth['key_name'] ?? '');
        if (($auth['key_in'] ?? '') !== 'query' || $name === '') {
            return false;
        }
        $query = (string) parse_url($url, PHP_URL_QUERY);
        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            if (strcasecmp(rawurldecode($k), $name) === 0) {
                $v = rawurldecode(str_replace('+', ' ', $v));
                if ($v !== '' && $v !== (string) ($auth['demo_key'] ?? '')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Everything explorer.js needs, embedded in the dataset page as JSON.
     *
     * @return array<string, mixed>
     */
    public function clientConfig(string $proxyUrl): array
    {
        $d = $this->dataset;
        $base = rtrim((string) $d['api']['base_url'], '/');
        $endpoints = [];
        foreach ((array) $d['endpoints'] as $ep) {
            $path = (string) $ep['path'];
            $endpoints[] = [
                'method' => (string) $ep['method'],
                'path' => $path,
                'url' => str_starts_with($path, 'https://') ? $path : $base . $path,
                'summary' => (string) $ep['summary'],
            ];
        }
        $curlHeaders = [];
        foreach ($this->recordHeaders() as [$name, $value]) {
            $curlHeaders[] = [$name, $value];
        }
        $auth = (array) $d['api']['auth'];

        return [
            'slug' => (string) $d['slug'],
            'proxy' => $proxyUrl,
            'mode' => $this->mode(),
            'cors' => View::browserUsable($d),
            'requiredHeaders' => !empty($d['api']['required_headers']),
            'methods' => $this->methods(),
            'example' => [
                'method' => (string) $d['example']['method'],
                'url' => (string) $d['example']['url'],
                'body' => (string) ($d['example']['body'] ?? ''),
            ],
            'endpoints' => $endpoints,
            'hosts' => array_keys(Target::allowedHosts($d)),
            'headers' => $curlHeaders,
            'postType' => $this->postContentType(),
            'maxBody' => self::MAX_POST_BODY,
            'key' => ($auth['key_in'] ?? '') === 'query' && isset($auth['key_name'])
                ? ['name' => (string) $auth['key_name'], 'demo' => (string) ($auth['demo_key'] ?? '')]
                : null,
        ];
    }

    private static function safeValue(string $value): bool
    {
        return $value !== '' && strlen($value) <= 500 && preg_match('/[^\x20-\x7e\t]/', $value) !== 1;
    }
}

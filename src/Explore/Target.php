<?php

declare(strict_types=1);

namespace Datasets\Explore;

/**
 * A validated upstream URL for the explorer proxy, rebuilt from checked parts.
 *
 * Target::parse() accepts only https://host[:port]/path?query where the host is exactly one
 * of a record's own hosts (Target::allowedHosts) and the port is 443 or one that record uses.
 * It refuses anything a URL parser and curl could read differently: whitespace, control or
 * non-ASCII characters, backslashes, credentials, IP-literal or odd authorities.
 * Target::isPublicIp() decides whether a resolved address may be connected to.
 */
final class Target
{
    public const MAX_URL_LENGTH = 2000;

    /** IPv4 ranges that are never contacted (private, loopback, link-local, CGNAT, multicast, reserved, documentation). */
    private const V4_BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.31.196.0/24', '192.52.193.0/24', '192.88.99.0/24',
        '192.168.0.0/16', '192.175.48.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
        '224.0.0.0/4', '240.0.0.0/4',
    ];

    /**
     * IPv6: only global unicast (2000::/3) is allowed, minus these. Everything outside 2000::/3 is
     * refused, which covers ::, ::1, IPv4-mapped/compatible (::ffff:0:0/96, ::/96), NAT64
     * (64:ff9b::/96), discard (100::/64), ULA (fc00::/7), link-local (fe80::/10), site-local and multicast.
     */
    private const V6_BLOCKED = ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'];

    private function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly string $pathAndQuery,
    ) {
    }

    /** The rebuilt URL (default port omitted). */
    public function url(): string
    {
        $default = $this->scheme === 'https' ? 443 : 80;

        return $this->scheme . '://' . $this->host . ($this->port === $default ? '' : ':' . $this->port) . $this->pathAndQuery;
    }

    /**
     * Hosts a record may be explored on: those of api.base_url, example.url and absolute
     * endpoint paths. Each maps to its allowed ports (443 plus any port the record's URLs name).
     *
     * @param array<string, mixed> $dataset
     * @return array<string, list<int>> lower-case host => ports
     */
    public static function allowedHosts(array $dataset): array
    {
        $urls = [(string) ($dataset['api']['base_url'] ?? ''), (string) ($dataset['example']['url'] ?? '')];
        foreach ((array) ($dataset['endpoints'] ?? []) as $ep) {
            $path = (string) ($ep['path'] ?? '');
            if (str_starts_with($path, 'https://')) {
                $urls[] = $path;
            }
        }
        $hosts = [];
        foreach ($urls as $u) {
            $p = parse_url($u);
            if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || !isset($p['host']) || isset($p['user'])) {
                continue;
            }
            $host = strtolower((string) $p['host']);
            if (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host) !== 1) {
                continue;
            }
            $hosts[$host] ??= [443];
            if (isset($p['port']) && !in_array((int) $p['port'], $hosts[$host], true)) {
                $hosts[$host][] = (int) $p['port'];
            }
        }
        ksort($hosts);

        return $hosts;
    }

    /**
     * Validate $url against a record's allowlist and rebuild it from its parts.
     *
     * @param array<string, list<int>> $allowed from allowedHosts()
     * @param bool $allowHttp test mode only: accept http:// (for a local upstream)
     * @throws Refusal
     */
    public static function parse(string $url, array $allowed, bool $allowHttp = false): self
    {
        if ($url === '') {
            throw new Refusal(400, 'Missing URL', 'Send the URL to request in "url".');
        }
        if (strlen($url) > self::MAX_URL_LENGTH) {
            throw new Refusal(400, 'URL too long', sprintf('URLs may be at most %d characters.', self::MAX_URL_LENGTH));
        }
        if (preg_match('/[^\x21-\x7e]/', $url) === 1) {
            throw new Refusal(400, 'Invalid characters in URL', 'The URL contains spaces, line breaks, control or non-ASCII characters. Percent-encode them.');
        }
        if (str_contains($url, '\\')) {
            throw new Refusal(400, 'Invalid characters in URL', 'Backslashes are not allowed in the URL.');
        }
        if (preg_match('~^([A-Za-z][A-Za-z0-9+.-]*)://([^/?#]*)([/?#].*)?$~', $url, $m) !== 1) {
            throw new Refusal(400, 'Invalid URL', 'Expected an absolute URL such as https://host/path.');
        }
        $scheme = strtolower($m[1]);
        if ($scheme !== 'https' && !($allowHttp && $scheme === 'http')) {
            throw new Refusal(400, 'Only https URLs', 'The explorer only requests https:// URLs.');
        }
        $authority = $m[2];
        $rest = $m[3] ?? '';
        if (str_contains($authority, '@')) {
            throw new Refusal(400, 'Credentials in URL', 'URLs with a user name or password (user@host) are not allowed.');
        }
        if (preg_match('/^([A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?)(?::([0-9]{1,5}))?$/', $authority, $a) !== 1) {
            throw new Refusal(400, 'Invalid host', 'The host must be a plain host name, optionally with a port.');
        }
        $host = strtolower($a[1]);
        $default = $scheme === 'https' ? 443 : 80;
        $port = isset($a[2]) && $a[2] !== '' ? (int) $a[2] : $default;
        if ($port < 1 || $port > 65535) {
            throw new Refusal(400, 'Invalid port', 'The port is out of range.');
        }

        // Our split must agree with parse_url (what PHP sees) before anything is rebuilt.
        $beforeFragment = explode('#', $rest, 2)[0];
        [$path, $query] = array_pad(explode('?', $beforeFragment, 2), 2, null);
        $p = parse_url($url);
        if (!is_array($p)
            || strtolower((string) ($p['scheme'] ?? '')) !== $scheme
            || strtolower((string) ($p['host'] ?? '')) !== $host
            || (int) ($p['port'] ?? $default) !== $port
            || isset($p['user']) || isset($p['pass'])
            || (string) ($p['path'] ?? '') !== (string) $path
            || ($p['query'] ?? null) !== $query
        ) {
            throw new Refusal(400, 'Ambiguous URL', 'The URL could not be parsed unambiguously.');
        }

        if (!isset($allowed[$host])) {
            throw new Refusal(403, 'Host not allowed', sprintf(
                'The explorer only sends requests to this API\'s own hosts: %s.',
                implode(', ', array_keys($allowed)) ?: '(none)'
            ));
        }
        if (!in_array($port, $allowed[$host], true)) {
            throw new Refusal(403, 'Port not allowed', sprintf('Port %d is not used by this API.', $port));
        }

        $pathAndQuery = ($path === '' || $path === null ? '/' : $path) . ($query !== null ? '?' . $query : '');

        return new self($scheme, $host, $port, $pathAndQuery);
    }

    /** True for a globally routable unicast address; false for anything private, local, reserved or malformed. */
    public static function isPublicIp(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (strlen($bin) === 4) {
            foreach (self::V4_BLOCKED as $cidr) {
                if (self::inCidr($bin, $cidr)) {
                    return false;
                }
            }

            return true;
        }
        if (!self::inCidr($bin, '2000::/3')) {
            return false;
        }
        foreach (self::V6_BLOCKED as $cidr) {
            if (self::inCidr($bin, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /** 127.0.0.0/8 or ::1 (only ever accepted in test mode). */
    public static function isLoopback(string $ip): bool
    {
        $bin = @inet_pton($ip);

        return $bin !== false && (self::inCidr($bin, '127.0.0.0/8') || $bin === inet_pton('::1'));
    }

    private static function inCidr(string $bin, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netBin = (string) inet_pton($net);
        if (strlen($netBin) !== strlen($bin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rem)) & 0xff;

        return (ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }
}

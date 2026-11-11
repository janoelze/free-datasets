<?php

declare(strict_types=1);

namespace Datasets\Curate;

use Datasets\Explore\Target;
use Datasets\Schema\Linter;
use Datasets\Verify\Verifier;

/**
 * Deterministic acceptance checks for the changes an unattended curation agent proposes
 * (bin/curate-guard.php; used by .github/workflows/curate.yml before anything is merged).
 *
 * The guard's own code, schema and checkers come from $root (a trusted checkout); the patch is
 * applied to $repo (a git work tree at $base). In CI both are the same directory: the patch is
 * only applied after its paths were found to be data files, so no code the guard runs can change.
 *
 * Checks, in order (the first group stops the run before the patch is applied):
 *  1. paths: only datasets/<slug>.json (add, modify, delete; mode 100644) and
 *     .claude/skills/add-datasets/backlog.md (modify). No renames, copies, mode changes, binaries.
 *  2. caps: <= maxAdded new records, <= maxRemoved deletions, <= maxModified existing records
 *     changed beyond verification.last_verified/http_status.
 *  3. the whole datasets/ directory validates; touched files are in canonical format.
 *  4. no `related` points at a deleted slug.
 *  5. hosts: every URL that gets executed or newly published is a public https host (not an IP
 *     literal, not a reserved name, not this site's own host); the example host resolves only
 *     to public addresses. Modified records may not move a link to a new domain.
 *  6. free text (names, summary, description, agent_notes, caveats, notes, ...): no URLs to hosts
 *     other than the record's own hosts and news.ycombinator.com, no HTML tags, no control or
 *     invisible formatting characters. backlog.md additions: no HTML tags or control characters.
 *  7. examples: new records, and modified records whose example, required headers or
 *     verification changed, are executed here (https only, one retry) and must return exactly
 *     their recorded verification.http_status (2xx, or the expected no-key status).
 *  8. removals: the slug failed in this run's pre-agent verify report AND the record's git
 *     history shows an earlier failure (a non-2xx recorded http_status, or a dated
 *     "failing since YYYY-MM-DD" caveat older than this run), and backlog.md gains a line
 *     naming the slug.
 *  9. the agent's report exists and is non-empty.
 */
final class Guard
{
    public const BACKLOG = '.claude/skills/add-datasets/backlog.md';

    public const SLUG_FILE = '~^datasets/([a-z0-9]+(?:-[a-z0-9]+)*)\.json$~';

    /** Hosts free text may always link to. */
    public const ALWAYS_ALLOWED_HOSTS = ['news.ycombinator.com'];

    /** Names that are never public hosts (RFC 2606/6761/8375 and common internal suffixes). */
    private const RESERVED_SUFFIXES = ['localhost', 'local', 'localdomain', 'internal', 'intranet', 'lan', 'home', 'corp', 'private', 'test', 'example', 'invalid', 'arpa', 'onion', 'home.arpa'];

    private const HTML_TAG = '~<\\s*script\\b|<\\s*/?\\s*(?:iframe|frame|frameset|object|embed|applet|img|svg|math|style|link|meta|base|form|input|button|textarea|select|option|a|div|span|p|br|hr|table|details|summary|video|audio|source|template|noscript|marquee|body|html|head|title|h[1-6]|b|i|u|em|strong|code|pre|ul|ol|li)(?:\\s*/?\\s*>|\\s+[^<>\\s=]+\\s*=)|<!--|javascript\\s*:~i';

    /** C0/C1 controls, zero-width and bidi formatting characters, BOM, Unicode tag characters. */
    private const INVISIBLE = '~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\xC2[\x80-\x9F]|\xE2\x80[\x8B-\x8F\xA8-\xAE]|\xE2\x81[\xA0-\xA4\xA6-\xAF]|\xEF\xBB\xBF|\xF3\xA0[\x80-\x81][\x80-\xBF]~';

    private const FAILING_SINCE = '~failing since (\d{4}-\d{2}-\d{2})~i';

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $notes = [];

    /** @var array<string, mixed> */
    public array $stats = [
        'added' => [], 'removed' => [], 'modified' => [], 'verification_only' => [],
        'backlog_changed' => false, 'executed' => [], 'reverified' => 0,
    ];

    public bool $pathsOk = false;

    public bool $applied = false;

    /** @var (callable(string): string)|null */
    private $rewrite = null;

    /**
     * @param array{max_added?: int, max_removed?: int, max_modified?: int, site_host?: string, retry_seconds?: int, test_upstream?: string|null} $options
     */
    public function __construct(
        private readonly string $root,
        private readonly string $repo,
        private readonly string $base = 'HEAD',
        private readonly array $options = [],
    ) {
        $up = $options['test_upstream'] ?? null;
        if (is_string($up) && $up !== '') {
            if (preg_match('~^http://127\.0\.0\.1:\d+$~', $up) !== 1) {
                throw new \InvalidArgumentException('test upstream must be http://127.0.0.1:PORT');
            }
            $this->rewrite = static function (string $url) use ($up): string {
                $p = parse_url($url);

                return $up . ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
            };
        }
    }

    private function opt(string $key, mixed $default): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Run every check. $verifyReport is the pre-agent report from bin/curate-select.php,
     * $agentReport the path of the agent's curation-report.md (null = missing).
     *
     * @param array<string, mixed> $verifyReport
     */
    public function run(string $patchFile, array $verifyReport, ?string $agentReport): bool
    {
        if (!$this->checkPaths($patchFile)) {
            return false;
        }
        if (!$this->apply($patchFile)) {
            return false;
        }
        $this->checkAppliedPaths();
        $changes = $this->classify();
        $this->checkCaps();
        $this->checkValidity($changes);
        $this->checkRelated();
        $this->checkHostsAndText($changes);
        $this->checkBacklog();
        $this->checkRemovals($verifyReport);
        $this->checkExamples($changes);
        $this->countReverified($verifyReport);
        if ($agentReport === null || !is_file($agentReport) || trim((string) file_get_contents($agentReport)) === '') {
            $this->errors[] = 'report: the agent did not write curation-report.md (did it finish?)';
        }

        return $this->errors === [];
    }

    // ---- 1. paths ---------------------------------------------------------------------------

    /** Inspect the patch with git's own parser (nothing is written). */
    public function checkPaths(string $patchFile): bool
    {
        $this->pathsOk = false;
        if (!is_file($patchFile)) {
            $this->errors[] = "paths: no patch file at $patchFile";

            return false;
        }
        if (trim((string) file_get_contents($patchFile)) === '') {
            $this->pathsOk = true;

            return true;
        }
        [$code, $numstat, $err] = $this->git(['apply', '--numstat', '-z', $patchFile]);
        if ($code !== 0) {
            $this->errors[] = 'paths: git cannot parse the patch: ' . trim($err);

            return false;
        }
        [, $summary] = $this->git(['apply', '--summary', $patchFile]);
        $ok = true;
        $records = explode("\0", rtrim($numstat, "\0"));
        for ($i = 0; $i < count($records); $i++) {
            $parts = explode("\t", $records[$i], 3);
            if (count($parts) !== 3) {
                continue;
            }
            [$added, $deleted, $path] = $parts;
            if ($path === '') { // rename/copy: the two paths follow as separate records
                $this->errors[] = sprintf('paths: rename or copy %s -> %s is not allowed', $records[$i + 1] ?? '?', $records[$i + 2] ?? '?');
                $ok = false;
                $i += 2;
                continue;
            }
            if ($added === '-' || $deleted === '-') {
                $this->errors[] = "paths: binary change to $path is not allowed";
                $ok = false;
                continue;
            }
            if (preg_match(self::SLUG_FILE, $path) !== 1 && $path !== self::BACKLOG) {
                $this->errors[] = "paths: $path is outside the allowed paths (datasets/<slug>.json, " . self::BACKLOG . ')';
                $ok = false;
            }
        }
        foreach (explode("\n", $summary) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('~^(create|delete) mode (\d+) (.+)$~', $line, $m) === 1) {
                if ($m[2] !== '100644') {
                    $this->errors[] = "paths: {$m[3]} has mode {$m[2]} (only regular 100644 files)";
                    $ok = false;
                }
                if ($m[3] === self::BACKLOG) {
                    $this->errors[] = 'paths: backlog.md may be modified, not created or deleted';
                    $ok = false;
                }
            } elseif (preg_match('~^(mode change|rename|copy|rewrite)~', $line) === 1) {
                $this->errors[] = "paths: not allowed: $line";
                $ok = false;
            }
        }
        $this->pathsOk = $ok;

        return $ok;
    }

    private function apply(string $patchFile): bool
    {
        if (trim((string) file_get_contents($patchFile)) === '') {
            $this->applied = true;

            return true;
        }
        [$code, , $err] = $this->git(['apply', '--index', '--whitespace=nowarn', $patchFile]);
        if ($code !== 0) {
            $this->errors[] = 'apply: the patch does not apply to ' . $this->base . ': ' . trim($err);

            return false;
        }
        $this->applied = true;

        return true;
    }

    /** Defense in depth: what git now reports as changed must also be allowed. */
    private function checkAppliedPaths(): void
    {
        [, $out] = $this->git(['diff', '--cached', '--name-status', '--no-renames', '-z', $this->base]);
        $parts = explode("\0", rtrim($out, "\0"));
        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            $path = $parts[$i + 1];
            if (preg_match(self::SLUG_FILE, $path) !== 1 && $path !== self::BACKLOG) {
                $this->errors[] = "paths: $path changed after applying the patch";
            }
        }
    }

    // ---- 2. classification and caps --------------------------------------------------------

    /** @return array<string, array{op: string, old: ?object, new: ?object, file: string}> slug => change */
    private function classify(): array
    {
        [, $out] = $this->git(['diff', '--cached', '--name-status', '--no-renames', '-z', $this->base, '--', 'datasets', self::BACKLOG]);
        $parts = explode("\0", rtrim($out, "\0"));
        $changes = [];
        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            [$status, $path] = [$parts[$i], $parts[$i + 1]];
            if ($path === self::BACKLOG) {
                $this->stats['backlog_changed'] = true;
                continue;
            }
            if (preg_match(self::SLUG_FILE, $path, $m) !== 1) {
                continue;
            }
            $slug = $m[1];
            $old = $status === 'A' ? null : $this->decode($this->show($this->base, $path));
            $new = $status === 'D' ? null : $this->decode((string) @file_get_contents($this->repo . '/' . $path));
            $op = ['A' => 'added', 'D' => 'removed'][$status] ?? 'modified';
            if ($op === 'modified' && $old !== null && $new !== null && self::withoutVerificationBump($old) == self::withoutVerificationBump($new)) {
                $op = 'verification_only';
            }
            $this->stats[$op][] = $slug;
            $changes[$slug] = ['op' => $op, 'old' => $old, 'new' => $new, 'file' => $this->repo . '/' . $path];
        }

        return $changes;
    }

    private static function withoutVerificationBump(object $d): object
    {
        $c = json_decode((string) json_encode($d), false);
        if (isset($c->verification) && is_object($c->verification)) {
            unset($c->verification->last_verified, $c->verification->http_status);
        }

        return $c;
    }

    private function checkCaps(): void
    {
        $caps = ['added' => (int) $this->opt('max_added', 3), 'removed' => (int) $this->opt('max_removed', 2), 'modified' => (int) $this->opt('max_modified', 10)];
        foreach ($caps as $kind => $max) {
            $n = count($this->stats[$kind]);
            if ($n > $max) {
                $this->errors[] = sprintf('caps: %d records %s, at most %d allowed (%s)', $n, $kind, $max, implode(', ', $this->stats[$kind]));
            }
        }
    }

    // ---- 3./4. validity and related ----------------------------------------------------------

    /** @param array<string, array{op: string, old: ?object, new: ?object, file: string}> $changes */
    private function checkValidity(array $changes): void
    {
        $linter = Linter::fromSchemaDir($this->root . '/schema');
        foreach ($linter->lintDirectory($this->repo . '/datasets') as $file => $r) {
            foreach ($r['errors'] as $e) {
                $this->errors[] = 'validate: datasets/' . basename($file) . ': ' . $e;
            }
        }
        $touched = [];
        foreach ($changes as $c) {
            if ($c['new'] !== null && $c['op'] !== 'verification_only') {
                $touched[] = $c['file'];
            }
        }
        if ($touched !== []) {
            $proc = proc_open(array_merge([PHP_BINARY, $this->root . '/.claude/skills/add-datasets/check-format.php'], $touched), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->repo);
            $out = is_resource($proc) ? (string) stream_get_contents($pipes[1]) : '';
            if (is_resource($proc)) {
                fclose($pipes[1]);
                fclose($pipes[2]);
            }
            if (!is_resource($proc) || proc_close($proc) !== 0) {
                foreach (array_filter(explode("\n", trim($out))) as $line) {
                    $this->errors[] = 'format: ' . str_replace($this->repo . '/', '', $line);
                }
            }
        }
    }

    private function checkRelated(): void
    {
        if ($this->stats['removed'] === []) {
            return;
        }
        $removed = array_fill_keys($this->stats['removed'], true);
        foreach (Linter::datasetFiles($this->repo . '/datasets') as $file) {
            $d = Verifier::load($file);
            foreach ((array) ($d->related ?? []) as $r) {
                if (is_string($r) && isset($removed[$r])) {
                    $this->errors[] = sprintf('related: %s still lists removed record %s', basename($file, '.json'), $r);
                }
            }
        }
    }

    // ---- 5./6. hosts and free text -----------------------------------------------------------

    /** @param array<string, array{op: string, old: ?object, new: ?object, file: string}> $changes */
    private function checkHostsAndText(array $changes): void
    {
        foreach ($changes as $slug => $c) {
            if ($c['new'] === null || $c['op'] === 'verification_only') {
                continue;
            }
            $d = $c['new'];
            $own = self::ownHosts($d);
            $siteHost = (string) $this->opt('site_host', 'endtime-instruments.org');
            $urls = self::publishedUrls($d);
            if ($c['op'] === 'modified' && $c['old'] !== null) {
                $oldDomains = array_map([self::class, 'baseDomain'], array_keys(self::ownHosts($c['old'])));
                foreach ($urls as $ptr => $url) {
                    $h = self::hostOf($url);
                    if ($h !== null && !in_array(self::baseDomain($h), $oldDomains, true)) {
                        $this->errors[] = "hosts: $slug$ptr moves to a new domain ($h); needs a human review";
                    }
                }
            }
            foreach ($urls as $ptr => $url) {
                if ($c['op'] === 'modified' && !in_array($ptr, ['/example/url', '/api/base_url'], true)) {
                    continue; // other links of existing records: the same-domain check above
                }
                $problem = self::hostProblem($url, $siteHost);
                if ($problem !== null) {
                    $this->errors[] = "hosts: $slug$ptr: $problem";
                }
            }
            if ($this->rewrite === null && is_string($d->example->url ?? null) && ($c['op'] === 'added' || self::fingerprintOf($c['old']) !== self::fingerprintOf($d))) {
                $h = self::hostOf($d->example->url);
                if ($h !== null && self::hostProblem($d->example->url, $siteHost) === null) {
                    $problem = self::resolveProblem($h);
                    if ($problem !== null) {
                        $this->errors[] = "hosts: $slug/example/url: $problem";
                    }
                }
            }
            foreach (self::scanText($d, $own) as $p) {
                $this->errors[] = "text: $slug$p";
            }
        }
    }

    /**
     * Free-text problems in a record: foreign URLs, HTML, invisible characters.
     *
     * @param array<string, true>|null $own hosts the text may link to (default: the record's own)
     * @return list<string> "/pointer: problem"
     */
    public static function scanText(object $d, ?array $own = null): array
    {
        $own ??= self::ownHosts($d);
        $problems = [];
        $free = self::freeText($d);
        foreach ($free as $ptr => $text) {
            if (preg_match(self::INVISIBLE, $text) === 1) {
                $problems[] = "$ptr: contains control or invisible formatting characters";
            }
            if (preg_match(self::HTML_TAG, $text, $m) === 1) {
                $problems[] = "$ptr: contains HTML/script markup (" . trim($m[0]) . ')';
            }
            foreach (self::mentionedHosts($text) as $host) {
                if (!self::hostAllowed($host, $own)) {
                    $problems[] = "$ptr: links to a foreign host ($host)";
                }
            }
        }
        foreach (self::allStrings($d) as $ptr => $text) {
            $check = $ptr === '/example/response_excerpt' ? str_replace(["\n", "\r", "\t"], '', $text) : $text;
            if (!isset($free[$ptr]) && preg_match(self::INVISIBLE, $check) === 1) {
                $problems[] = "$ptr: contains control or invisible formatting characters";
            }
        }

        return $problems;
    }

    /** @return array<string, string> pointer => text of the human-readable fields */
    public static function freeText(object $d): array
    {
        $out = [
            '/name' => $d->name ?? null,
            '/summary' => $d->summary ?? null,
            '/description' => $d->description ?? null,
            '/agent_notes' => $d->agent_notes ?? null,
            '/provider/name' => $d->provider->name ?? null,
            '/api/auth/notes' => $d->api->auth->notes ?? null,
            '/api/rate_limit/summary' => $d->api->rate_limit->summary ?? null,
            '/data/license/name' => $d->data->license->name ?? null,
            '/verification/notes' => $d->verification->notes ?? null,
        ];
        foreach ((array) ($d->caveats ?? []) as $i => $c) {
            $out["/caveats/$i"] = $c;
        }
        foreach ((array) ($d->data->coverage ?? []) as $k => $v) {
            $out["/data/coverage/$k"] = $v;
        }
        foreach ((array) ($d->endpoints ?? []) as $i => $e) {
            $out["/endpoints/$i/summary"] = is_object($e) ? ($e->summary ?? null) : null;
        }
        foreach ((array) ($d->api->required_headers ?? []) as $i => $h) {
            $out["/api/required_headers/$i/notes"] = is_object($h) ? ($h->notes ?? null) : null;
        }
        foreach ((array) ($d->hn ?? []) as $i => $h) {
            $out["/hn/$i/title"] = is_object($h) ? ($h->title ?? null) : null;
        }

        return array_filter($out, 'is_string');
    }

    /** @return array<string, string> pointer => every string value in the record */
    private static function allStrings(mixed $v, string $ptr = ''): array
    {
        if (is_string($v)) {
            return [$ptr => $v];
        }
        $out = [];
        if (is_object($v) || is_array($v)) {
            foreach ((array) $v as $k => $x) {
                $out += self::allStrings($x, $ptr . '/' . $k);
            }
        }

        return $out;
    }

    /** @return list<string> lower-case hosts of URLs written in a text */
    public static function mentionedHosts(string $text): array
    {
        $hosts = [];
        if (preg_match_all('~\b(?:https?|ftp|wss?)://([^/\s"\'<>()\[\]{}?#\\\\]+)~i', $text, $m) > 0) {
            foreach ($m[1] as $authority) {
                $hosts[] = strtolower((string) preg_replace('~:\d+$~', '', $authority));
            }
        }
        if (preg_match_all('~(?<![\w.@/-])(www\.[a-z0-9-]+(?:\.[a-z0-9-]+)+)~i', $text, $m) > 0) {
            foreach ($m[1] as $h) {
                $hosts[] = strtolower(rtrim($h, '.'));
            }
        }

        return array_values(array_unique($hosts));
    }

    /** @param array<string, true> $own */
    private static function hostAllowed(string $host, array $own): bool
    {
        if (str_contains($host, '@')) {
            return false;
        }
        if (in_array($host, self::ALWAYS_ALLOWED_HOSTS, true)) {
            return true;
        }
        $base = self::baseDomain($host);
        foreach (array_keys($own) as $h) {
            if (self::baseDomain($h) === $base) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> hosts of the record's own URLs */
    public static function ownHosts(object $d): array
    {
        $hosts = [];
        foreach (self::publishedUrls($d) as $url) {
            $h = self::hostOf($url);
            if ($h !== null) {
                $hosts[$h] = true;
            }
        }

        return $hosts;
    }

    /** @return array<string, string> pointer => URL for every URL field of a record */
    public static function publishedUrls(object $d): array
    {
        $out = [];
        foreach ((array) ($d->links ?? []) as $k => $u) {
            $out["/links/$k"] = $u;
        }
        $out['/api/base_url'] = $d->api->base_url ?? null;
        $out['/example/url'] = $d->example->url ?? null;
        $out['/provider/url'] = $d->provider->url ?? null;
        $out['/data/license/url'] = $d->data->license->url ?? null;
        foreach ((array) ($d->endpoints ?? []) as $i => $e) {
            $p = is_object($e) ? ($e->path ?? null) : null;
            if (is_string($p) && preg_match('~^[a-z]+://~i', $p) === 1) {
                $out["/endpoints/$i/path"] = $p;
            }
        }

        return array_filter($out, 'is_string');
    }

    public static function hostOf(string $url): ?string
    {
        $h = parse_url($url, PHP_URL_HOST);

        return is_string($h) && $h !== '' ? strtolower(trim($h, '[]')) : null;
    }

    /** Registrable-domain approximation: last two labels, three under ccTLD second levels (co.uk, gov.br, ...). */
    public static function baseDomain(string $host): string
    {
        $l = explode('.', strtolower($host));
        $n = count($l);
        if ($n >= 3 && strlen($l[$n - 1]) === 2 && (strlen($l[$n - 2]) <= 3 || in_array($l[$n - 2], ['gouv', 'govt', 'nhs', 'police'], true))) {
            return implode('.', array_slice($l, -3));
        }

        return implode('.', array_slice($l, -2));
    }

    /** Why a URL is not a public https URL on someone else's host, or null when it is. */
    public static function hostProblem(string $url, string $siteHost): ?string
    {
        $p = parse_url($url);
        if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https') {
            return 'must be an https URL';
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return 'must not contain credentials';
        }
        if (isset($p['port']) && (int) $p['port'] !== 443) {
            return 'must use the default https port';
        }
        $host = strtolower((string) ($p['host'] ?? ''));
        if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || str_starts_with($host, '[')) {
            return "host \"$host\" must be a DNS name, not an IP address";
        }
        if (preg_match('~^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]*[a-z0-9]$~', $host) !== 1 || preg_match('~\.[a-z0-9-]*[a-z][a-z0-9-]*$~', $host) !== 1) {
            return "host \"$host\" is not a public DNS name";
        }
        foreach (self::RESERVED_SUFFIXES as $s) {
            if ($host === $s || str_ends_with($host, ".$s")) {
                return "host \"$host\" is a reserved or internal name";
            }
        }
        $site = strtolower($siteHost);
        if ($site !== '' && ($host === $site || str_ends_with($host, ".$site"))) {
            return "host \"$host\" is this catalogue's own site";
        }

        return null;
    }

    private static function resolveProblem(string $host): ?string
    {
        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
            if (isset($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        if ($ips === []) {
            return "host \"$host\" does not resolve";
        }
        foreach ($ips as $ip) {
            if (!Target::isPublicIp($ip)) {
                return "host \"$host\" resolves to a non-public address ($ip)";
            }
        }

        return null;
    }

    // ---- backlog -----------------------------------------------------------------------------

    private function checkBacklog(): void
    {
        if (!$this->stats['backlog_changed']) {
            return;
        }
        $added = $this->addedBacklogLines();
        if (count($added) > 60) {
            $this->errors[] = sprintf('backlog: %d lines added, at most 60', count($added));
        }
        foreach ($added as $line) {
            if (preg_match(self::INVISIBLE, $line) === 1) {
                $this->errors[] = 'backlog: an added line contains control or invisible formatting characters';
            }
            if (preg_match(self::HTML_TAG, $line, $m) === 1) {
                $this->errors[] = 'backlog: an added line contains HTML/script markup (' . trim($m[0]) . ')';
            }
        }
    }

    /** @return list<string> */
    private function addedBacklogLines(): array
    {
        [, $diff] = $this->git(['diff', '--cached', '--no-color', '--unified=0', $this->base, '--', self::BACKLOG]);
        $lines = [];
        foreach (explode("\n", $diff) as $l) {
            if (str_starts_with($l, '+') && !str_starts_with($l, '+++')) {
                $lines[] = substr($l, 1);
            }
        }

        return $lines;
    }

    // ---- 8. removals -------------------------------------------------------------------------

    /** @param array<string, mixed> $verifyReport */
    private function checkRemovals(array $verifyReport): void
    {
        if ($this->stats['removed'] === []) {
            return;
        }
        $runDate = (string) ($verifyReport['date'] ?? '');
        $failedNow = [];
        foreach ((array) ($verifyReport['results'] ?? []) as $r) {
            if (is_array($r) && ($r['result'] ?? '') === 'fail') {
                $failedNow[(string) $r['slug']] = $r;
            }
        }
        $backlogAdded = implode("\n", $this->addedBacklogLines());
        foreach ($this->stats['removed'] as $slug) {
            if (!isset($failedNow[$slug])) {
                $this->errors[] = "removal: $slug did not fail in this run's pre-agent verify report; it may not be removed";
                continue;
            }
            $evidence = $this->priorFailure($slug, $runDate);
            if ($evidence === null) {
                $this->errors[] = "removal: $slug has no earlier failure in its git history (no non-2xx verification.http_status, no \"failing since\" caveat dated before $runDate); mark it unstable with a dated caveat instead";
                continue;
            }
            if (!str_contains($backlogAdded, $slug)) {
                $this->errors[] = "removal: backlog.md gained no line naming $slug (add it to the Rejected list with the reason)";
                continue;
            }
            $now = $failedNow[$slug];
            $this->notes[] = sprintf('removal of %s accepted: failed in this run (%s%s) and %s', $slug, $now['http_status'] ? 'HTTP ' . $now['http_status'] : 'no response', ($now['error'] ?? '') !== '' ? ', ' . $now['error'] : '', $evidence);
        }
    }

    /** Earliest evidence of a failure recorded before this run, or null. */
    private function priorFailure(string $slug, string $runDate): ?string
    {
        $path = "datasets/$slug.json";
        [, $log] = $this->git(['log', '--format=%H %cs', '-n', '200', $this->base, '--', $path]);
        $versions = [[$this->base, 'base']];
        foreach (array_filter(explode("\n", trim($log))) as $line) {
            [$sha, $date] = explode(' ', $line, 2) + [1 => ''];
            $versions[] = [$sha, $date];
        }
        foreach ($versions as [$rev, $label]) {
            $d = $this->decode($this->show($rev, $path));
            if ($d === null) {
                continue;
            }
            $where = $rev === $this->base ? 'the current record' : 'commit ' . substr($rev, 0, 10) . " ($label)";
            foreach ((array) ($d->caveats ?? []) as $c) {
                if (is_string($c) && preg_match(self::FAILING_SINCE, $c, $m) === 1 && ($runDate === '' || $m[1] < $runDate)) {
                    return "$where has the caveat \"failing since {$m[1]}\"";
                }
            }
            $st = (int) ($d->verification->http_status ?? 200);
            if (($st < 200 || $st >= 300) && ($d->verification->with_key ?? true) !== false) {
                return "$where recorded verification.http_status $st";
            }
        }

        return null;
    }

    // ---- 7. examples -------------------------------------------------------------------------

    /** @param array<string, array{op: string, old: ?object, new: ?object, file: string}> $changes */
    private function checkExamples(array $changes): void
    {
        $run = [];
        foreach ($changes as $slug => $c) {
            if ($c['new'] === null) {
                continue;
            }
            $changed = $c['op'] === 'added'
                || self::fingerprintOf($c['old']) !== self::fingerprintOf($c['new'])
                || json_encode($c['old']->verification ?? null) !== json_encode($c['new']->verification ?? null);
            if ($changed) {
                $run[$slug] = $c['new'];
            }
        }
        if ($run === []) {
            return;
        }
        if (count($run) > 25) {
            $this->errors[] = sprintf('examples: %d records would need re-execution, at most 25', count($run));

            return;
        }
        $verifier = new Verifier($this->rewrite === null, $this->rewrite);
        $results = $verifier->run($run);
        $retry = array_filter($run, fn (object $d, string $slug) => !$this->exampleOk($d, $results[$slug]['status']), ARRAY_FILTER_USE_BOTH);
        if ($retry !== []) {
            sleep(max(0, (int) $this->opt('retry_seconds', 10)));
            foreach ($verifier->run($retry) as $slug => $r) {
                $results[$slug] = $r;
            }
        }
        foreach ($run as $slug => $d) {
            $r = $results[$slug];
            $recorded = (int) ($d->verification->http_status ?? 0);
            $this->stats['executed'][$slug] = ['http_status' => $r['status'], 'recorded' => $recorded, 'error' => $r['error'], 'duration_ms' => $r['duration_ms']];
            if (!$this->exampleOk($d, $r['status'])) {
                $this->errors[] = sprintf('examples: %s returned %s%s, but its record says verification.http_status %d', $slug, $r['status'] === 0 ? 'no response' : 'HTTP ' . $r['status'], $r['error'] !== '' ? ' (' . $r['error'] . ')' : '', $recorded);
            }
        }
    }

    private function exampleOk(object $d, int $status): bool
    {
        return Verifier::classify($d, $status) !== 'fail' && $status === (int) ($d->verification->http_status ?? 0);
    }

    private static function fingerprintOf(?object $d): string
    {
        return $d === null ? '' : Verifier::fingerprint($d);
    }

    /** @param array<string, mixed> $verifyReport */
    private function countReverified(array $verifyReport): void
    {
        $n = 0;
        foreach ((array) ($verifyReport['results'] ?? []) as $r) {
            if (!is_array($r) || !in_array($r['result'] ?? '', ['ok', 'expected'], true)) {
                continue;
            }
            $d = Verifier::load($this->repo . '/datasets/' . basename((string) $r['slug']) . '.json');
            if ($d !== null && Verifier::fingerprint($d) === ($r['fingerprint'] ?? '')) {
                $n++;
            }
        }
        $this->stats['reverified'] = $n;
    }

    // ---- output ------------------------------------------------------------------------------

    public function title(string $date): string
    {
        $parts = [sprintf('+%d added', count($this->stats['added'])), sprintf('%d fixed', count($this->stats['modified'])), sprintf('%d removed', count($this->stats['removed'])), sprintf('%d re-verified', $this->stats['reverified'])];

        return "Curation $date: " . implode(', ', $parts);
    }

    public function summaryMarkdown(): string
    {
        $s = $this->stats;
        $list = static fn (array $xs): string => $xs === [] ? '-' : implode(', ', array_map(static fn ($x) => "`$x`", $xs));
        $md = "## Guard summary\n\n";
        $md .= ($this->errors === [] ? '**Result: passed.**' : '**Result: FAILED** (' . count($this->errors) . ' problem' . (count($this->errors) === 1 ? '' : 's') . ').') . "\n\n";
        $md .= "| | records |\n|---|---|\n";
        $md .= '| added | ' . $list($s['added']) . " |\n";
        $md .= '| modified | ' . $list($s['modified']) . " |\n";
        $md .= '| removed | ' . $list($s['removed']) . " |\n";
        $md .= '| verification fields only | ' . $list($s['verification_only']) . " |\n";
        $md .= '| backlog.md changed | ' . ($s['backlog_changed'] ? 'yes' : 'no') . " |\n";
        $md .= '| passing records re-verified by the deterministic run | ' . $s['reverified'] . " |\n";
        if ($s['executed'] !== []) {
            $md .= "\nExamples executed by the guard (not by the agent):\n\n| record | observed | recorded | time |\n|---|---|---|---|\n";
            foreach ($s['executed'] as $slug => $r) {
                $md .= sprintf("| `%s` | %s%s | %d | %d ms |\n", $slug, $r['http_status'] === 0 ? 'no response' : $r['http_status'], $r['error'] !== '' ? ' (' . str_replace('|', '/', $r['error']) . ')' : '', $r['recorded'], $r['duration_ms']);
            }
        }
        foreach ($this->notes as $n) {
            $md .= "\n- $n";
        }
        if ($this->notes !== []) {
            $md .= "\n";
        }
        if ($this->errors !== []) {
            $md .= "\nProblems:\n\n";
            foreach ($this->errors as $e) {
                $md .= '- ' . $e . "\n";
            }
        }

        return $md;
    }

    /** Agent report made safe for a PR body: no controls, no @-mentions, bounded length. */
    public static function sanitizeReport(string $text, int $max = 50000): string
    {
        $text = (string) preg_replace(self::INVISIBLE, '', str_replace("\r\n", "\n", $text));
        $text = str_replace("\t", '    ', $text);
        $text = (string) preg_replace('~(?<![\w`])@([A-Za-z0-9][A-Za-z0-9-]*)~', '`@$1`', $text);
        if (strlen($text) > $max) {
            $text = mb_strcut($text, 0, $max, 'UTF-8') . "\n\n[report truncated]\n";
        }

        return $text;
    }

    // ---- git helpers -------------------------------------------------------------------------

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string}
     */
    private function git(array $args): array
    {
        $cmd = array_merge(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', $this->repo], $args);
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return [127, '', 'cannot run git'];
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
    }

    private function show(string $rev, string $path): string
    {
        [$code, $out] = $this->git(['show', "$rev:$path"]);

        return $code === 0 ? $out : '';
    }

    private function decode(string $json): ?object
    {
        $d = $json === '' ? null : json_decode($json, false);

        return is_object($d) ? $d : null;
    }
}

<?php

declare(strict_types=1);

namespace Datasets\Seo;

use Datasets\Catalog;
use Datasets\Url;

/**
 * Every indexable HTML URL, for /sitemap.xml (sitemaps.org 0.9) and the tests that check the
 * sitemap against the index policy. lastmod is a real date: a record's is the later of `added`
 * and `verification.last_verified`; a listing page's is the latest of its records. Pages that do
 * not derive from records (/api, /about) have no lastmod rather than an invented one. No
 * changefreq/priority: Google ignores both.
 */
final class Sitemap
{
    public function __construct(private readonly Catalog $catalog, private readonly Url $url)
    {
    }

    /** @param array<string, mixed> $d */
    public static function lastmod(array $d): ?string
    {
        $dates = array_filter([(string) ($d['added'] ?? ''), (string) ($d['verification']['last_verified'] ?? '')],
            static fn (string $s): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1);

        return $dates === [] ? null : max($dates);
    }

    /** @param list<array<string, mixed>> $datasets */
    public static function latest(array $datasets): ?string
    {
        $dates = array_filter(array_map(self::lastmod(...), $datasets));

        return $dates === [] ? null : max($dates);
    }

    /**
     * @return list<array{loc: string, lastmod: ?string}>
     */
    public function entries(): array
    {
        $all = $this->catalog->all();
        $out = [
            ['loc' => $this->url->absolute('/'), 'lastmod' => self::latest($all)],
            ['loc' => $this->url->absolute('/api'), 'lastmod' => null],
            ['loc' => $this->url->absolute('/about'), 'lastmod' => null],
        ];
        foreach ($this->catalog->categories() as $c) {
            if ($c['count'] > 0) {
                $out[] = ['loc' => $this->url->absolute('/c/' . rawurlencode($c['id'])), 'lastmod' => self::latest($this->catalog->filter(['category' => [$c['id']]]))];
            }
        }
        $tags = IndexPolicy::landingTags($this->catalog);
        if ($tags !== []) {
            $out[] = ['loc' => $this->url->absolute('/t'), 'lastmod' => self::latest($all)];
            $names = array_keys($tags);
            sort($names, SORT_STRING);
            foreach ($names as $t) {
                $out[] = ['loc' => $this->url->absolute('/t/' . rawurlencode((string) $t)), 'lastmod' => self::latest($this->catalog->filter(['tag' => [(string) $t]]))];
            }
        }
        foreach ($all as $d) {
            $out[] = ['loc' => $this->url->absolute('/d/' . rawurlencode((string) $d['slug'])), 'lastmod' => self::lastmod($d)];
        }

        return $out;
    }

    public function render(): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($this->entries() as $e) {
            $x .= '  <url><loc>' . self::xml($e['loc']) . '</loc>'
                . ($e['lastmod'] !== null ? '<lastmod>' . $e['lastmod'] . '</lastmod>' : '')
                . "</url>\n";
        }

        return $x . "</urlset>\n";
    }

    public static function xml(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}

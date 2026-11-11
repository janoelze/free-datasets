<?php

declare(strict_types=1);

namespace Datasets\Seo;

use Datasets\Catalog;
use Datasets\Url;
use Datasets\Web\View;

/**
 * Atom 1.0 feed (RFC 4287) of the most recently added datasets, newest first. Entry dates are
 * the record's `added` date (midnight UTC), so re-verifying a record does not resurface it in
 * feed readers.
 */
final class Feed
{
    public const LIMIT = 50;

    public function __construct(private readonly Catalog $catalog, private readonly Url $url)
    {
    }

    public function render(): string
    {
        $items = array_slice($this->catalog->sort($this->catalog->all(), '-added'), 0, self::LIMIT);
        $self = $this->url->absolute('/feed.xml');
        $home = $this->url->absolute('/');
        $updated = $items === [] ? '1970-01-01' : max(array_map(static fn (array $d): string => (string) $d['added'], $items));
        $x = static fn (string $s): string => Sitemap::xml($s);
        $categories = array_column($this->catalog->categories(), 'name', 'id');

        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="en">' . "\n"
            . '  <title>' . $x(Meta::SITE . ' — newly added datasets') . "</title>\n"
            . '  <subtitle>' . $x('Free datasets with free, open REST APIs, newest first.') . "</subtitle>\n"
            . '  <id>' . $x($self) . "</id>\n"
            . '  <link rel="self" type="application/atom+xml" href="' . $x($self) . '"/>' . "\n"
            . '  <link rel="alternate" type="text/html" href="' . $x($home) . '"/>' . "\n"
            . '  <updated>' . self::date($updated) . "</updated>\n"
            . '  <author><name>' . $x(Meta::SITE) . '</name><uri>' . $x($home) . "</uri></author>\n"
            . '  <icon>' . $x($this->url->absolute('/assets/apple-touch-icon.png')) . "</icon>\n";
        foreach ($items as $d) {
            $page = $this->url->absolute('/d/' . rawurlencode((string) $d['slug']));
            $auth = View::label('auth', (string) $d['api']['auth']['type']);
            $summary = $d['summary'] . ' — ' . $auth . ', ' . ($categories[$d['category']] ?? $d['category']) . '.';
            $out .= "  <entry>\n"
                . '    <title>' . $x((string) $d['name']) . "</title>\n"
                . '    <id>' . $x($page) . "</id>\n"
                . '    <link rel="alternate" type="text/html" href="' . $x($page) . '"/>' . "\n"
                . '    <link rel="related" type="application/json" href="' . $x($this->url->absolute('/api/v1/datasets/' . rawurlencode((string) $d['slug']))) . '"/>' . "\n"
                . '    <published>' . self::date((string) $d['added']) . "</published>\n"
                . '    <updated>' . self::date((string) $d['added']) . "</updated>\n"
                . '    <category term="' . $x((string) $d['category']) . '" label="' . $x($categories[$d['category']] ?? (string) $d['category']) . '"/>' . "\n"
                . '    <summary>' . $x($summary) . "</summary>\n"
                . "  </entry>\n";
        }

        return $out . "</feed>\n";
    }

    private static function date(string $ymd): string
    {
        return $ymd . 'T00:00:00Z';
    }
}

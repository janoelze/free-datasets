<?php

declare(strict_types=1);

namespace Datasets\Seo;

use Datasets\Web\View;

/**
 * <title> and meta description text, generated from record fields so every indexable page gets
 * a unique, specific, front-loaded pair. Titles put the dataset/category/tag first and add the
 * site name only while the whole title stays short enough to show in a result; descriptions are
 * cut at a word boundary at DESCRIPTION_MAX characters.
 */
final class Meta
{
    public const SITE = 'Free Dataset APIs';

    public const SUFFIX = ' | ' . self::SITE;

    /** Titles longer than this (with the suffix) drop the suffix; Google shows roughly 60 characters. */
    public const TITLE_BUDGET = 70;

    public const DESCRIPTION_MAX = 160;

    /** Full title: "<base> | Free Dataset APIs" when that fits the budget, otherwise just <base>. */
    public static function withSite(string $base): string
    {
        return mb_strlen($base . self::SUFFIX) <= self::TITLE_BUDGET ? $base . self::SUFFIX : $base;
    }

    /**
     * "Open-Meteo Forecast API — free weather & climate data, no key".
     * Names that already say "API"/"APIs" are not repeated; when the title runs past the budget
     * a parenthetical in the name goes first, then the category.
     *
     * @param array<string, mixed>      $d
     * @param array<string, mixed>|null $category
     */
    public static function datasetTitle(array $d, ?array $category): string
    {
        $name = (string) $d['name'];
        $short = trim((string) preg_replace('/\s*\([^)]*\)\s*/', ' ', $name));
        $short = $short === '' ? $name : $short;
        $long = ' — free ' . mb_strtolower((string) ($category['name'] ?? 'open')) . ' data, ' . self::authShort($d);
        $brief = ' — free, ' . self::authShort($d);
        // Most specific first; drop a parenthetical, then the category, until it fits.
        $candidates = [self::apiName($name) . $long, self::apiName($short) . $long, self::apiName($name) . $brief, self::apiName($short) . $brief];
        foreach ($candidates as $c) {
            if (mb_strlen($c) <= self::TITLE_BUDGET) {
                return self::withSite($c);
            }
        }

        return end($candidates);
    }

    /** The social-card title: the dataset name plus what it is, without the site suffix. */
    public static function datasetShareTitle(array $d, ?array $category): string
    {
        return self::apiName((string) $d['name']) . ' — free ' . mb_strtolower((string) ($category['name'] ?? 'open')) . ' data';
    }

    /**
     * Summary first, then the facts people filter by: "… No key, CORS enabled, CC-BY-4.0."
     *
     * @param array<string, mixed> $d
     */
    public static function datasetDescription(array $d): string
    {
        $summary = rtrim(trim((string) $d['summary']), '.') . '.';
        $license = $d['data']['license'] ?? [];
        $facts = [ucfirst(self::authShort($d))];
        if (View::browserUsable($d)) {
            $facts[] = 'CORS enabled';
        }
        $lic = (string) ($license['spdx'] ?? $license['name'] ?? '');
        if ($lic !== '' && mb_strlen($lic) <= 24) {
            $facts[] = $lic;
        }
        for ($n = count($facts); $n >= 1; $n--) {
            $s = $summary . ' ' . implode(', ', array_slice($facts, 0, $n)) . '.';
            if (mb_strlen($s) <= self::DESCRIPTION_MAX) {
                return $s;
            }
        }

        return self::truncate($summary, self::DESCRIPTION_MAX);
    }

    /** @param array<string, mixed> $cat category with count */
    public static function categoryTitle(array $cat): string
    {
        return self::withSite(sprintf('Free %s APIs — %d %s', $cat['name'], $cat['count'], $cat['count'] === 1 ? 'dataset' : 'datasets'));
    }

    /** @param array<string, mixed> $cat category with count */
    public static function categoryDescription(array $cat, int $keyless): string
    {
        $intro = rtrim((string) $cat['description'], '.') . '.';
        $s = sprintf('%s %d free %s APIs, %d without a key — with auth, CORS, rate limits, license and a tested request for each.',
            $intro, $cat['count'], mb_strtolower((string) $cat['name']), $keyless);
        if (mb_strlen($s) > self::DESCRIPTION_MAX) {
            $s = sprintf('%s %d free %s APIs, %d without a key.', $intro, $cat['count'], mb_strtolower((string) $cat['name']), $keyless);
        }

        return self::truncate($s, self::DESCRIPTION_MAX);
    }

    public static function tagLabel(string $tag): string
    {
        return str_replace('-', ' ', $tag);
    }

    public static function tagTitle(string $tag, int $count): string
    {
        return self::withSite(sprintf('Free %s APIs — %d datasets tagged “%s”', self::tagLabel($tag), $count, $tag));
    }

    /** @param list<string> $names a few dataset names, most relevant first */
    public static function tagDescription(string $tag, int $count, array $names): string
    {
        $lead = sprintf('%d free datasets with open APIs tagged “%s”', $count, $tag);
        $s = $lead . ($names !== [] ? ': ' . implode(', ', $names) . ($count > count($names) ? ' and more' : '') : '') . '.';
        while (mb_strlen($s) > self::DESCRIPTION_MAX && $names !== []) {
            array_pop($names);
            $s = $lead . ($names !== [] ? ': ' . implode(', ', $names) . ' and more' : '') . '.';
        }

        return self::truncate($s, self::DESCRIPTION_MAX);
    }

    /** Cut at a word boundary so the result (with "…") is at most $max characters. */
    public static function truncate(string $s, int $max): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        $cut = mb_substr($s, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $max * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " ,;:—-") . '…';
    }

    private static function apiName(string $name): string
    {
        return preg_match('/(APIs?|Api)\b/', $name) === 1 ? $name : $name . ' API';
    }

    /** "no key" | "free API key" | "OAuth" */
    private static function authShort(array $d): string
    {
        return match ($d['api']['auth']['type'] ?? '') {
            'none' => 'no key',
            'api_key' => 'free API key',
            'oauth2' => 'OAuth',
            default => 'free',
        };
    }
}

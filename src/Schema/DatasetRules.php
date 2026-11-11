<?php

declare(strict_types=1);

namespace Datasets\Schema;

/**
 * Semantic rules that JSON Schema cannot express. Run after schema validation, on the
 * dataset decoded as an associative array.
 *
 *  - slug equals the file name (without .json)
 *  - category is an id from schema/categories.json
 *  - every `related` slug exists in the catalogue (only checked when the slug set is given)
 *  - api.auth.type = api_key requires api.auth.key_in and links.signup
 *  - api.auth.key_optional is only allowed with api.auth.type = none
 *  - verification.last_verified and added are not in the future
 *  - all URLs (links.*, example.url, api.base_url, provider.url, data.license.url,
 *    absolute endpoint paths) use https; plain http is allowed nowhere
 */
final class DatasetRules
{
    /** @var array<string, true> */
    private array $categories;

    private string $today;

    /**
     * @param list<string> $categoryIds
     * @param string|null  $today YYYY-MM-DD; defaults to today's date in the earliest-ahead
     *                            time zone (UTC+14) so no contributor is flagged for "the future"
     */
    public function __construct(array $categoryIds, ?string $today = null)
    {
        $this->categories = array_fill_keys($categoryIds, true);
        $this->today = $today ?? gmdate('Y-m-d', time() + 14 * 3600);
    }

    /**
     * @param array<string, mixed>   $d          dataset (associative array)
     * @param string|null            $fileSlug   file name without .json; null skips the check
     * @param array<string, mixed>|null $knownSlugs set of catalogue slugs (keys); null skips the related check
     * @return list<ValidationError>
     */
    public function check(array $d, ?string $fileSlug = null, ?array $knownSlugs = null): array
    {
        $errors = [];

        if ($fileSlug !== null && ($d['slug'] ?? null) !== $fileSlug) {
            $errors[] = new ValidationError('/slug', 'x-slug-filename', sprintf('must equal the file name "%s" (got %s)', $fileSlug, json_encode($d['slug'] ?? null)));
        }

        if (isset($d['category']) && is_string($d['category']) && !isset($this->categories[$d['category']])) {
            $errors[] = new ValidationError('/category', 'x-category', sprintf('unknown category "%s" (see schema/categories.json)', $d['category']));
        }

        if ($knownSlugs !== null && is_array($d['related'] ?? null)) {
            foreach ($d['related'] as $i => $slug) {
                if (!is_string($slug)) {
                    continue;
                }
                if ($slug === ($d['slug'] ?? null)) {
                    $errors[] = new ValidationError("/related/$i", 'x-related', 'must not reference the dataset itself');
                } elseif (!array_key_exists($slug, $knownSlugs)) {
                    $errors[] = new ValidationError("/related/$i", 'x-related', sprintf('no dataset with slug "%s" exists', $slug));
                }
            }
        }

        $auth = is_array($d['api']['auth'] ?? null) ? $d['api']['auth'] : [];
        $authType = $auth['type'] ?? null;
        if ($authType === 'api_key') {
            if (!isset($auth['key_in'])) {
                $errors[] = new ValidationError('/api/auth/key_in', 'x-auth', 'required when auth.type is "api_key"');
            }
            if (!isset($d['links']['signup'])) {
                $errors[] = new ValidationError('/links/signup', 'x-auth', 'required when auth.type is "api_key"');
            }
        }
        if (array_key_exists('key_optional', $auth) && $authType !== 'none') {
            $errors[] = new ValidationError('/api/auth/key_optional', 'x-auth', 'only allowed when auth.type is "none"');
        }

        foreach (['/verification/last_verified' => $d['verification']['last_verified'] ?? null, '/added' => $d['added'] ?? null] as $ptr => $date) {
            if (is_string($date) && Validator::isDate($date) && $date > $this->today) {
                $errors[] = new ValidationError($ptr, 'x-date', "must not be in the future (today is {$this->today})");
            }
        }

        foreach ($this->urls($d) as $ptr => $url) {
            if (is_string($url) && preg_match('~^https?://~i', $url) === 1 && stripos($url, 'https://') !== 0) {
                $errors[] = new ValidationError($ptr, 'x-https', 'must use https (plain http is not allowed)');
            }
        }

        return $errors;
    }

    /** @return array<string, mixed> JSON pointer => URL value */
    private function urls(array $d): array
    {
        $out = [];
        foreach (is_array($d['links'] ?? null) ? $d['links'] : [] as $key => $url) {
            $out[ValidationError::child('/links', $key)] = $url;
        }
        $out['/example/url'] = $d['example']['url'] ?? null;
        $out['/api/base_url'] = $d['api']['base_url'] ?? null;
        $out['/provider/url'] = $d['provider']['url'] ?? null;
        $out['/data/license/url'] = $d['data']['license']['url'] ?? null;
        foreach (is_array($d['endpoints'] ?? null) ? $d['endpoints'] : [] as $i => $ep) {
            $out["/endpoints/$i/path"] = is_array($ep) ? ($ep['path'] ?? null) : null;
        }

        return $out;
    }
}

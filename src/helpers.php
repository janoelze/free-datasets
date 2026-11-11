<?php

declare(strict_types=1);

/*
 * Global template helpers. All are base-path aware via Datasets\Url::current(),
 * which the App sets for every request.
 */

use Datasets\Url;

if (!function_exists('url')) {
    /**
     * Root-relative URL for an app path: url('/d/open-meteo') -> "/free-datasets/d/open-meteo".
     *
     * @param array<string, scalar|list<scalar>|null> $query
     */
    function url(string $path = '/', array $query = []): string
    {
        return Url::current()->path($path, $query);
    }
}

if (!function_exists('abs_url')) {
    /**
     * Absolute public URL: abs_url('/d/x') -> "https://endtime-instruments.org/free-datasets/d/x".
     *
     * @param array<string, scalar|list<scalar>|null> $query
     */
    function abs_url(string $path = '/', array $query = []): string
    {
        return Url::current()->absolute($path, $query);
    }
}

if (!function_exists('asset')) {
    /** URL of a file in public/ with ?v=<mtime> cache-busting: asset('assets/style.css'). */
    function asset(string $file): string
    {
        return Url::current()->asset($file);
    }
}

if (!function_exists('e')) {
    /** HTML-escape a value for text or attribute context. */
    function e(mixed $value): string
    {
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

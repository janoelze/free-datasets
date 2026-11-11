<?php

declare(strict_types=1);

namespace Datasets;

/** JSON encoding helpers shared by the API and the CLI tools. */
final class Json
{
    public const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /** Pretty-printed (4-space), unescaped slashes and unicode. */
    public static function encode(mixed $value): string
    {
        return json_encode($value, self::FLAGS);
    }

    /**
     * Encode for files in datasets/: 2-space indent, unescaped slashes/unicode, trailing newline.
     * Pass values decoded with json_decode($json, false) to keep {} vs [] and key order.
     */
    public static function encodeFile(mixed $value): string
    {
        $json = self::encode($value);
        // json_encode indents with 4 spaces; strings never contain raw newlines, so this is safe.
        $json = (string) preg_replace_callback('/^( +)/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json);

        return $json . "\n";
    }

    /**
     * Turn empty PHP arrays that the schema says are objects back into objects, so that
     * data decoded with assoc=true re-encodes {} as {} instead of [].
     *
     * @param array<string, mixed>|bool $schema
     */
    public static function objectify(mixed $value, array|bool $schema): mixed
    {
        if (!is_array($value) || !is_array($schema)) {
            return $value;
        }
        $isObjectSchema = ($schema['type'] ?? null) === 'object' || isset($schema['properties']);
        if ($value === []) {
            return $isObjectSchema ? new \stdClass() : [];
        }
        if (array_is_list($value) && !$isObjectSchema) {
            $items = $schema['items'] ?? [];
            foreach ($value as $i => $v) {
                $value[$i] = self::objectify($v, $items);
            }

            return $value;
        }
        foreach ($value as $k => $v) {
            $sub = $schema['properties'][$k] ?? (is_array($schema['additionalProperties'] ?? null) ? $schema['additionalProperties'] : []);
            $value[$k] = self::objectify($v, $sub);
        }

        return $value;
    }
}

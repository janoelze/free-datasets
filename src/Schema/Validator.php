<?php

declare(strict_types=1);

namespace Datasets\Schema;

/**
 * Minimal JSON Schema (2020-12) validator implementing exactly the keyword subset used by
 * schema/dataset.schema.json:
 *
 *   type, enum, const, required, properties, additionalProperties (bool|schema), items,
 *   minItems, maxItems, uniqueItems, minLength, maxLength (code points), pattern,
 *   format (uri, date), minimum, maximum, dependentRequired
 *
 * Annotation keywords ($schema, $id, $comment, title, description, examples, default,
 * deprecated, readOnly, writeOnly) are ignored. Any other keyword makes the constructor throw
 * UnsupportedSchemaException, so the schema cannot silently outgrow the validator.
 *
 * Instances should be decoded with json_decode($json, false) so that {} and [] stay distinct;
 * associative PHP arrays are accepted too (non-list arrays count as objects, [] as either).
 */
final class Validator
{
    private const ANNOTATIONS = [
        '$schema', '$id', '$comment', 'title', 'description', 'examples', 'default',
        'deprecated', 'readOnly', 'writeOnly',
    ];

    private const KEYWORDS = [
        'type', 'enum', 'const', 'required', 'properties', 'additionalProperties', 'items',
        'minItems', 'maxItems', 'uniqueItems', 'minLength', 'maxLength', 'pattern', 'format',
        'minimum', 'maximum', 'dependentRequired',
    ];

    private const TYPES = ['object', 'array', 'string', 'number', 'integer', 'boolean', 'null'];

    private const FORMATS = ['uri', 'date'];

    /** @var array<string, string> compiled PCRE patterns keyed by the schema pattern */
    private array $regexCache = [];

    /** @param array<string, mixed>|bool $schema schema decoded with json_decode($json, true) */
    public function __construct(private readonly array|bool $schema)
    {
        $this->checkSchema($schema, '#');
    }

    public static function fromFile(string $path): self
    {
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("Cannot read schema file $path");
        }
        $schema = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($schema) && !is_bool($schema)) {
            throw new UnsupportedSchemaException("Schema in $path is not an object");
        }

        return new self($schema);
    }

    /** @return array<string, mixed>|bool */
    public function schema(): array|bool
    {
        return $this->schema;
    }

    /** @return list<ValidationError> */
    public function validate(mixed $instance): array
    {
        $errors = [];
        $this->validateNode($instance, $this->schema, '', $errors);

        return $errors;
    }

    /**
     * Decode and validate a JSON document. Syntax errors are reported as a root error.
     *
     * @return list<ValidationError>
     */
    public function validateJson(string $json): array
    {
        try {
            $instance = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [new ValidationError('', 'json', 'invalid JSON: ' . $e->getMessage())];
        }

        return $this->validate($instance);
    }

    // ---------------------------------------------------------------- schema checking

    private function checkSchema(mixed $schema, string $where): void
    {
        if (is_bool($schema)) {
            return;
        }
        if (!is_array($schema) || ($schema !== [] && array_is_list($schema))) {
            throw new UnsupportedSchemaException("$where: a schema must be an object or boolean");
        }
        foreach ($schema as $keyword => $value) {
            $keyword = (string) $keyword;
            if (in_array($keyword, self::ANNOTATIONS, true)) {
                continue;
            }
            if (!in_array($keyword, self::KEYWORDS, true)) {
                throw new UnsupportedSchemaException("$where: unsupported JSON Schema keyword \"$keyword\"");
            }
            $at = "$where/$keyword";
            switch ($keyword) {
                case 'type':
                    $types = is_array($value) ? $value : [$value];
                    foreach ($types as $t) {
                        if (!is_string($t) || !in_array($t, self::TYPES, true)) {
                            throw new UnsupportedSchemaException("$at: unknown type " . json_encode($t));
                        }
                    }
                    break;
                case 'enum':
                    if (!is_array($value) || !array_is_list($value) || $value === []) {
                        throw new UnsupportedSchemaException("$at: enum must be a non-empty array");
                    }
                    break;
                case 'required':
                    if (!is_array($value) || !array_is_list($value)) {
                        throw new UnsupportedSchemaException("$at: required must be an array of strings");
                    }
                    break;
                case 'properties':
                    if (!is_array($value)) {
                        throw new UnsupportedSchemaException("$at: properties must be an object");
                    }
                    foreach ($value as $name => $sub) {
                        $this->checkSchema($sub, "$at/$name");
                    }
                    break;
                case 'additionalProperties':
                    $this->checkSchema($value, $at);
                    break;
                case 'items':
                    if (is_array($value) && $value !== [] && array_is_list($value)) {
                        throw new UnsupportedSchemaException("$at: tuple-form items is not supported (use prefixItems)");
                    }
                    $this->checkSchema($value, $at);
                    break;
                case 'minItems':
                case 'maxItems':
                case 'minLength':
                case 'maxLength':
                    if (!is_int($value) || $value < 0) {
                        throw new UnsupportedSchemaException("$at: must be a non-negative integer");
                    }
                    break;
                case 'uniqueItems':
                    if (!is_bool($value)) {
                        throw new UnsupportedSchemaException("$at: must be a boolean");
                    }
                    break;
                case 'minimum':
                case 'maximum':
                    if (!is_int($value) && !is_float($value)) {
                        throw new UnsupportedSchemaException("$at: must be a number");
                    }
                    break;
                case 'pattern':
                    if (!is_string($value) || @preg_match($this->regex($value), '') === false) {
                        throw new UnsupportedSchemaException("$at: invalid pattern");
                    }
                    break;
                case 'format':
                    if (!is_string($value) || !in_array($value, self::FORMATS, true)) {
                        throw new UnsupportedSchemaException("$at: unsupported format " . json_encode($value));
                    }
                    break;
                case 'dependentRequired':
                    if (!is_array($value)) {
                        throw new UnsupportedSchemaException("$at: must be an object");
                    }
                    foreach ($value as $deps) {
                        if (!is_array($deps) || !array_is_list($deps)) {
                            throw new UnsupportedSchemaException("$at: values must be arrays of strings");
                        }
                    }
                    break;
            }
        }
    }

    // ---------------------------------------------------------------- validation

    /** @param list<ValidationError> $errors */
    private function validateNode(mixed $value, array|bool $schema, string $ptr, array &$errors): void
    {
        if ($schema === true || $schema === []) {
            return;
        }
        if ($schema === false) {
            $errors[] = new ValidationError($ptr, 'false', 'no value is allowed here');

            return;
        }

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $ok = false;
            foreach ($types as $t) {
                if ($this->isType($value, $t)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $errors[] = new ValidationError($ptr, 'type', sprintf('must be of type %s, got %s', implode('|', $types), self::typeOf($value)));

                return; // further keywords would only produce noise
            }
        }

        if (array_key_exists('const', $schema) && !self::equal($value, $schema['const'])) {
            $errors[] = new ValidationError($ptr, 'const', 'must equal ' . self::show($schema['const']));
        }

        if (isset($schema['enum'])) {
            $found = false;
            foreach ($schema['enum'] as $candidate) {
                if (self::equal($value, $candidate)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $allowed = implode(', ', array_map([self::class, 'show'], $schema['enum']));
                $errors[] = new ValidationError($ptr, 'enum', 'must be one of: ' . $allowed . '; got ' . self::show($value));
            }
        }

        if (is_string($value)) {
            $this->validateString($value, $schema, $ptr, $errors);
        } elseif (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = new ValidationError($ptr, 'minimum', "must be >= {$schema['minimum']}");
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = new ValidationError($ptr, 'maximum', "must be <= {$schema['maximum']}");
            }
        }

        if (self::isObjectValue($value) || ($value === [] && $this->wantsObject($schema))) {
            $this->validateObject(self::props($value), $schema, $ptr, $errors);
        } elseif (is_array($value)) {
            $this->validateArray($value, $schema, $ptr, $errors);
        }
    }

    /** @param list<ValidationError> $errors */
    private function validateString(string $value, array $schema, string $ptr, array &$errors): void
    {
        if (isset($schema['minLength']) || isset($schema['maxLength'])) {
            $len = self::length($value);
            if (isset($schema['minLength']) && $len < $schema['minLength']) {
                $errors[] = new ValidationError($ptr, 'minLength', "must be at least {$schema['minLength']} characters (has $len)");
            }
            if (isset($schema['maxLength']) && $len > $schema['maxLength']) {
                $errors[] = new ValidationError($ptr, 'maxLength', "must be at most {$schema['maxLength']} characters (has $len)");
            }
        }
        if (isset($schema['pattern']) && preg_match($this->regex($schema['pattern']), $value) !== 1) {
            $errors[] = new ValidationError($ptr, 'pattern', 'must match pattern ' . $schema['pattern']);
        }
        if (isset($schema['format'])) {
            $ok = match ($schema['format']) {
                'uri' => self::isUri($value),
                'date' => self::isDate($value),
                default => true,
            };
            if (!$ok) {
                $errors[] = new ValidationError($ptr, 'format', 'must be a valid ' . $schema['format'] . ($schema['format'] === 'date' ? ' (YYYY-MM-DD)' : ''));
            }
        }
    }

    /**
     * @param list<mixed> $items
     * @param list<ValidationError> $errors
     */
    private function validateArray(array $items, array $schema, string $ptr, array &$errors): void
    {
        $n = count($items);
        if (isset($schema['minItems']) && $n < $schema['minItems']) {
            $errors[] = new ValidationError($ptr, 'minItems', "must have at least {$schema['minItems']} items (has $n)");
        }
        if (isset($schema['maxItems']) && $n > $schema['maxItems']) {
            $errors[] = new ValidationError($ptr, 'maxItems', "must have at most {$schema['maxItems']} items (has $n)");
        }
        if (($schema['uniqueItems'] ?? false) === true) {
            $seen = [];
            foreach ($items as $i => $item) {
                $key = self::canonical($item);
                if (isset($seen[$key])) {
                    $errors[] = new ValidationError(ValidationError::child($ptr, $i), 'uniqueItems', "duplicates item {$seen[$key]}");
                } else {
                    $seen[$key] = $i;
                }
            }
        }
        if (array_key_exists('items', $schema)) {
            foreach ($items as $i => $item) {
                $this->validateNode($item, $schema['items'], ValidationError::child($ptr, $i), $errors);
            }
        }
    }

    /**
     * @param array<string, mixed> $props
     * @param list<ValidationError> $errors
     */
    private function validateObject(array $props, array $schema, string $ptr, array &$errors): void
    {
        foreach ($schema['required'] ?? [] as $name) {
            if (!array_key_exists($name, $props)) {
                $errors[] = new ValidationError(ValidationError::child($ptr, $name), 'required', 'required property is missing');
            }
        }
        foreach ($schema['dependentRequired'] ?? [] as $trigger => $deps) {
            if (!array_key_exists((string) $trigger, $props)) {
                continue;
            }
            foreach ($deps as $dep) {
                if (!array_key_exists($dep, $props)) {
                    $errors[] = new ValidationError(ValidationError::child($ptr, $dep), 'dependentRequired', "required when \"$trigger\" is present");
                }
            }
        }
        $defined = $schema['properties'] ?? [];
        foreach ($props as $name => $value) {
            $name = (string) $name;
            $child = ValidationError::child($ptr, $name);
            if (array_key_exists($name, $defined)) {
                $this->validateNode($value, $defined[$name], $child, $errors);
            } elseif (array_key_exists('additionalProperties', $schema)) {
                if ($schema['additionalProperties'] === false) {
                    $errors[] = new ValidationError($child, 'additionalProperties', 'property is not allowed');
                } else {
                    $this->validateNode($value, $schema['additionalProperties'], $child, $errors);
                }
            }
        }
    }

    // ---------------------------------------------------------------- helpers

    private function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => self::isObjectValue($value) || $value === [],
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }

    private function wantsObject(array $schema): bool
    {
        return in_array('object', (array) ($schema['type'] ?? []), true)
            || isset($schema['properties']) || isset($schema['required']) || isset($schema['additionalProperties']);
    }

    private static function isObjectValue(mixed $value): bool
    {
        return $value instanceof \stdClass || (is_array($value) && $value !== [] && !array_is_list($value));
    }

    /** @return array<string, mixed> */
    private static function props(mixed $value): array
    {
        return $value instanceof \stdClass ? get_object_vars($value) : (array) $value;
    }

    private static function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            self::isObjectValue($value) => 'object',
            default => 'array',
        };
    }

    private function regex(string $pattern): string
    {
        // \x01 as delimiter: it can never appear in a schema pattern by accident.
        return $this->regexCache[$pattern] ??= "\x01" . $pattern . "\x01u";
    }

    /** Length in Unicode code points, as JSON Schema defines it. */
    public static function length(string $s): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($s, 'UTF-8');
        }
        $n = preg_match_all('/./su', $s);

        return $n === false ? strlen($s) : $n;
    }

    /** Absolute URI per RFC 3986 (scheme required, no whitespace/illegal characters; http(s) needs a host). */
    public static function isUri(string $s): bool
    {
        if (preg_match('~^[A-Za-z][A-Za-z0-9+.\-]*:[^\s<>"{}|\\\\^`\x00-\x1f\x7f]*$~', $s) !== 1) {
            return false;
        }
        $scheme = strtolower((string) strstr($s, ':', true));
        if ($scheme === 'http' || $scheme === 'https') {
            $parts = parse_url($s);

            return is_array($parts) && isset($parts['host']) && $parts['host'] !== '' && str_starts_with(substr($s, strlen($scheme) + 1), '//');
        }

        return true;
    }

    /** RFC 3339 full-date: YYYY-MM-DD and a real calendar date. */
    public static function isDate(string $s): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function equal(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }

        return self::canonical($a) === self::canonical($b);
    }

    /** Order-independent canonical encoding for deep equality. */
    private static function canonical(mixed $value): string
    {
        if (self::isObjectValue($value)) {
            $props = self::props($value);
            ksort($props, SORT_STRING);

            return '{' . implode(',', array_map(
                static fn ($k, $v) => json_encode((string) $k) . ':' . self::canonical($v),
                array_keys($props),
                $props
            )) . '}';
        }
        if (is_array($value)) {
            return '[' . implode(',', array_map([self::class, 'canonical'], $value)) . ']';
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function show(mixed $value): string
    {
        $s = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return strlen($s) > 80 ? substr($s, 0, 77) . '...' : $s;
    }
}

<?php

declare(strict_types=1);

namespace Datasets\Schema;

/**
 * Validates dataset files: JSON syntax, the JSON Schema, then DatasetRules.
 * Used by bin/validate.php, bin/build.php, Catalog (when loading from files) and the tests.
 */
final class Linter
{
    private Validator $validator;

    private DatasetRules $rules;

    public function __construct(Validator $validator, DatasetRules $rules)
    {
        $this->validator = $validator;
        $this->rules = $rules;
    }

    /** Linter wired to schema/dataset.schema.json and schema/categories.json in $schemaDir. */
    public static function fromSchemaDir(string $schemaDir, ?string $today = null): self
    {
        $categories = json_decode((string) file_get_contents($schemaDir . '/categories.json'), true, 512, JSON_THROW_ON_ERROR);

        return new self(
            Validator::fromFile($schemaDir . '/dataset.schema.json'),
            new DatasetRules(array_column($categories, 'id'), $today),
        );
    }

    /** @return list<string> absolute paths of *.json files in $dir, sorted */
    public static function datasetFiles(string $dir): array
    {
        $files = glob(rtrim($dir, '/') . '/*.json') ?: [];
        $files = array_values(array_filter($files, 'is_file'));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Lint every *.json file in a directory. `related` is checked against the slugs of all
     * files present (by file name).
     *
     * @return array<string, array{errors: list<ValidationError>, data: array<string, mixed>|null}> keyed by path
     */
    public function lintDirectory(string $dir, bool $checkRelated = true): array
    {
        $files = self::datasetFiles($dir);
        $known = null;
        if ($checkRelated) {
            $known = [];
            foreach ($files as $f) {
                $known[basename($f, '.json')] = true;
            }
        }
        $out = [];
        foreach ($files as $f) {
            $out[$f] = $this->lintFile($f, $known);
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $knownSlugs slugs as keys; null skips the related check
     * @return array{errors: list<ValidationError>, data: array<string, mixed>|null}
     */
    public function lintFile(string $path, ?array $knownSlugs = null): array
    {
        $json = @file_get_contents($path);
        if ($json === false) {
            return ['errors' => [new ValidationError('', 'io', 'cannot read file')], 'data' => null];
        }

        return $this->lintJson($json, basename($path, '.json'), $knownSlugs);
    }

    /**
     * @param array<string, mixed>|null $knownSlugs
     * @return array{errors: list<ValidationError>, data: array<string, mixed>|null}
     */
    public function lintJson(string $json, ?string $fileSlug, ?array $knownSlugs = null): array
    {
        try {
            $instance = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['errors' => [new ValidationError('', 'json', 'invalid JSON: ' . $e->getMessage())], 'data' => null];
        }
        $errors = $this->validator->validate($instance);
        $data = null;
        if (is_object($instance)) {
            $data = json_decode($json, true);
            // Schema errors first; semantic rules still run so a single pass shows everything.
            $errors = array_merge($errors, $this->rules->check($data, $fileSlug, $knownSlugs));
        }

        return ['errors' => $errors, 'data' => $errors === [] ? $data : null];
    }
}

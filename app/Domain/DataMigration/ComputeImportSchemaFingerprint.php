<?php

namespace App\Domain\DataMigration;

use JsonException;
use stdClass;

/**
 * @phpstan-type JsonValue null|bool|int|float|string|list<mixed>|array<string, mixed>
 * @phpstan-type FileStat array<int|string, int>
 */
final class ComputeImportSchemaFingerprint
{
    public const MAX_BYTES = 262144;

    public const MAX_DEPTH = 32;

    public const MAX_COLUMNS = 1000;

    public const FINGERPRINT_ALGORITHM = 'mapilio-schema-fingerprint-v1';

    public const IDENTIFIER_PATTERN = '^[a-z_][a-z0-9_]*$';

    public const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'staging'];

    public const ENGINES = ['postgresql', 'sqlite'];

    /** @var list<string> */
    public const TOP_KEYS = ['schema_version', 'fingerprint_algorithm', 'engine', 'schema', 'table', 'columns'];

    /** @var list<string> */
    public const COLUMN_KEYS = ['position', 'name', 'type_schema', 'type_name', 'nullable', 'character_length', 'numeric_precision', 'numeric_scale', 'datetime_precision'];

    public function compute(?string $descriptorPath): object
    {
        $environment = (string) config('app.env', app()->environment());
        if (! in_array($environment, self::ALLOWED_ENVIRONMENTS, true)) {
            throw new ImportSchemaFingerprintException('PRODUCTION_BLOCKED');
        }

        $json = $this->readDescriptor($descriptorPath);
        if (! mb_check_encoding($json, 'UTF-8')) {
            throw new ImportSchemaFingerprintException('DESCRIPTOR_INVALID_JSON');
        }
        try {
            $descriptor = json_decode($json, false, self::MAX_DEPTH * 2, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new ImportSchemaFingerprintException('DESCRIPTOR_INVALID_JSON');
        }
        if (! $descriptor instanceof stdClass || $this->depth($descriptor) > self::MAX_DEPTH) {
            throw new ImportSchemaFingerprintException('DESCRIPTOR_SCHEMA_INVALID');
        }

        try {
            $canonical = $this->canonicalize($descriptor);
            $digest = hash('sha256', self::FINGERPRINT_ALGORITHM."\0".$canonical);
        } catch (ImportSchemaFingerprintException $exception) {
            throw $exception;
        } catch (JsonException) {
            throw new ImportSchemaFingerprintException('CANONICALIZATION_FAILED');
        }

        return (object) ['fingerprint' => $digest, 'checks' => ['SCHEMA_DESCRIPTOR', 'CANONICALIZATION', 'SCHEMA_FINGERPRINT']];
    }

    private function readDescriptor(?string $path): string
    {
        if (! is_string($path) || $path === '' || str_contains($path, "\0")) {
            throw new ImportSchemaFingerprintException('DESCRIPTOR_UNREADABLE');
        }
        $pathStat = @lstat($path);
        if ($pathStat === false || ! $this->regular($pathStat['mode'])) {
            throw new ImportSchemaFingerprintException('DESCRIPTOR_UNREADABLE');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false || ! is_resource($handle)) {
            throw new ImportSchemaFingerprintException('DESCRIPTOR_UNREADABLE');
        }
        try {
            $openedStat = fstat($handle);
            if ($openedStat === false || ! $this->sameFile($pathStat, $openedStat) || ! $this->regular($openedStat['mode'])) {
                throw new ImportSchemaFingerprintException('DESCRIPTOR_UNREADABLE');
            }
            if ($openedStat['size'] > self::MAX_BYTES) {
                throw new ImportSchemaFingerprintException('DESCRIPTOR_TOO_LARGE');
            }
            $contents = stream_get_contents($handle, self::MAX_BYTES + 1);
            $finalStat = fstat($handle);
            if ($contents === false || $finalStat === false || ! $this->sameFile($openedStat, $finalStat) || ! $this->regular($finalStat['mode'])) {
                throw new ImportSchemaFingerprintException('DESCRIPTOR_UNREADABLE');
            }
            if (strlen($contents) > self::MAX_BYTES || $finalStat['size'] > self::MAX_BYTES) {
                throw new ImportSchemaFingerprintException('DESCRIPTOR_TOO_LARGE');
            }
            if ($finalStat['size'] !== $openedStat['size'] || strlen($contents) !== $finalStat['size']) {
                throw new ImportSchemaFingerprintException('DESCRIPTOR_UNREADABLE');
            }
            if (@rewind($handle) !== true) {
                throw new ImportSchemaFingerprintException('DESCRIPTOR_UNREADABLE');
            }
            $verifiedContents = stream_get_contents($handle, self::MAX_BYTES + 1);
            $verifiedStat = fstat($handle);
            if ($verifiedContents === false || $verifiedStat === false || ! $this->sameFile($openedStat, $verifiedStat) || ! $this->regular($verifiedStat['mode'])) {
                throw new ImportSchemaFingerprintException('DESCRIPTOR_UNREADABLE');
            }
            if (strlen($verifiedContents) > self::MAX_BYTES || $verifiedStat['size'] > self::MAX_BYTES) {
                throw new ImportSchemaFingerprintException('DESCRIPTOR_TOO_LARGE');
            }
            if ($verifiedStat['size'] !== $openedStat['size'] || strlen($verifiedContents) !== $verifiedStat['size'] || $verifiedContents !== $contents) {
                throw new ImportSchemaFingerprintException('DESCRIPTOR_UNREADABLE');
            }

            return $verifiedContents;
        } finally {
            fclose($handle);
        }
    }

    private function canonicalize(stdClass $descriptor): string
    {
        if (! is_array($descriptor->columns ?? null) || ! array_is_list($descriptor->columns)) {
            throw new ImportSchemaFingerprintException('DESCRIPTOR_SCHEMA_INVALID');
        }
        foreach ($descriptor->columns as $column) {
            if (! $column instanceof stdClass) {
                throw new ImportSchemaFingerprintException('DESCRIPTOR_SCHEMA_INVALID');
            }
        }
        $value = $this->normalize($descriptor);
        $this->keys($value, self::TOP_KEYS);
        if (! $this->schemaVersion($value['schema_version']) || $value['fingerprint_algorithm'] !== self::FINGERPRINT_ALGORITHM || ! in_array($value['engine'], self::ENGINES, true) || ! $this->identifier($value['schema']) || ! $this->identifier($value['table'])) {
            throw new ImportSchemaFingerprintException('DESCRIPTOR_SCHEMA_INVALID');
        }
        if (! is_array($value['columns']) || count($value['columns']) < 1 || count($value['columns']) > self::MAX_COLUMNS) {
            throw new ImportSchemaFingerprintException('DESCRIPTOR_SCHEMA_INVALID');
        }
        $columns = [];
        $positions = [];
        $names = [];
        foreach ($value['columns'] as $column) {
            if (! is_array($column)) {
                throw new ImportSchemaFingerprintException('DESCRIPTOR_SCHEMA_INVALID');
            }
            $this->keys($column, self::COLUMN_KEYS);
            if (! $this->integralInRange($column['position'], 1, self::MAX_COLUMNS)) {
                throw new ImportSchemaFingerprintException('DESCRIPTOR_SCHEMA_INVALID');
            }
            $column['position'] = (int) $column['position'];
            foreach (['name', 'type_schema', 'type_name'] as $key) {
                if (! $this->identifier($column[$key])) {
                    throw new ImportSchemaFingerprintException('DESCRIPTOR_SCHEMA_INVALID');
                }
            }
            if (! is_bool($column['nullable']) || in_array($column['name'], $names, true)) {
                throw new ImportSchemaFingerprintException('DESCRIPTOR_SCHEMA_INVALID');
            }
            foreach (['character_length', 'numeric_precision', 'numeric_scale', 'datetime_precision'] as $key) {
                if ($column[$key] !== null && ! $this->integralInRange($column[$key], 0, 1000000)) {
                    throw new ImportSchemaFingerprintException('DESCRIPTOR_SCHEMA_INVALID');
                }
                if ($column[$key] !== null) {
                    $column[$key] = (int) $column[$key];
                }
            }
            $positions[] = $column['position'];
            $names[] = $column['name'];
            $columns[] = $column;
        }
        sort($positions, SORT_NUMERIC);
        foreach ($positions as $index => $position) {
            if ($position !== $index + 1) {
                throw new ImportSchemaFingerprintException('DESCRIPTOR_SCHEMA_INVALID');
            }
        }
        usort($columns, fn (array $left, array $right): int => $left['position'] <=> $right['position']);
        $canonical = ['schema_version' => 1, 'fingerprint_algorithm' => self::FINGERPRINT_ALGORITHM, 'engine' => $value['engine'], 'schema' => $value['schema'], 'table' => $value['table'], 'columns' => []];
        foreach ($columns as $column) {
            $canonical['columns'][] = array_combine(self::COLUMN_KEYS, array_map(fn (string $key): mixed => $column[$key], self::COLUMN_KEYS));
        }

        return json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return JsonValue */
    private function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $result = [];
            foreach (get_object_vars($value) as $key => $child) {
                $result[$key] = $this->normalize($child);
            }

            return $result;
        }
        if (is_array($value)) {
            return array_map(fn (mixed $child): mixed => $this->normalize($child), $value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expected
     */
    private function keys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys);
        $sorted = $expected;
        sort($sorted);
        if ($keys !== $sorted) {
            throw new ImportSchemaFingerprintException('DESCRIPTOR_SCHEMA_INVALID');
        }
    }

    private function schemaVersion(mixed $value): bool
    {
        return (is_int($value) && $value === 1) || (is_float($value) && $value === 1.0);
    }

    private function integralInRange(mixed $value, int $minimum, int $maximum): bool
    {
        if (is_int($value)) {
            return $value >= $minimum && $value <= $maximum;
        }
        if (! is_float($value) || ! is_finite($value) || floor($value) !== $value) {
            return false;
        }

        return $value >= $minimum && $value <= $maximum;
    }

    private function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/'.self::IDENTIFIER_PATTERN.'/D', $value) === 1;
    }

    private function regular(mixed $mode): bool
    {
        return is_int($mode) && ($mode & 0170000) === 0100000;
    }

    /**
     * @param  FileStat  $left
     * @param  FileStat  $right
     */
    private function sameFile(array $left, array $right): bool
    {
        return isset($left['dev'], $left['ino'], $right['dev'], $right['ino']) && $left['dev'] === $right['dev'] && $left['ino'] === $right['ino'];
    }

    private function depth(mixed $value): int
    {
        if ($value instanceof stdClass) {
            return 1 + $this->childrenDepth(get_object_vars($value));
        }
        if (is_array($value)) {
            return 1 + $this->childrenDepth($value);
        }

        return 0;
    }

    /** @param array<int|string, mixed> $values */
    private function childrenDepth(array $values): int
    {
        $maximum = 0;
        foreach ($values as $value) {
            $maximum = max($maximum, $this->depth($value));
        }

        return $maximum;
    }
}

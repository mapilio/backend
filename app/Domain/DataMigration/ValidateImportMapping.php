<?php

namespace App\Domain\DataMigration;

use DateTimeImmutable;
use JsonException;
use stdClass;

final class ValidateImportMapping
{
    public const MAX_BYTES = 262144;

    public const MAX_DEPTH = 32;

    public const MAX_MAPPINGS = 100;

    public const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'staging'];

    public const TYPES = ['bigint', 'integer', 'string', 'text', 'boolean', 'datetime', 'password_hash'];

    public const CLASSIFICATIONS = ['none', 'stable_identifier', 'contact', 'credential', 'profile'];

    public const TRANSFORMATIONS = ['identity', 'nullable_identity', 'datetime_utc', 'boolean_normalize', 'password_hash_preserve', 'force_password_reset'];

    public const APPROVED_AT_PATTERN = '^(?:(?:(?:[0-9]{3}[1-9]|[0-9]{2}[1-9][0-9]|[0-9][1-9][0-9]{2}|[1-9][0-9]{3})-(?:(?:0[13578]|1[02])-(?:0[1-9]|[12][0-9]|3[01])|(?:0[469]|11)-(?:0[1-9]|[12][0-9]|30)|02-(?:0[1-9]|1[0-9]|2[0-8])))|(?:(?:[0-9]{2}(?:0[48]|[2468][048]|[13579][26])|(?:0[48]|[2468][048]|[13579][26])00)-02-29))T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]Z$';

    private const TOP_KEYS = ['schema_version', 'manifest_id', 'domain', 'source', 'target', 'policy', 'approvals', 'mappings'];

    private const SOURCE_KEYS = ['system', 'table', 'schema_fingerprint'];

    private const POLICY_KEYS = ['collision', 'unknown_columns', 'pii_handling', 'external_ids', 'rollback', 'password_strategy'];

    private const APPROVAL_KEYS = ['role', 'approval_id', 'approved_at'];

    private const MAPPING_KEYS = ['source_column', 'source_type', 'source_nullable', 'target_column', 'target_type', 'target_nullable', 'classification', 'external_id', 'transformation'];

    private const ROLES = ['data_owner', 'identity_owner', 'security_owner'];

    public function validate(?string $manifestPath, mixed $sourceFingerprint, mixed $targetFingerprint): ImportMappingValidationResult
    {
        $environment = (string) config('app.env', app()->environment());
        if (! in_array($environment, self::ALLOWED_ENVIRONMENTS, true)) {
            throw new ImportMappingValidationException('PRODUCTION_BLOCKED');
        }
        $json = $this->readManifest($manifestPath);
        if (! mb_check_encoding($json, 'UTF-8')) {
            throw new ImportMappingValidationException('MANIFEST_INVALID_JSON');
        }
        try {
            $manifest = json_decode($json, false, self::MAX_DEPTH + 1, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new ImportMappingValidationException('MANIFEST_INVALID_JSON');
        }
        if (! $this->isManifestShapeValid($manifest)) {
            throw new ImportMappingValidationException('MANIFEST_SCHEMA_INVALID');
        }
        $manifest = $this->normalizeObject($manifest);
        $this->validateManifest($manifest, $sourceFingerprint, $targetFingerprint);

        return new ImportMappingValidationResult(true, ['MANIFEST_READABLE', 'MANIFEST_SCHEMA', 'FINGERPRINTS', 'MAPPINGS']);
    }

    private function readManifest(?string $manifestPath): string
    {
        if (! is_string($manifestPath) || $manifestPath === '' || str_contains($manifestPath, "\0")) {
            throw new ImportMappingValidationException('MANIFEST_UNREADABLE');
        }
        $pathStat = @lstat($manifestPath);
        if ($pathStat === false || ! $this->isRegularMode($pathStat['mode'])) {
            throw new ImportMappingValidationException('MANIFEST_UNREADABLE');
        }
        $handle = @fopen($manifestPath, 'rb');
        if ($handle === false) {
            throw new ImportMappingValidationException('MANIFEST_UNREADABLE');
        }
        try {
            $openedStat = fstat($handle);
            if ($openedStat === false || ! $this->sameFile($pathStat, $openedStat) || ! $this->isRegularMode($openedStat['mode'])) {
                throw new ImportMappingValidationException('MANIFEST_UNREADABLE');
            }
            if ($openedStat['size'] > self::MAX_BYTES) {
                throw new ImportMappingValidationException('MANIFEST_TOO_LARGE');
            }
            $contents = stream_get_contents($handle, self::MAX_BYTES + 1);
            $finalStat = fstat($handle);
            if ($contents === false || $finalStat === false || ! $this->sameFile($openedStat, $finalStat) || ! $this->isRegularMode($finalStat['mode'])) {
                throw new ImportMappingValidationException('MANIFEST_UNREADABLE');
            }
            if (strlen($contents) > self::MAX_BYTES || $finalStat['size'] > self::MAX_BYTES) {
                throw new ImportMappingValidationException('MANIFEST_TOO_LARGE');
            }

            return $contents;
        } finally {
            fclose($handle);
        }
    }

    private function isRegularMode(mixed $mode): bool
    {
        return is_int($mode) && ($mode & 0170000) === 0100000;
    }

    /** @param array<int|string,int> $left @param array<int|string,int> $right */
    private function sameFile(array $left, array $right): bool
    {
        return isset($left['dev'], $left['ino'], $right['dev'], $right['ino'])
            && $left['dev'] === $right['dev'] && $left['ino'] === $right['ino'];
    }

    private function isManifestShapeValid(mixed $manifest): bool
    {
        if (! $manifest instanceof stdClass || $this->depth($manifest) > self::MAX_DEPTH) {
            return false;
        }
        foreach (['source', 'target', 'policy'] as $key) {
            if (! (($manifest->{$key} ?? null) instanceof stdClass)) {
                return false;
            }
        }
        if (! is_array($manifest->approvals ?? null) || ! is_array($manifest->mappings ?? null)) {
            return false;
        }
        foreach ($manifest->approvals as $approval) {
            if (! $approval instanceof stdClass) {
                return false;
            }
        }
        foreach ($manifest->mappings as $mapping) {
            if (! $mapping instanceof stdClass) {
                return false;
            }
        }

        return true;
    }

    private function normalizeObject(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $normalized = [];
            foreach (get_object_vars($value) as $key => $child) {
                $normalized[$key] = $this->normalizeObject($child);
            }

            return $normalized;
        }
        if (is_array($value)) {
            return array_map(fn (mixed $child): mixed => $this->normalizeObject($child), $value);
        }

        return $value;
    }

    /** @param array<string,mixed> $m */
    private function validateManifest(array $m, mixed $sourceFingerprint, mixed $targetFingerprint): void
    {
        $this->keys($m, self::TOP_KEYS);
        if (! $this->schemaVersion($m['schema_version']) || $m['domain'] !== 'identity_users' || ! $this->slug($m['manifest_id'])) {
            throw new ImportMappingValidationException('MANIFEST_SCHEMA_INVALID');
        }
        foreach (['source', 'target'] as $side) {
            if (! is_array($m[$side] ?? null)) {
                throw new ImportMappingValidationException('MANIFEST_SCHEMA_INVALID');
            }
            $this->keys($m[$side], self::SOURCE_KEYS);
            if (! $this->slug($m[$side]['system']) || ! $this->identifier($m[$side]['table']) || ! $this->fingerprint($m[$side]['schema_fingerprint'])) {
                throw new ImportMappingValidationException('MANIFEST_SCHEMA_INVALID');
            }
        }
        if (! $this->fingerprint($sourceFingerprint) || ! $this->fingerprint($targetFingerprint)) {
            throw new ImportMappingValidationException('FINGERPRINT_REQUIRED');
        }
        if (! hash_equals($m['source']['schema_fingerprint'], $sourceFingerprint) || ! hash_equals($m['target']['schema_fingerprint'], $targetFingerprint)) {
            throw new ImportMappingValidationException('SCHEMA_FINGERPRINT_MISMATCH');
        }
        if (! is_array($m['policy'] ?? null)) {
            throw new ImportMappingValidationException('MANIFEST_SCHEMA_INVALID');
        }
        $this->keys($m['policy'], self::POLICY_KEYS);
        $p = $m['policy'];
        if ($p['collision'] !== 'reject' || $p['unknown_columns'] !== 'reject' || $p['pii_handling'] !== 'restricted' || $p['external_ids'] !== 'preserve' || $p['rollback'] !== 'required' || ! in_array($p['password_strategy'], ['preserve_supported_hash', 'force_reset'], true)) {
            throw new ImportMappingValidationException('MANIFEST_SCHEMA_INVALID');
        }
        $this->approvals($m['approvals'] ?? null);
        if (! is_array($m['mappings']) || count($m['mappings']) < 1 || count($m['mappings']) > self::MAX_MAPPINGS) {
            throw new ImportMappingValidationException('MANIFEST_SCHEMA_INVALID');
        }
        $sources = $targets = [];
        $external = 0;
        $credentials = 0;
        foreach ($m['mappings'] as $mapping) {
            if (! is_array($mapping)) {
                throw new ImportMappingValidationException('MANIFEST_SCHEMA_INVALID');
            }
            $this->keys($mapping, self::MAPPING_KEYS);
            foreach (['source_column', 'target_column'] as $k) {
                if (! $this->identifier($mapping[$k])) {
                    throw new ImportMappingValidationException('MANIFEST_SCHEMA_INVALID');
                }
            }
            foreach (['source_type', 'target_type'] as $k) {
                if (! in_array($mapping[$k], self::TYPES, true)) {
                    throw new ImportMappingValidationException('TRANSFORMATION_NOT_ALLOWED');
                }
            }
            foreach (['source_nullable', 'target_nullable'] as $k) {
                if (! is_bool($mapping[$k])) {
                    throw new ImportMappingValidationException('NULLABILITY_UNSAFE');
                }
            }
            if (! in_array($mapping['classification'], self::CLASSIFICATIONS, true)) {
                throw new ImportMappingValidationException('PII_CLASSIFICATION_INVALID');
            }
            if (! in_array($mapping['external_id'], ['preserve', 'not_external'], true)) {
                throw new ImportMappingValidationException('EXTERNAL_ID_NOT_PRESERVED');
            }
            if (! in_array($mapping['transformation'], self::TRANSFORMATIONS, true)) {
                throw new ImportMappingValidationException('TRANSFORMATION_NOT_ALLOWED');
            }
            if (in_array($mapping['source_column'], $sources, true) || in_array($mapping['target_column'], $targets, true)) {
                throw new ImportMappingValidationException('MAPPING_DUPLICATE');
            }
            $sources[] = $mapping['source_column'];
            $targets[] = $mapping['target_column'];
            $t = $mapping['transformation'];
            $same = $mapping['source_type'] === $mapping['target_type'];
            if ($mapping['source_nullable'] && ! $mapping['target_nullable']) {
                throw new ImportMappingValidationException('NULLABILITY_UNSAFE');
            }
            $passwordMapping = $mapping['source_type'] === 'password_hash'
                || $mapping['target_type'] === 'password_hash'
                || in_array($t, ['password_hash_preserve', 'force_password_reset'], true)
                || $mapping['classification'] === 'credential';
            if ($passwordMapping) {
                $credentials++;
                if ($mapping['classification'] !== 'credential'
                    || $mapping['source_type'] !== 'password_hash'
                    || $mapping['target_type'] !== 'password_hash'
                    || $t !== ($p['password_strategy'] === 'force_reset' ? 'force_password_reset' : 'password_hash_preserve')) {
                    throw new ImportMappingValidationException('PASSWORD_POLICY_MISMATCH');
                }
            }
            if (in_array($t, ['identity', 'nullable_identity'], true)) {
                if (! $same || ($t === 'nullable_identity' && ! $mapping['target_nullable'])) {
                    throw new ImportMappingValidationException('NULLABILITY_UNSAFE');
                }
            }
            if ($t === 'datetime_utc' && ($mapping['source_type'] !== 'datetime' || $mapping['target_type'] !== 'datetime')) {
                throw new ImportMappingValidationException('TRANSFORMATION_NOT_ALLOWED');
            }
            if ($t === 'boolean_normalize' && (! in_array($mapping['source_type'], ['integer', 'boolean'], true) || $mapping['target_type'] !== 'boolean')) {
                throw new ImportMappingValidationException('TRANSFORMATION_NOT_ALLOWED');
            }
            if ($mapping['external_id'] === 'preserve') {
                $external++;
                if ($t !== 'identity' || $mapping['target_nullable'] || $mapping['classification'] !== 'stable_identifier') {
                    throw new ImportMappingValidationException('EXTERNAL_ID_NOT_PRESERVED');
                }
            }
        }
        if ($external === 0) {
            throw new ImportMappingValidationException('EXTERNAL_ID_NOT_PRESERVED');
        }
        if ($credentials !== 1) {
            throw new ImportMappingValidationException('PASSWORD_POLICY_MISMATCH');
        }
    }

    private function approvals(mixed $approvals): void
    {
        if (! is_array($approvals) || count($approvals) !== 3) {
            throw new ImportMappingValidationException('OWNER_APPROVAL_MISSING');
        }
        $roles = [];
        foreach ($approvals as $approval) {
            if (! is_array($approval)) {
                throw new ImportMappingValidationException('OWNER_APPROVAL_MISSING');
            }
            $this->keys($approval, self::APPROVAL_KEYS);
            if (! in_array($approval['role'], self::ROLES, true) || in_array($approval['role'], $roles, true) || ! $this->slug($approval['approval_id']) || ! $this->timestamp($approval['approved_at'])) {
                throw new ImportMappingValidationException('OWNER_APPROVAL_MISSING');
            }
            $roles[] = $approval['role'];
        }
        sort($roles);
        $expected = self::ROLES;
        sort($expected);
        if ($roles !== $expected) {
            throw new ImportMappingValidationException('OWNER_APPROVAL_MISSING');
        }
    }

    /** @param array<string,mixed> $value */
    private function keys(array $value, array $expected): void
    {
        if (array_diff(array_keys($value), $expected) !== [] || array_diff($expected, array_keys($value)) !== []) {
            throw new ImportMappingValidationException('MANIFEST_SCHEMA_INVALID');
        }
    }

    private function slug(mixed $v): bool
    {
        return is_string($v) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $v) === 1;
    }

    private function identifier(mixed $v): bool
    {
        return is_string($v) && preg_match('/^[a-z_][a-z0-9_]*$/D', $v) === 1;
    }

    private function fingerprint(mixed $v): bool
    {
        return is_string($v) && preg_match('/^[0-9a-f]{64}$/D', $v) === 1;
    }

    private function schemaVersion(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && (float) $value === 1.0;
    }

    private function timestamp(mixed $v): bool
    {
        if (! is_string($v) || preg_match('~'.self::APPROVED_AT_PATTERN.'~D', $v) !== 1) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $v);

        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $v;
    }

    private function depth(mixed $v, int $level = 1): int
    {
        if (! is_array($v) && ! $v instanceof stdClass) {
            return $level;
        } $max = $level;
        foreach ($v as $child) {
            $max = max($max, $this->depth($child, $level + 1));
        }

        return $max;
    }
}

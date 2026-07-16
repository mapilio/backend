<?php

namespace App\Domain\OperationsDashboard\Actions;

use Carbon\CarbonImmutable;
use JsonException;

class VerifyBackupReadinessEvidence
{
    private const MAX_MANIFEST_BYTES = 65_536;

    private const FUTURE_CLOCK_SKEW_MINUTES = 5;

    /**
     * @return array{checks: list<string>}
     */
    public function verify(?string $manifestPath = null): array
    {
        $policy = $this->policy();
        $manifest = $this->manifest($manifestPath);
        $failures = [];
        $checks = [];

        $this->exactKeys(
            $manifest,
            ['schema_version', 'environment', 'generated_at', 'database', 'restore_drill'],
            'manifest',
            $failures,
        );
        $this->check(($manifest['schema_version'] ?? null) === 1, 'schema version is supported', $checks, $failures);
        $this->check(
            ($manifest['environment'] ?? null) === $policy['expected_environment'],
            'evidence environment matches deployment policy',
            $checks,
            $failures,
        );

        $generatedAt = $this->timestamp($manifest['generated_at'] ?? null, 'manifest generated_at', $failures);
        $database = $this->object($manifest['database'] ?? null, 'database', $failures);
        $restore = $this->object($manifest['restore_drill'] ?? null, 'restore_drill', $failures);

        $this->exactKeys(
            $database,
            [
                'engine',
                'status',
                'completed_at',
                'artifact_read_verified',
                'checksum_verified',
                'encrypted',
                'encryption_key_external',
                'offsite_copy_verified',
                'immutable_copy_verified',
                'pitr_enabled',
                'latest_wal_archived_at',
            ],
            'database',
            $failures,
        );
        $this->exactKeys(
            $restore,
            [
                'status',
                'completed_at',
                'target',
                'postgis_verified',
                'migration_state_verified',
                'integrity_checks_verified',
                'application_boot_verified',
                'measured_rpo_seconds',
                'measured_rto_seconds',
            ],
            'restore_drill',
            $failures,
        );

        $this->check(($database['engine'] ?? null) === 'postgresql', 'database engine is PostgreSQL', $checks, $failures);
        $this->check(($database['status'] ?? null) === 'success', 'database backup completed successfully', $checks, $failures);
        $this->requiredTrue($database, 'artifact_read_verified', 'backup artifact is readable', $checks, $failures);
        $this->requiredTrue($database, 'checksum_verified', 'backup checksum was verified', $checks, $failures);
        $this->requiredTrue($database, 'encrypted', 'backup is encrypted', $checks, $failures);
        $this->requiredTrue($database, 'encryption_key_external', 'encryption key is stored outside backup storage', $checks, $failures);
        $this->requiredTrue($database, 'offsite_copy_verified', 'off-site copy was verified', $checks, $failures);
        $this->requiredTrue($database, 'immutable_copy_verified', 'immutable copy was verified', $checks, $failures);
        $this->requiredTrue($database, 'pitr_enabled', 'point-in-time recovery is enabled', $checks, $failures);

        $backupAt = $this->timestamp($database['completed_at'] ?? null, 'database completed_at', $failures);
        $walAt = $this->timestamp($database['latest_wal_archived_at'] ?? null, 'database latest_wal_archived_at', $failures);

        $this->check(($restore['status'] ?? null) === 'success', 'restore drill completed successfully', $checks, $failures);
        $this->check(
            ($restore['target'] ?? null) === 'isolated-non-production',
            'restore drill used an isolated non-production target',
            $checks,
            $failures,
        );
        $this->requiredTrue($restore, 'postgis_verified', 'PostGIS was verified after restore', $checks, $failures);
        $this->requiredTrue($restore, 'migration_state_verified', 'migration state was verified after restore', $checks, $failures);
        $this->requiredTrue($restore, 'integrity_checks_verified', 'data integrity checks passed after restore', $checks, $failures);
        $this->requiredTrue($restore, 'application_boot_verified', 'application boot was verified after restore', $checks, $failures);

        $restoreAt = $this->timestamp($restore['completed_at'] ?? null, 'restore_drill completed_at', $failures);
        $rpo = $this->nonNegativeInteger($restore['measured_rpo_seconds'] ?? null, 'restore_drill measured_rpo_seconds', $failures);
        $rto = $this->nonNegativeInteger($restore['measured_rto_seconds'] ?? null, 'restore_drill measured_rto_seconds', $failures);
        $now = CarbonImmutable::now('UTC');

        $this->fresh(
            $generatedAt,
            $now->subMinutes($policy['max_manifest_age_minutes']),
            $now,
            'evidence manifest is fresh',
            $checks,
            $failures,
        );
        $this->fresh(
            $backupAt,
            $now->subHours($policy['max_backup_age_hours']),
            $now,
            'database backup is within the configured age',
            $checks,
            $failures,
        );
        $this->fresh(
            $walAt,
            $now->subMinutes($policy['max_wal_age_minutes']),
            $now,
            'latest archived WAL is within the configured age',
            $checks,
            $failures,
        );
        $this->fresh(
            $restoreAt,
            $now->subDays($policy['max_restore_drill_age_days']),
            $now,
            'restore drill is within the configured age',
            $checks,
            $failures,
        );

        if ($generatedAt !== null) {
            foreach (['database backup' => $backupAt, 'archived WAL' => $walAt, 'restore drill' => $restoreAt] as $label => $occurredAt) {
                $this->check(
                    $occurredAt !== null && $occurredAt->lessThanOrEqualTo($generatedAt),
                    "{$label} predates evidence generation",
                    $checks,
                    $failures,
                );
            }
        }

        $this->check(
            $rpo !== null && $rpo <= $policy['max_rpo_seconds'],
            'measured RPO meets deployment policy',
            $checks,
            $failures,
        );
        $this->check(
            $rto !== null && $rto <= $policy['max_rto_seconds'],
            'measured RTO meets deployment policy',
            $checks,
            $failures,
        );

        if ($failures !== []) {
            throw new BackupReadinessException(array_values(array_unique($failures)));
        }

        return ['checks' => $checks];
    }

    /**
     * @return array{
     *     expected_environment: string,
     *     max_manifest_age_minutes: int,
     *     max_backup_age_hours: int,
     *     max_wal_age_minutes: int,
     *     max_restore_drill_age_days: int,
     *     max_rpo_seconds: int,
     *     max_rto_seconds: int
     * }
     */
    private function policy(): array
    {
        $config = config('mapilio.backup_readiness', []);
        $failures = [];
        $environment = is_string($config['expected_environment'] ?? null)
            ? trim($config['expected_environment'])
            : '';

        if ($environment === '' || ! preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $environment)) {
            $failures[] = 'expected backup environment policy is not configured';
        }

        $limits = [];

        foreach ([
            'max_manifest_age_minutes',
            'max_backup_age_hours',
            'max_wal_age_minutes',
            'max_restore_drill_age_days',
            'max_rpo_seconds',
            'max_rto_seconds',
        ] as $key) {
            $limits[$key] = $this->positiveInteger($config[$key] ?? null, "backup policy {$key}", $failures);
        }

        if ($failures !== []) {
            throw new BackupReadinessException($failures);
        }

        return ['expected_environment' => $environment, ...$limits];
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(?string $manifestPath): array
    {
        $path = trim($manifestPath ?? (string) config('mapilio.backup_readiness.evidence_path', ''));

        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new BackupReadinessException(['backup evidence file is not configured or readable']);
        }

        $size = filesize($path);

        if (! is_int($size) || $size < 2 || $size > self::MAX_MANIFEST_BYTES) {
            throw new BackupReadinessException(['backup evidence file size is outside the accepted boundary']);
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new BackupReadinessException(['backup evidence file could not be read']);
        }

        if (strlen($contents) < 2 || strlen($contents) > self::MAX_MANIFEST_BYTES) {
            throw new BackupReadinessException(['backup evidence file size changed outside the accepted boundary']);
        }

        try {
            $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BackupReadinessException(['backup evidence file is not valid JSON']);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new BackupReadinessException(['backup evidence root must be a JSON object']);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expected
     * @param  list<string>  $failures
     */
    private function exactKeys(array $value, array $expected, string $field, array &$failures): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);

        if ($actual !== $expected) {
            $failures[] = "{$field} fields do not match the public evidence schema";
        }
    }

    /**
     * @param  list<string>  $failures
     * @return array<string, mixed>
     */
    private function object(mixed $value, string $field, array &$failures): array
    {
        if (! is_array($value) || array_is_list($value)) {
            $failures[] = "{$field} must be a JSON object";

            return [];
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $checks
     * @param  list<string>  $failures
     */
    private function requiredTrue(array $values, string $key, string $check, array &$checks, array &$failures): void
    {
        $this->check(($values[$key] ?? null) === true, $check, $checks, $failures);
    }

    /**
     * @param  list<string>  $checks
     * @param  list<string>  $failures
     */
    private function check(bool $passes, string $check, array &$checks, array &$failures): void
    {
        if ($passes) {
            $checks[] = $check;

            return;
        }

        $failures[] = $check;
    }

    /**
     * @param  list<string>  $failures
     */
    private function timestamp(mixed $value, string $field, array &$failures): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value)) {
            $failures[] = "{$field} must be a UTC RFC3339 timestamp";

            return null;
        }

        $timestamp = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value, 'UTC');

        if ($timestamp === null || $timestamp->format('Y-m-d\TH:i:s\Z') !== $value) {
            $failures[] = "{$field} must be a valid UTC RFC3339 timestamp";

            return null;
        }

        return $timestamp;
    }

    /**
     * @param  list<string>  $checks
     * @param  list<string>  $failures
     */
    private function fresh(
        ?CarbonImmutable $value,
        CarbonImmutable $oldest,
        CarbonImmutable $now,
        string $check,
        array &$checks,
        array &$failures,
    ): void {
        $this->check(
            $value !== null
                && $value->greaterThanOrEqualTo($oldest)
                && $value->lessThanOrEqualTo($now->addMinutes(self::FUTURE_CLOCK_SKEW_MINUTES)),
            $check,
            $checks,
            $failures,
        );
    }

    /**
     * @param  list<string>  $failures
     */
    private function positiveInteger(mixed $value, string $field, array &$failures): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 1) {
            $failures[] = "{$field} must be a positive integer";

            return 0;
        }

        return $value;
    }

    /**
     * @param  list<string>  $failures
     */
    private function nonNegativeInteger(mixed $value, string $field, array &$failures): ?int
    {
        if (! is_int($value) || $value < 0) {
            $failures[] = "{$field} must be a non-negative integer";

            return null;
        }

        return $value;
    }
}

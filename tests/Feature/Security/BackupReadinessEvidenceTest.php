<?php

namespace Tests\Feature\Security;

use Carbon\CarbonImmutable;
use Tests\TestCase;

class BackupReadinessEvidenceTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-16T09:00:00Z'));
        config()->set('mapilio.backup_readiness', [
            'evidence_path' => null,
            'expected_environment' => 'production',
            'max_manifest_age_minutes' => 15,
            'max_backup_age_hours' => 24,
            'max_wal_age_minutes' => 15,
            'max_restore_drill_age_days' => 90,
            'max_rpo_seconds' => 900,
            'max_rto_seconds' => 14_400,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_fresh_complete_evidence_passes_without_printing_manifest_values(): void
    {
        $path = $this->writeManifest($this->validManifest());

        $this->artisan('mapilio:verify-backup-readiness', ['--manifest' => $path])
            ->expectsOutputToContain('database backup completed successfully')
            ->expectsOutputToContain('measured RTO meets deployment policy')
            ->expectsOutputToContain('meets the configured deployment policy')
            ->doesntExpectOutputToContain($path)
            ->doesntExpectOutputToContain('2026-07-16T08:00:00Z')
            ->assertSuccessful();
    }

    public function test_policy_thresholds_and_environment_must_be_explicitly_configured(): void
    {
        config()->set('mapilio.backup_readiness.expected_environment', null);
        config()->set('mapilio.backup_readiness.max_rpo_seconds', null);

        $this->artisan('mapilio:verify-backup-readiness', [
            '--manifest' => $this->writeManifest($this->validManifest()),
        ])
            ->expectsOutputToContain('expected backup environment policy is not configured')
            ->expectsOutputToContain('backup policy max_rpo_seconds must be a positive integer')
            ->assertFailed();
    }

    public function test_manifest_schema_rejects_extra_fields_without_printing_their_values(): void
    {
        $manifest = $this->validManifest();
        $manifest['database']['password'] = 'must-never-be-printed';

        $this->artisan('mapilio:verify-backup-readiness', [
            '--manifest' => $this->writeManifest($manifest),
        ])
            ->expectsOutputToContain('database fields do not match the public evidence schema')
            ->doesntExpectOutputToContain('must-never-be-printed')
            ->assertFailed();
    }

    public function test_backup_integrity_encryption_copy_and_pitr_controls_fail_closed(): void
    {
        $manifest = $this->validManifest();

        foreach ([
            'artifact_read_verified',
            'checksum_verified',
            'encrypted',
            'encryption_key_external',
            'offsite_copy_verified',
            'immutable_copy_verified',
            'pitr_enabled',
        ] as $field) {
            $manifest['database'][$field] = false;
        }

        $this->artisan('mapilio:verify-backup-readiness', [
            '--manifest' => $this->writeManifest($manifest),
        ])
            ->expectsOutputToContain('backup checksum was verified')
            ->expectsOutputToContain('backup is encrypted')
            ->expectsOutputToContain('immutable copy was verified')
            ->expectsOutputToContain('point-in-time recovery is enabled')
            ->assertFailed();
    }

    public function test_stale_and_future_dated_evidence_fails_closed(): void
    {
        $manifest = $this->validManifest();
        $manifest['generated_at'] = '2026-07-16T08:30:00Z';
        $manifest['database']['completed_at'] = '2026-07-14T08:00:00Z';
        $manifest['database']['latest_wal_archived_at'] = '2026-07-16T09:06:00Z';
        $manifest['restore_drill']['completed_at'] = '2026-03-01T12:00:00Z';

        $this->artisan('mapilio:verify-backup-readiness', [
            '--manifest' => $this->writeManifest($manifest),
        ])
            ->expectsOutputToContain('evidence manifest is fresh')
            ->expectsOutputToContain('database backup is within the configured age')
            ->expectsOutputToContain('latest archived WAL is within the configured age')
            ->expectsOutputToContain('restore drill is within the configured age')
            ->expectsOutputToContain('archived WAL predates evidence generation')
            ->assertFailed();
    }

    public function test_restore_verification_and_measured_objectives_fail_closed(): void
    {
        $manifest = $this->validManifest();
        $manifest['restore_drill']['status'] = 'partial';
        $manifest['restore_drill']['target'] = 'production';
        $manifest['restore_drill']['postgis_verified'] = false;
        $manifest['restore_drill']['migration_state_verified'] = false;
        $manifest['restore_drill']['integrity_checks_verified'] = false;
        $manifest['restore_drill']['application_boot_verified'] = false;
        $manifest['restore_drill']['measured_rpo_seconds'] = 901;
        $manifest['restore_drill']['measured_rto_seconds'] = 14_401;

        $this->artisan('mapilio:verify-backup-readiness', [
            '--manifest' => $this->writeManifest($manifest),
        ])
            ->expectsOutputToContain('restore drill completed successfully')
            ->expectsOutputToContain('restore drill used an isolated non-production target')
            ->expectsOutputToContain('PostGIS was verified after restore')
            ->expectsOutputToContain('data integrity checks passed after restore')
            ->expectsOutputToContain('measured RPO meets deployment policy')
            ->expectsOutputToContain('measured RTO meets deployment policy')
            ->assertFailed();
    }

    public function test_unreadable_or_invalid_json_evidence_fails_safely(): void
    {
        $this->artisan('mapilio:verify-backup-readiness', ['--manifest' => '/missing/backup-evidence.json'])
            ->expectsOutputToContain('backup evidence file is not configured or readable')
            ->doesntExpectOutputToContain('/missing/backup-evidence.json')
            ->assertFailed();

        $path = $this->writeContents('{not-json');

        $this->artisan('mapilio:verify-backup-readiness', ['--manifest' => $path])
            ->expectsOutputToContain('backup evidence file is not valid JSON')
            ->doesntExpectOutputToContain('{not-json')
            ->assertFailed();
    }

    /**
     * @return array<string, mixed>
     */
    private function validManifest(): array
    {
        return [
            'schema_version' => 1,
            'environment' => 'production',
            'generated_at' => '2026-07-16T08:59:00Z',
            'database' => [
                'engine' => 'postgresql',
                'status' => 'success',
                'completed_at' => '2026-07-16T08:00:00Z',
                'artifact_read_verified' => true,
                'checksum_verified' => true,
                'encrypted' => true,
                'encryption_key_external' => true,
                'offsite_copy_verified' => true,
                'immutable_copy_verified' => true,
                'pitr_enabled' => true,
                'latest_wal_archived_at' => '2026-07-16T08:55:00Z',
            ],
            'restore_drill' => [
                'status' => 'success',
                'completed_at' => '2026-07-06T12:00:00Z',
                'target' => 'isolated-non-production',
                'postgis_verified' => true,
                'migration_state_verified' => true,
                'integrity_checks_verified' => true,
                'application_boot_verified' => true,
                'measured_rpo_seconds' => 300,
                'measured_rto_seconds' => 3600,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function writeManifest(array $manifest): string
    {
        return $this->writeContents((string) json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    private function writeContents(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mapilio-backup-evidence-');

        if ($path === false) {
            $this->fail('Could not create temporary evidence file.');
        }

        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}

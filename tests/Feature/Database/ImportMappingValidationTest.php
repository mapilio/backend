<?php

namespace Tests\Feature\Database;

use App\Domain\DataMigration\ValidateImportMapping;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ImportMappingValidationTest extends TestCase
{
    private string $directory;

    private string $source = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $target = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.env', 'testing');
        $this->directory = sys_get_temp_dir().'/mapilio-mapping-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_valid_synthetic_manifest_passes_without_sensitive_output(): void
    {
        $path = $this->write('valid.json', $this->manifest());
        $this->artisan('mapilio:validate-import-mapping', ['manifest' => $path, '--source-fingerprint' => $this->source, '--target-fingerprint' => $this->target])
            ->assertSuccessful()->expectsOutput('MANIFEST_SCHEMA: PASS')->expectsOutput('FINGERPRINTS: PASS')
            ->doesntExpectOutput($path)->doesntExpectOutput($this->source)->doesntExpectOutput('legacy_users')->doesntExpectOutput('password_hash');
    }

    public function test_production_is_refused_before_file_read(): void
    {
        Config::set('app.env', 'production');
        $path = $this->directory.'/missing.json';
        $this->artisan('mapilio:validate-import-mapping', ['manifest' => $path, '--source-fingerprint' => $this->source, '--target-fingerprint' => $this->target])->assertFailed()->expectsOutput('PRODUCTION_BLOCKED');
    }

    public function test_input_and_fingerprint_guards_are_sanitized(): void
    {
        $valid = $this->write('valid.json', $this->manifest());
        foreach ([[$this->directory.'/missing.json', 'MANIFEST_UNREADABLE'], [$this->directory, 'MANIFEST_UNREADABLE']] as [$path, $reason]) {
            $this->artisan('mapilio:validate-import-mapping', ['manifest' => $path, '--source-fingerprint' => $this->source, '--target-fingerprint' => $this->target])->assertFailed()->expectsOutput($reason);
        }
        $this->artisan('mapilio:validate-import-mapping', ['manifest' => $valid])->assertFailed()->expectsOutput('FINGERPRINT_REQUIRED');
        $this->artisan('mapilio:validate-import-mapping', ['manifest' => $valid, '--source-fingerprint' => str_repeat('c', 64), '--target-fingerprint' => $this->target])->assertFailed()->expectsOutput('SCHEMA_FINGERPRINT_MISMATCH');
    }

    public function test_schema_unknown_fields_approvals_duplicates_and_mapping_rules_fail_closed(): void
    {
        $cases = [
            ['unknown', 'MANIFEST_SCHEMA_INVALID', fn (&$m) => $m['unexpected'] = true],
            ['approval', 'MANIFEST_SCHEMA_INVALID', fn (&$m) => $m['approvals'][0]['extra'] = true],
            ['duplicate', 'MAPPING_DUPLICATE', fn (&$m) => $m['mappings'][1]['source_column'] = 'legacy_id'],
            ['external', 'EXTERNAL_ID_NOT_PRESERVED', fn (&$m) => $m['mappings'][0]['external_id'] = 'not_external'],
            ['password', 'PASSWORD_POLICY_MISMATCH', fn (&$m) => $m['mappings'][2]['transformation'] = 'identity'],
        ];
        foreach ($cases as [$name, $reason, $change]) {
            $manifest = $this->manifest();
            $change($manifest);
            $path = $this->write($name.'.json', $manifest);
            $this->artisan('mapilio:validate-import-mapping', ['manifest' => $path, '--source-fingerprint' => $this->source, '--target-fingerprint' => $this->target])->assertFailed()->expectsOutput($reason);
        }
    }

    public function test_invalid_json_utf8_oversize_and_symlink_are_rejected(): void
    {
        $this->writeRaw('invalid.json', '{');
        $this->artisan('mapilio:validate-import-mapping', ['manifest' => $this->directory.'/invalid.json', '--source-fingerprint' => $this->source, '--target-fingerprint' => $this->target])->assertFailed()->expectsOutput('MANIFEST_INVALID_JSON');
        $this->writeRaw('utf8.json', "{\xFF");
        $this->artisan('mapilio:validate-import-mapping', ['manifest' => $this->directory.'/utf8.json', '--source-fingerprint' => $this->source, '--target-fingerprint' => $this->target])->assertFailed()->expectsOutput('MANIFEST_INVALID_JSON');
        $this->writeRaw('large.json', str_repeat('x', 262145));
        $this->artisan('mapilio:validate-import-mapping', ['manifest' => $this->directory.'/large.json', '--source-fingerprint' => $this->source, '--target-fingerprint' => $this->target])->assertFailed()->expectsOutput('MANIFEST_TOO_LARGE');
        symlink($this->directory.'/invalid.json', $this->directory.'/linked.json');
        $this->artisan('mapilio:validate-import-mapping', ['manifest' => $this->directory.'/linked.json', '--source-fingerprint' => $this->source, '--target-fingerprint' => $this->target])->assertFailed()->expectsOutput('MANIFEST_UNREADABLE');
    }

    public function test_exact_limit_is_read_but_one_byte_over_is_rejected(): void
    {
        $json = json_encode($this->manifest(), JSON_THROW_ON_ERROR);
        $this->writeRaw('limit.json', $json.str_repeat(' ', ValidateImportMapping::MAX_BYTES - strlen($json)));
        $this->artisan('mapilio:validate-import-mapping', ['manifest' => $this->directory.'/limit.json', '--source-fingerprint' => $this->source, '--target-fingerprint' => $this->target])->assertSuccessful();
        $this->writeRaw('over-limit.json', $json.str_repeat(' ', ValidateImportMapping::MAX_BYTES + 1 - strlen($json)));
        $this->artisan('mapilio:validate-import-mapping', ['manifest' => $this->directory.'/over-limit.json', '--source-fingerprint' => $this->source, '--target-fingerprint' => $this->target])->assertFailed()->expectsOutput('MANIFEST_TOO_LARGE');
    }

    public function test_json_object_and_array_shapes_are_not_interchangeable(): void
    {
        $manifest = $this->manifest();
        $manifest['source'] = [];
        $this->assertShapeRejected('source-array', $manifest);

        $manifest = $this->manifest();
        $manifest['approvals'] = (object) ['role' => 'data_owner'];
        $this->assertShapeRejected('approvals-object', $manifest);

        $manifest = $this->manifest();
        $manifest['mappings'] = (object) $manifest['mappings'];
        $this->assertShapeRejected('mappings-object', $manifest);

        $manifest = $this->manifest();
        $manifest['approvals'][0] = array_values($manifest['approvals'][0]);
        $this->assertShapeRejected('approval-array', $manifest);

        $manifest = $this->manifest();
        $manifest['mappings'][0] = array_values($manifest['mappings'][0]);
        $this->assertShapeRejected('mapping-array', $manifest);
    }

    public function test_source_nullability_is_rejected_before_each_transform_family(): void
    {
        $cases = [
            ['datetime', fn (&$m) => [$m['mappings'][1]['source_type'] = 'datetime', $m['mappings'][1]['target_type'] = 'datetime', $m['mappings'][1]['source_nullable'] = true, $m['mappings'][1]['transformation'] = 'datetime_utc']],
            ['boolean', fn (&$m) => [$m['mappings'][1]['source_type'] = 'integer', $m['mappings'][1]['target_type'] = 'boolean', $m['mappings'][1]['source_nullable'] = true, $m['mappings'][1]['transformation'] = 'boolean_normalize']],
            ['preserve', fn (&$m) => [$m['mappings'][2]['source_nullable'] = true, $m['mappings'][2]['transformation'] = 'password_hash_preserve']],
            ['reset', fn (&$m) => [$m['policy']['password_strategy'] = 'force_reset', $m['mappings'][2]['source_nullable'] = true, $m['mappings'][2]['transformation'] = 'force_password_reset']],
        ];
        foreach ($cases as [$name, $change]) {
            $manifest = $this->manifest();
            $change($manifest);
            $path = $this->write($name.'.json', $manifest);
            $this->artisan('mapilio:validate-import-mapping', ['manifest' => $path, '--source-fingerprint' => $this->source, '--target-fingerprint' => $this->target])->assertFailed()->expectsOutput('NULLABILITY_UNSAFE');
        }
    }

    /** @param array<string,mixed> $manifest */
    private function assertShapeRejected(string $name, array $manifest): void
    {
        $path = $this->write($name.'.json', $manifest);
        $this->artisan('mapilio:validate-import-mapping', ['manifest' => $path, '--source-fingerprint' => $this->source, '--target-fingerprint' => $this->target])->assertFailed()->expectsOutput('MANIFEST_SCHEMA_INVALID');
    }

    public function test_invalid_approval_timestamp_is_rejected(): void
    {
        $manifest = $this->manifest();
        $manifest['approvals'][0]['approved_at'] = '2026-02-30T00:00:00Z';
        $path = $this->write('bad-timestamp.json', $manifest);
        $this->artisan('mapilio:validate-import-mapping', ['manifest' => $path, '--source-fingerprint' => $this->source, '--target-fingerprint' => $this->target])->assertFailed()->expectsOutput('OWNER_APPROVAL_MISSING');
    }

    public function test_password_hash_cannot_bypass_credential_policy(): void
    {
        $cases = [
            ['source-only', fn (&$m) => $m['mappings'][1]['source_type'] = 'password_hash'],
            ['target-only', fn (&$m) => $m['mappings'][1]['target_type'] = 'password_hash'],
            ['extra-password-hash', fn (&$m) => [$m['mappings'][1]['source_type'] = 'password_hash', $m['mappings'][1]['target_type'] = 'password_hash']],
        ];
        foreach ($cases as [$name, $change]) {
            $manifest = $this->manifest();
            $change($manifest);
            $path = $this->write($name.'.json', $manifest);
            $this->artisan('mapilio:validate-import-mapping', ['manifest' => $path, '--source-fingerprint' => $this->source, '--target-fingerprint' => $this->target])->assertFailed()->expectsOutput('PASSWORD_POLICY_MISMATCH');
        }
    }

    public function test_approved_at_pattern_matches_schema_and_exact_utc_calendar_contract(): void
    {
        $schema = json_decode(File::get(base_path('docs/database/identity-import-mapping.schema.json')), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(ValidateImportMapping::APPROVED_AT_PATTERN, $schema['$defs']['approval']['properties']['approved_at']['pattern']);

        foreach (['2024-02-29T23:59:59Z', '2000-02-29T00:00:00Z', '0004-02-29T12:30:45Z', '2026-01-01T00:00:00Z'] as $timestamp) {
            $this->assertSame(1, preg_match('~'.ValidateImportMapping::APPROVED_AT_PATTERN.'~D', $timestamp), $timestamp);
        }
        foreach (['0000-01-01T00:00:00Z', '1900-02-29T00:00:00Z', '2023-02-29T00:00:00Z', '2026-02-30T00:00:00Z', '2026-04-31T00:00:00Z', '2026-01-01T24:00:00Z', '2026-01-01T23:60:00Z', '2026-01-01T23:59:60Z', '2026-01-01T00:00:00+00:00'] as $timestamp) {
            $this->assertSame(0, preg_match('~'.ValidateImportMapping::APPROVED_AT_PATTERN.'~D', $timestamp), $timestamp);
        }

        $manifest = $this->manifest();
        $manifest['approvals'][0]['approved_at'] = '2024-02-29T23:59:59Z';
        $path = $this->write('valid-leap-timestamp.json', $manifest);
        $this->artisan('mapilio:validate-import-mapping', ['manifest' => $path, '--source-fingerprint' => $this->source, '--target-fingerprint' => $this->target])->assertSuccessful();

        $manifest['approvals'][0]['approved_at'] = '2023-02-29T23:59:59Z';
        $path = $this->write('invalid-non-leap-timestamp.json', $manifest);
        $this->artisan('mapilio:validate-import-mapping', ['manifest' => $path, '--source-fingerprint' => $this->source, '--target-fingerprint' => $this->target])->assertFailed()->expectsOutput('OWNER_APPROVAL_MISSING');
    }

    public function test_schema_version_accepts_numeric_one_equivalence_only(): void
    {
        $manifest = $this->manifest();
        $manifest['schema_version'] = 1.0;
        $path = $this->writeRaw('float-version.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $this->artisan('mapilio:validate-import-mapping', ['manifest' => $path, '--source-fingerprint' => $this->source, '--target-fingerprint' => $this->target])->assertSuccessful();

        foreach (['1', 2, true] as $version) {
            $manifest['schema_version'] = $version;
            $path = $this->write('invalid-version-'.get_debug_type($version).'.json', $manifest);
            $this->artisan('mapilio:validate-import-mapping', ['manifest' => $path, '--source-fingerprint' => $this->source, '--target-fingerprint' => $this->target])->assertFailed()->expectsOutput('MANIFEST_SCHEMA_INVALID');
        }
    }

    public function test_json_depth_boundary_fails_safely_without_exposing_input(): void
    {
        foreach ([ValidateImportMapping::MAX_DEPTH, ValidateImportMapping::MAX_DEPTH + 1] as $depth) {
            $path = $this->writeRaw('depth-'.$depth.'.json', $this->nestedJson($depth));
            $this->artisan('mapilio:validate-import-mapping', ['manifest' => $path, '--source-fingerprint' => $this->source, '--target-fingerprint' => $this->target])
                ->assertFailed()->expectsOutput('MAPPING_VALIDATION_FAILED')->doesntExpectOutput($path)->doesntExpectOutput('synthetic-depth-marker');
        }
    }

    /** @return array<string,mixed> */
    private function manifest(): array
    {
        return ['schema_version' => 1, 'manifest_id' => 'synthetic-identity-users-v1', 'domain' => 'identity_users', 'source' => ['system' => 'example-legacy', 'table' => 'legacy_users', 'schema_fingerprint' => $this->source], 'target' => ['system' => 'example-mapilio', 'table' => 'users', 'schema_fingerprint' => $this->target], 'policy' => ['collision' => 'reject', 'unknown_columns' => 'reject', 'pii_handling' => 'restricted', 'external_ids' => 'preserve', 'rollback' => 'required', 'password_strategy' => 'preserve_supported_hash'], 'approvals' => [['role' => 'data_owner', 'approval_id' => 'synthetic-data-owner-1', 'approved_at' => '2026-01-01T00:00:00Z'], ['role' => 'identity_owner', 'approval_id' => 'synthetic-identity-owner-1', 'approved_at' => '2026-01-01T00:00:00Z'], ['role' => 'security_owner', 'approval_id' => 'synthetic-security-owner-1', 'approved_at' => '2026-01-01T00:00:00Z']], 'mappings' => [['source_column' => 'legacy_id', 'source_type' => 'bigint', 'source_nullable' => false, 'target_column' => 'legacy_id', 'target_type' => 'bigint', 'target_nullable' => false, 'classification' => 'stable_identifier', 'external_id' => 'preserve', 'transformation' => 'identity'], ['source_column' => 'email', 'source_type' => 'string', 'source_nullable' => false, 'target_column' => 'email', 'target_type' => 'string', 'target_nullable' => false, 'classification' => 'contact', 'external_id' => 'not_external', 'transformation' => 'identity'], ['source_column' => 'password_digest', 'source_type' => 'password_hash', 'source_nullable' => false, 'target_column' => 'password_hash', 'target_type' => 'password_hash', 'target_nullable' => false, 'classification' => 'credential', 'external_id' => 'not_external', 'transformation' => 'password_hash_preserve']]];
    }

    private function write(string $name, array $value): string
    {
        return $this->writeRaw($name, json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function writeRaw(string $name, string $value): string
    {
        $path = $this->directory.'/'.$name;
        file_put_contents($path, $value);

        return $path;
    }

    private function nestedJson(int $depth): string
    {
        $json = '"synthetic-depth-marker"';
        for ($level = 1; $level < $depth; $level++) {
            $json = '{"nested":'.$json.'}';
        }

        return $json;
    }
}

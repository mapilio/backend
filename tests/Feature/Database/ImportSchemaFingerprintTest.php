<?php

namespace Tests\Feature\Database;

use App\Domain\DataMigration\ComputeImportSchemaFingerprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ImportSchemaFingerprintTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.env', 'testing');
        $this->directory = sys_get_temp_dir().'/mapilio-schema-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_synthetic_descriptor_has_contract_digest_and_sanitized_success_output(): void
    {
        $path = $this->write('synthetic.json', $this->descriptor());
        $expected = $this->independentExpectedFingerprint();
        $this->assertSame('aa3d97794a96946efade0aa79cb5204b6061633f03b38013a994baa7e28d99d9', $expected);
        $this->artisan('mapilio:fingerprint-import-schema', ['descriptor' => $path])
            ->assertSuccessful()
            ->expectsOutput('SCHEMA_DESCRIPTOR: PASS')
            ->expectsOutput('CANONICALIZATION: PASS')
            ->expectsOutput('SCHEMA_FINGERPRINT: '.$expected)
            ->doesntExpectOutput($path)
            ->doesntExpectOutput('synthetic')
            ->doesntExpectOutput('users');
    }

    public function test_column_input_order_does_not_change_digest(): void
    {
        $descriptor = $this->descriptor();
        $reordered = $descriptor;
        $reordered['columns'] = array_reverse($reordered['columns']);
        $expected = $this->independentExpectedFingerprint();
        $this->artisan('mapilio:fingerprint-import-schema', ['descriptor' => $this->write('first.json', $descriptor)])
            ->assertSuccessful()->expectsOutput('SCHEMA_FINGERPRINT: '.$expected);
        $this->artisan('mapilio:fingerprint-import-schema', ['descriptor' => $this->write('second.json', $reordered)])
            ->assertSuccessful()->expectsOutput('SCHEMA_FINGERPRINT: '.$expected);
    }

    #[DataProvider('contractChanges')]
    public function test_every_contract_field_change_changes_digest(string $field, mixed $replacement): void
    {
        $base = $this->write('base.json', $this->descriptor());
        $changed = $this->descriptor();
        if (str_starts_with($field, 'column.')) {
            $changed['columns'][0][substr($field, 7)] = $replacement;
        } else {
            $changed[$field] = $replacement;
        }
        $changedPath = $this->write('changed.json', $changed);
        $computer = $this->app->make(ComputeImportSchemaFingerprint::class);
        $this->assertNotSame($computer->compute($base)->fingerprint, $computer->compute($changedPath)->fingerprint);
    }

    /** @return array<string,array{string,mixed}> */
    public static function contractChanges(): array
    {
        return [
            'engine' => ['engine', 'sqlite'], 'schema' => ['schema', 'other_schema'], 'table' => ['table', 'other_table'],
            'name' => ['column.name', 'other_name'], 'type schema' => ['column.type_schema', 'other_schema'],
            'type name' => ['column.type_name', 'bigint'], 'nullable' => ['column.nullable', true], 'character length' => ['column.character_length', 12],
            'numeric precision' => ['column.numeric_precision', 12], 'numeric scale' => ['column.numeric_scale', 2], 'datetime precision' => ['column.datetime_precision', 3],
        ];
    }

    public function test_position_changes_digest_with_valid_contiguous_swap(): void
    {
        $base = $this->write('base-position.json', $this->descriptor());
        $changed = $this->descriptor();
        $changed['columns'][0]['position'] = 2;
        $changed['columns'][1]['position'] = 1;
        $changedPath = $this->write('changed-position.json', $changed);
        $computer = $this->app->make(ComputeImportSchemaFingerprint::class);
        $this->assertNotSame($computer->compute($base)->fingerprint, $computer->compute($changedPath)->fingerprint);
    }

    public function test_integral_float_positions_and_shapes_normalize_to_base_digest(): void
    {
        $floatDescriptor = $this->descriptor();
        $floatDescriptor['columns'][0]['position'] = 1.0;
        $floatDescriptor['columns'][0]['numeric_precision'] = 64.0;
        $floatDescriptor['columns'][0]['numeric_scale'] = 0.0;
        $floatDescriptor['columns'][1]['position'] = 2.0;
        $path = $this->writeRaw('integral-floats.json', json_encode($floatDescriptor, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));

        $this->artisan('mapilio:fingerprint-import-schema', ['descriptor' => $path])
            ->assertSuccessful()
            ->expectsOutput('SCHEMA_FINGERPRINT: '.$this->independentExpectedFingerprint());
    }

    public function test_strict_keys_shapes_and_unsupported_metadata_fail(): void
    {
        $cases = [
            ['unknown', fn (&$d) => $d['unexpected'] = true], ['missing', function (&$d): void {
                unset($d['table']);
            }],
            ['row-count', fn (&$d) => $d['row_count'] = 1], ['default', fn (&$d) => $d['columns'][0]['default'] = null],
            ['index', fn (&$d) => $d['indexes'] = []], ['array-top', fn (&$d) => $d = [$d]], ['columns-object', fn (&$d) => $d['columns'] = (object) $d['columns']],
            ['column-list', fn (&$d) => $d['columns'][0] = array_values($d['columns'][0])],
        ];
        foreach ($cases as [$name, $change]) {
            $descriptor = $this->descriptor();
            $change($descriptor);
            $this->assertFailure($name, $descriptor, 'DESCRIPTOR_SCHEMA_INVALID');
        }
    }

    public function test_positions_names_identifiers_types_and_bounds_fail(): void
    {
        $cases = [
            ['duplicate-position', fn (&$d) => $d['columns'][1]['position'] = 1], ['gap', fn (&$d) => $d['columns'][1]['position'] = 3],
            ['fractional-position', fn (&$d) => $d['columns'][0]['position'] = 1.5], ['fractional-shape', fn (&$d) => $d['columns'][0]['numeric_precision'] = 64.5],
            ['duplicate-name', fn (&$d) => $d['columns'][1]['name'] = 'id'],
            ['bad-identifier', fn (&$d) => $d['columns'][0]['name'] = 'Bad'], ['bad-type', fn (&$d) => $d['columns'][0]['type_name'] = 'INT8'],
            ['bad-bound', fn (&$d) => $d['columns'][0]['numeric_scale'] = 1000001], ['bad-null', fn (&$d) => $d['columns'][0]['nullable'] = 0],
        ];
        foreach ($cases as [$name, $change]) {
            $descriptor = $this->descriptor();
            $change($descriptor);
            $this->assertFailure($name, $descriptor, 'DESCRIPTOR_SCHEMA_INVALID');
        }
    }

    public function test_column_count_and_version_semantics_are_strict(): void
    {
        foreach ([0, 1001] as $count) {
            $descriptor = $this->descriptor();
            $descriptor['columns'] = array_fill(0, $count, $descriptor['columns'][0]);
            foreach ($descriptor['columns'] as $index => &$column) {
                $column['position'] = $index + 1;
                $column['name'] = 'column_'.$index;
            }
            unset($column);
            $this->assertFailure('columns-'.$count, $descriptor, 'DESCRIPTOR_SCHEMA_INVALID');
        }
        $this->assertSuccessful('version-int', $this->descriptor());
        $floatDescriptor = $this->descriptor();
        $floatDescriptor['schema_version'] = 1.0;
        $this->assertSuccessfulRaw('version-float', json_encode($floatDescriptor, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        foreach (['1', true, 2] as $version) {
            $descriptor = $this->descriptor();
            $descriptor['schema_version'] = $version;
            $this->assertFailure('bad-version-'.get_debug_type($version), $descriptor, 'DESCRIPTOR_SCHEMA_INVALID');
        }
    }

    public function test_file_guards_json_utf8_size_depth_and_production_are_sanitized(): void
    {
        Config::set('app.env', 'production');
        $missing = $this->directory.'/secret-name.json';
        $this->artisan('mapilio:fingerprint-import-schema', ['descriptor' => $missing])->assertFailed()->expectsOutput('PRODUCTION_BLOCKED')->doesntExpectOutput($missing);
        Config::set('app.env', 'testing');
        $this->assertFailureRaw('missing', $missing, 'DESCRIPTOR_UNREADABLE');
        $this->assertFailureRaw('directory', $this->directory, 'DESCRIPTOR_UNREADABLE');
        $invalid = $this->writeRaw('invalid.json', '{');
        $this->assertFailureRaw('invalid', $invalid, 'DESCRIPTOR_INVALID_JSON');
        $utf8 = $this->writeRaw('utf8.json', "{\xFF");
        $this->assertFailureRaw('utf8', $utf8, 'DESCRIPTOR_INVALID_JSON');
        $json = json_encode($this->descriptor(), JSON_THROW_ON_ERROR);
        $this->assertSuccessfulRaw('exact.json', $json.str_repeat(' ', ComputeImportSchemaFingerprint::MAX_BYTES - strlen($json)));
        $this->assertFailureRaw('large', $this->writeRaw('large.json', $json.str_repeat(' ', ComputeImportSchemaFingerprint::MAX_BYTES + 1 - strlen($json))), 'DESCRIPTOR_TOO_LARGE');
        symlink($invalid, $this->directory.'/linked.json');
        $this->assertFailureRaw('symlink', $this->directory.'/linked.json', 'DESCRIPTOR_UNREADABLE');
        $this->assertFailureRaw('depth', $this->writeRaw('depth.json', str_repeat('{"x":', 32).'1'.str_repeat('}', 32)), 'DESCRIPTOR_SCHEMA_INVALID');
    }

    public function test_schema_pattern_and_runtime_constants_agree(): void
    {
        $schema = json_decode(File::get(base_path('docs/database/import-schema-fingerprint.schema.json')), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(ComputeImportSchemaFingerprint::IDENTIFIER_PATTERN, $schema['properties']['schema']['pattern']);
        $this->assertSame(ComputeImportSchemaFingerprint::FINGERPRINT_ALGORITHM, $schema['properties']['fingerprint_algorithm']['const']);
        $this->assertSame(ComputeImportSchemaFingerprint::MAX_COLUMNS, $schema['properties']['columns']['maxItems']);
        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
    }

    /** @return array<string,mixed> */
    private function descriptor(): array
    {
        return ['schema_version' => 1, 'fingerprint_algorithm' => 'mapilio-schema-fingerprint-v1', 'engine' => 'postgresql', 'schema' => 'synthetic', 'table' => 'users', 'columns' => [
            ['position' => 1, 'name' => 'id', 'type_schema' => 'pg_catalog', 'type_name' => 'int8', 'nullable' => false, 'character_length' => null, 'numeric_precision' => 64, 'numeric_scale' => 0, 'datetime_precision' => null],
            ['position' => 2, 'name' => 'display_name', 'type_schema' => 'pg_catalog', 'type_name' => 'text', 'nullable' => true, 'character_length' => null, 'numeric_precision' => null, 'numeric_scale' => null, 'datetime_precision' => null],
        ]];
    }

    private function independentExpectedFingerprint(): string
    {
        $canonical = '{"schema_version":1,"fingerprint_algorithm":"mapilio-schema-fingerprint-v1","engine":"postgresql","schema":"synthetic","table":"users","columns":[{"position":1,"name":"id","type_schema":"pg_catalog","type_name":"int8","nullable":false,"character_length":null,"numeric_precision":64,"numeric_scale":0,"datetime_precision":null},{"position":2,"name":"display_name","type_schema":"pg_catalog","type_name":"text","nullable":true,"character_length":null,"numeric_precision":null,"numeric_scale":null,"datetime_precision":null}]}';
        $prefix = "mapilio-schema-fingerprint-v1\0";

        return hash('sha256', $prefix.$canonical);
    }

    /**
     * @param  array<string, mixed>  $descriptor
     */
    private function assertSuccessful(string $name, array $descriptor): void
    {
        $this->artisan('mapilio:fingerprint-import-schema', ['descriptor' => $this->write($name.'.json', $descriptor)])->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $descriptor
     */
    private function assertFailure(string $name, array $descriptor, string $reason): void
    {
        $this->assertFailureRaw($name, $this->write($name.'.json', $descriptor), $reason);
    }

    private function assertFailureRaw(string $name, string $path, string $reason): void
    {
        $this->artisan('mapilio:fingerprint-import-schema', ['descriptor' => $path])->assertFailed()->expectsOutput('SCHEMA_FINGERPRINT_FAILED')->expectsOutput($reason)->doesntExpectOutput($path)->doesntExpectOutput('synthetic')->doesntExpectOutput('users');
    }

    private function assertSuccessfulRaw(string $name, string $contents): void
    {
        $this->artisan('mapilio:fingerprint-import-schema', ['descriptor' => $this->writeRaw($name, $contents)])->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function write(string $name, array $value): string
    {
        return $this->writeRaw($name, json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function writeRaw(string $name, string $value): string
    {
        $path = $this->directory.'/'.$name;
        file_put_contents($path, $value);

        return $path;
    }
}

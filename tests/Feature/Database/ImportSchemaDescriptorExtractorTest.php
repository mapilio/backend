<?php

namespace Tests\Feature\Database;

use App\Domain\DataMigration\ExtractImportSchemaDescriptor;
use App\Domain\DataMigration\ImportSchemaDescriptorExtractionException;
use App\Domain\DataMigration\JsonPublisher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class ImportSchemaDescriptorExtractorTest extends TestCase
{
    private function configure(): void
    {
        Config::set('app.env', 'testing');
        Config::set('mapilio.import_schema_extractor.enabled', true);
        Config::set('mapilio.import_schema_extractor.source_connection', 'source_pgsql');
        Config::set('mapilio.import_schema_extractor.source_connection_allowlist', ['source_pgsql']);
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.source_pgsql', ['driver' => 'pgsql', 'host' => 'source.example.test', 'port' => 5432, 'database' => 'source_db']);
        Config::set('mapilio.import_schema_extractor.schema', 'legacy');
        Config::set('mapilio.import_schema_extractor.table', 'users');
        Config::set('mapilio.import_schema_extractor.output_directory', sys_get_temp_dir().'/unused');
    }

    public function test_production_disabled_confirmation_and_connection_guards_precede_side_effects(): void
    {
        $database = $this->createMock(DatabaseManager::class);
        $publisher = $this->createMock(JsonPublisher::class);
        $database->expects($this->never())->method('connection');
        $publisher->expects($this->never())->method('publish');
        $extractor = new ExtractImportSchemaDescriptor($database, $publisher);

        Config::set('app.env', 'production');
        $this->expectException(ImportSchemaDescriptorExtractionException::class);
        $this->expectExceptionMessage('PRODUCTION_BLOCKED');
        $extractor->run('descriptor.json', true);
    }

    public function test_all_pre_connection_guards_fail_without_source_resolution_or_publication(): void
    {
        $cases = [
            ['enabled' => false, 'confirmed' => true, 'reason' => 'EXTRACTOR_NOT_ENABLED'],
            ['enabled' => true, 'confirmed' => false, 'reason' => 'CONFIRMATION_REQUIRED'],
            ['enabled' => true, 'confirmed' => true, 'output' => '../escape.json', 'reason' => 'OUTPUT_INVALID'],
            ['enabled' => true, 'confirmed' => true, 'connection' => 'not-allowlisted', 'reason' => 'CONNECTION_NOT_ALLOWED'],
            ['enabled' => true, 'confirmed' => true, 'connection' => 'sqlite', 'reason' => 'CONNECTION_NOT_ALLOWED'],
            ['enabled' => true, 'confirmed' => true, 'schema' => 'pg_catalog', 'reason' => 'SCHEMA_NOT_ALLOWED'],
            ['enabled' => true, 'confirmed' => true, 'schema' => 'pg_toast_123', 'reason' => 'SCHEMA_NOT_ALLOWED'],
            ['enabled' => true, 'confirmed' => true, 'schema' => 'Bad', 'reason' => 'IDENTIFIER_INVALID'],
        ];
        foreach ($cases as $case) {
            $this->configure();
            Config::set('mapilio.import_schema_extractor.enabled', $case['enabled']);
            Config::set('mapilio.import_schema_extractor.schema', $case['schema'] ?? 'legacy');
            Config::set('mapilio.import_schema_extractor.source_connection', $case['connection'] ?? 'source_pgsql');
            $database = $this->createMock(DatabaseManager::class);
            $publisher = $this->createMock(JsonPublisher::class);
            $database->expects($this->never())->method('connection');
            $publisher->expects($this->never())->method('publish');
            try {
                (new ExtractImportSchemaDescriptor($database, $publisher))->run($case['output'] ?? 'descriptor.json', $case['confirmed']);
                $this->fail('Expected guard failure.');
            } catch (ImportSchemaDescriptorExtractionException $exception) {
                $this->assertSame($case['reason'], $exception->reasonCode);
            }
        }

        $this->configure();
        Config::set('database.default', 'source_pgsql');
        $database = $this->createMock(DatabaseManager::class);
        $publisher = $this->createMock(JsonPublisher::class);
        $database->expects($this->never())->method('connection');
        $publisher->expects($this->never())->method('publish');
        $this->expectExceptionMessage('CONNECTION_NOT_ALLOWED');
        (new ExtractImportSchemaDescriptor($database, $publisher))->run('descriptor.json', true);
    }

    public function test_cli_output_is_limited_to_sanitized_reason_codes(): void
    {
        Config::set('app.env', 'production');

        $this->artisan('mapilio:extract-import-schema', [
            '--output' => '/private/secret-path.json',
            '--confirm-read-only-source' => true,
        ])->assertFailed()
            ->expectsOutput('SCHEMA_DESCRIPTOR_EXTRACTION_FAILED')
            ->expectsOutput('PRODUCTION_BLOCKED')
            ->doesntExpectOutput('/private/secret-path.json')
            ->doesntExpectOutput('pg_catalog');
    }

    public function test_same_postgresql_endpoint_through_a_differently_named_default_alias_is_rejected_without_resolution(): void
    {
        foreach ([
            [
                'source' => ['driver' => 'pgsql', 'host' => 'source.example.test', 'port' => '5432', 'database' => 'source_db'],
                'default' => ['driver' => 'pgsql', 'host' => 'source.example.test', 'port' => '5432', 'database' => 'source_db'],
            ],
            [
                'source' => ['driver' => 'pgsql', 'url' => 'postgresql://different.example.test/other_db?host=source.example.test&port=5432&database=source_db'],
                'default' => ['driver' => 'pgsql', 'host' => 'source.example.test', 'port' => '5432', 'database' => 'source_db'],
            ],
            [
                'source' => ['driver' => 'pgsql', 'url' => 'postgresql://source.example.test/source%5Fdb'],
                'default' => ['driver' => 'pgsql', 'host' => 'source.example.test', 'port' => '5432', 'database' => 'source_db'],
            ],
            [
                'source' => ['driver' => 'pgsql', 'host' => 'source.example.test', 'port' => 5432, 'database' => 'source_db'],
                'default' => ['driver' => 'sqlite', 'url' => 'postgresql://source.example.test/source_db'],
            ],
        ] as $case) {
            $this->configure();
            Config::set('database.connections.source_pgsql', $case['source']);
            Config::set('database.default', 'application_alias');
            Config::set('database.connections.application_alias', $case['default']);
            $database = $this->createMock(DatabaseManager::class);
            $publisher = $this->createMock(JsonPublisher::class);
            $database->expects($this->never())->method('connection');
            $publisher->expects($this->never())->method('publish');

            try {
                (new ExtractImportSchemaDescriptor($database, $publisher))->run('alias.json', true);
                $this->fail('Expected endpoint alias rejection.');
            } catch (ImportSchemaDescriptorExtractionException $exception) {
                $this->assertSame('CONNECTION_NOT_ALLOWED', $exception->reasonCode);
            }
        }
    }

    public function test_malformed_source_or_default_postgresql_endpoint_is_rejected_without_resolution(): void
    {
        foreach ([
            ['source' => ['driver' => 'pgsql', 'host' => '', 'port' => 5432, 'database' => 'source_db'], 'default' => 'sqlite'],
            ['source' => ['driver' => 'pgsql', 'url' => 'not-a-url'], 'default' => 'sqlite'],
            ['source' => ['driver' => 'pgsql', 'host' => 'source.example.test', 'port' => 5432, 'database' => 'source_db'], 'default' => 'application_alias', 'default_config' => ['driver' => 'pgsql', 'host' => '']],
        ] as $case) {
            $this->configure();
            Config::set('database.connections.source_pgsql', $case['source']);
            Config::set('database.default', $case['default']);
            if (isset($case['default_config'])) {
                Config::set('database.connections.application_alias', $case['default_config']);
            }
            $database = $this->createMock(DatabaseManager::class);
            $publisher = $this->createMock(JsonPublisher::class);
            $database->expects($this->never())->method('connection');
            $publisher->expects($this->never())->method('publish');
            try {
                (new ExtractImportSchemaDescriptor($database, $publisher))->run('malformed.json', true);
                $this->fail('Expected endpoint rejection.');
            } catch (ImportSchemaDescriptorExtractionException $exception) {
                $this->assertSame('CONNECTION_NOT_ALLOWED', $exception->reasonCode);
            }
        }
    }

    public function test_success_is_read_only_catalog_only_and_maps_exact_descriptor_keys(): void
    {
        $this->configure();
        $events = [];
        $connection = $this->mockConnection($events);
        $publisher = $this->createMock(JsonPublisher::class);
        $publisher->expects($this->once())->method('publish')->with(
            sys_get_temp_dir().'/unused', 'descriptor.json', $this->callback(function (string $descriptor): bool {
                $descriptor = json_decode($descriptor, true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame(['schema_version', 'fingerprint_algorithm', 'engine', 'schema', 'table', 'columns'], array_keys($descriptor));
                $this->assertSame(['position', 'name', 'type_schema', 'type_name', 'nullable', 'character_length', 'numeric_precision', 'numeric_scale', 'datetime_precision'], array_keys($descriptor['columns'][0]));
                $this->assertSame(1, $descriptor['columns'][0]['position']);
                $this->assertSame('int4', $descriptor['columns'][0]['type_name']);

                return true;
            }),
        );
        $database = $this->createMock(DatabaseManager::class);
        $database->expects($this->once())->method('connection')->with('source_pgsql')->willReturn($connection);

        $result = (new ExtractImportSchemaDescriptor($database, $publisher))->run('descriptor.json', true);

        $this->assertSame(['SOURCE_READ_ONLY', 'TABLE_METADATA', 'DESCRIPTOR_WRITTEN'], $result->checks);
        $this->assertSame('set transaction read only', $events[0]);
        $this->assertStringContainsString('pg_catalog.pg_class', implode('\n', $events));
        $this->assertStringContainsString('pg_catalog.pg_attribute', implode('\n', $events));
        $this->assertStringContainsString('information_schema.columns', implode('\n', $events));
        $this->assertStringNotContainsString('select *', strtolower(implode('\n', $events)));
        $this->assertFalse(getenv('PGCONNECT_TIMEOUT'));
    }

    public function test_pgconnect_timeout_is_restored_after_success_and_connection_failure(): void
    {
        $this->configure();
        putenv('PGCONNECT_TIMEOUT=prior-value');
        $database = $this->createMock(DatabaseManager::class);
        $events = [];
        $connection = $this->mockConnection($events);
        $database->expects($this->once())->method('connection')->willReturn($connection);
        $publisher = $this->createMock(JsonPublisher::class);
        $publisher->expects($this->once())->method('publish');
        (new ExtractImportSchemaDescriptor($database, $publisher))->run('success.json', true);
        $this->assertSame('prior-value', getenv('PGCONNECT_TIMEOUT'));

        $failingDatabase = $this->createMock(DatabaseManager::class);
        $failingConnection = $this->createMock(Connection::class);
        $failingConnection->expects($this->once())->method('getPdo')->willThrowException(new \RuntimeException('synthetic'));
        $failingDatabase->expects($this->once())->method('connection')->willReturn($failingConnection);
        try {
            (new ExtractImportSchemaDescriptor($failingDatabase, $publisher))->run('failure.json', true);
            $this->fail('Expected connection failure.');
        } catch (ImportSchemaDescriptorExtractionException $exception) {
            $this->assertSame('CONNECTION_FAILED', $exception->reasonCode);
        }
        $this->assertSame('prior-value', getenv('PGCONNECT_TIMEOUT'));
        putenv('PGCONNECT_TIMEOUT');
    }

    public function test_read_only_verification_failure_prevents_catalog_reads_and_publication(): void
    {
        $this->configure();
        $events = [];
        $connection = $this->mockConnection($events, [], (object) ['setting' => 'off']);
        $database = $this->createMock(DatabaseManager::class);
        $database->expects($this->once())->method('connection')->willReturn($connection);
        $publisher = $this->createMock(JsonPublisher::class);
        $publisher->expects($this->never())->method('publish');
        try {
            (new ExtractImportSchemaDescriptor($database, $publisher))->run('readonly.json', true);
            $this->fail('Expected read-only verification failure.');
        } catch (ImportSchemaDescriptorExtractionException $exception) {
            $this->assertSame('READ_ONLY_UNVERIFIED', $exception->reasonCode);
        }
        $this->assertSame([], array_filter($events, static fn (string $sql): bool => str_contains($sql, 'pg_catalog')));
    }

    public function test_metadata_rejections_are_sanitized_and_never_published(): void
    {
        foreach ([
            ['data_type' => 'ARRAY', 'udt_name' => '_int4'],
            ['data_type' => 'USER-DEFINED', 'udt_name' => 'custom_type'],
            ['data_type' => 'integer', 'udt_schema' => 'custom_schema', 'udt_name' => 'int4'],
            ['data_type' => 'integer', 'udt_name' => 'int4', 'is_generated' => 'ALWAYS'],
            ['data_type' => 'integer', 'udt_name' => 'int4', 'identity_generation' => 'BY DEFAULT'],
        ] as $overrides) {
            $this->configure();
            $publisher = $this->createMock(JsonPublisher::class);
            $publisher->expects($this->never())->method('publish');
            $database = $this->createMock(DatabaseManager::class);
            $events = [];
            $database->expects($this->once())->method('connection')->willReturn($this->mockConnection($events, $overrides));
            try {
                (new ExtractImportSchemaDescriptor($database, $publisher))->run('rejected.json', true);
                $this->fail('Expected metadata rejection.');
            } catch (ImportSchemaDescriptorExtractionException $exception) {
                $this->assertSame('COLUMN_METADATA_INVALID', $exception->reasonCode);
            }
        }
    }

    public function test_table_count_position_duplicate_limit_and_malformed_metadata_fail_closed(): void
    {
        $cases = [
            ['table' => null, 'reason' => 'TABLE_NOT_ORDINARY_BASE'],
            ['count' => (object) ['aggregate' => '2'], 'reason' => 'COLUMN_METADATA_INVALID'],
            ['rows' => [['ordinal_position' => 2]], 'reason' => 'COLUMN_METADATA_INVALID'],
            ['rows' => [['column_name' => 'id'], ['column_name' => 'id']], 'count' => (object) ['aggregate' => 2], 'reason' => 'COLUMN_METADATA_INVALID'],
            ['count' => (object) ['aggregate' => '1001'], 'reason' => 'COLUMN_METADATA_INVALID'],
            ['overrides' => ['numeric_precision' => '1.5'], 'reason' => 'COLUMN_METADATA_INVALID'],
            ['overrides' => ['__missing' => 'numeric_precision'], 'reason' => 'COLUMN_METADATA_INVALID'],
            ['overrides' => ['is_nullable' => 'MAYBE'], 'reason' => 'COLUMN_METADATA_INVALID'],
            ['overrides' => ['column_name' => 42], 'reason' => 'COLUMN_METADATA_INVALID'],
        ];
        foreach ($cases as $case) {
            $this->configure();
            $events = [];
            $tableRow = array_key_exists('table', $case) ? $case['table'] : (object) ['oid' => 42];
            $countRow = $case['count'] ?? (object) ['aggregate' => 1];
            $missingTable = array_key_exists('table', $case);
            $connection = $this->mockConnection($events, $case['overrides'] ?? [], null, $tableRow, $countRow, $case['rows'] ?? null, $missingTable);
            $database = $this->createMock(DatabaseManager::class);
            $database->expects($this->once())->method('connection')->willReturn($connection);
            $publisher = $this->createMock(JsonPublisher::class);
            $publisher->expects($this->never())->method('publish');
            try {
                (new ExtractImportSchemaDescriptor($database, $publisher))->run('invalid.json', true);
                $this->fail('Expected metadata failure.');
            } catch (ImportSchemaDescriptorExtractionException $exception) {
                $this->assertSame($case['reason'], $exception->reasonCode);
            }
        }
    }

    /** @return Connection&MockObject */
    private function mockConnection(array &$events, array $overrides = [], ?object $readOnly = null, ?object $tableRow = null, ?object $countRow = null, ?array $rows = null, bool $missingTable = false): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn(new \PDO('sqlite::memory:'));
        $connection->method('transaction')->willReturnCallback(fn (callable $callback): array => $callback($connection));
        $connection->method('statement')->willReturnCallback(function (string $sql) use (&$events): bool {
            $events[] = $sql;

            return true;
        });
        $connection->method('selectOne')->willReturnCallback(function (string $sql) use (&$events, $readOnly, $tableRow, $countRow, $missingTable): object|null {
            $events[] = $sql;
            if (str_contains($sql, 'transaction_read_only')) {
                return $readOnly ?? (object) ['setting' => 'on'];
            }
            if (str_contains($sql, 'pg_class')) {
                return $missingTable ? null : ($tableRow ?? (object) ['oid' => 42]);
            }

            return $countRow ?? (object) ['aggregate' => 1];
        });
        $connection->method('select')->willReturnCallback(function (string $sql) use (&$events, $overrides, $rows): array {
            $events[] = $sql;
            $missing = $overrides['__missing'] ?? null;
            unset($overrides['__missing']);
            $defaultRows = [(object) array_merge([
                'ordinal_position' => 1, 'column_name' => 'id', 'udt_schema' => 'pg_catalog', 'udt_name' => 'int4', 'is_nullable' => 'NO',
                'character_maximum_length' => null, 'numeric_precision' => 32, 'numeric_scale' => 0, 'datetime_precision' => null,
                'data_type' => 'integer', 'domain_schema' => null, 'domain_name' => null, 'is_generated' => 'NEVER', 'identity_generation' => null,
            ], $overrides)];
            if (is_string($missing)) {
                unset($defaultRows[0]->{$missing});
            }

            return $rows === null ? $defaultRows : array_map(static fn (array $row): object => (object) array_merge([
                'ordinal_position' => 1, 'column_name' => 'id', 'udt_schema' => 'pg_catalog', 'udt_name' => 'int4', 'is_nullable' => 'NO',
                'character_maximum_length' => null, 'numeric_precision' => 32, 'numeric_scale' => 0, 'datetime_precision' => null,
                'data_type' => 'integer', 'domain_schema' => null, 'domain_name' => null, 'is_generated' => 'NEVER', 'identity_generation' => null,
            ], $row), $rows);
        });

        return $connection;
    }
}

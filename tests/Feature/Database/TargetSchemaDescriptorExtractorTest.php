<?php

namespace Tests\Feature\Database;

use App\Domain\DataMigration\ExtractTargetSchemaDescriptor;
use App\Domain\DataMigration\ImportSchemaDescriptorExtractionException;
use App\Domain\DataMigration\JsonPublisher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class TargetSchemaDescriptorExtractorTest extends TestCase
{
    private function configure(): void
    {
        Config::set('app.env', 'testing');
        Config::set('mapilio.target_schema_extractor.enabled', true);
        Config::set('mapilio.target_schema_extractor.target_connection', 'pgsql');
        Config::set('mapilio.target_schema_extractor.schema', 'modern');
        Config::set('mapilio.target_schema_extractor.table', 'users');
        Config::set('mapilio.target_schema_extractor.output_directory', sys_get_temp_dir().'/target-schema');
        Config::set('database.connections.pgsql', ['driver' => 'pgsql', 'host' => 'target.example.test', 'port' => 5432, 'database' => 'modern_db']);
        Config::set('database.connections.legacy_pgsql', ['driver' => 'pgsql', 'host' => 'legacy.example.test', 'port' => 5432, 'database' => 'legacy_db']);
    }

    public function test_production_disabled_and_confirmation_guards_have_no_side_effects(): void
    {
        $database = $this->createMock(DatabaseManager::class);
        $publisher = $this->createMock(JsonPublisher::class);
        $database->expects($this->never())->method('connection');
        $publisher->expects($this->never())->method('publish');
        $extractor = new ExtractTargetSchemaDescriptor($database, $publisher);

        foreach ([
            ['env' => 'production', 'enabled' => true, 'confirmed' => true, 'reason' => 'PRODUCTION_BLOCKED'],
            ['env' => 'testing', 'enabled' => false, 'confirmed' => true, 'reason' => 'EXTRACTOR_NOT_ENABLED'],
            ['env' => 'testing', 'enabled' => true, 'confirmed' => false, 'reason' => 'CONFIRMATION_REQUIRED'],
        ] as $case) {
            $this->configure();
            Config::set('app.env', $case['env']);
            Config::set('mapilio.target_schema_extractor.enabled', $case['enabled']);
            try {
                $extractor->run('target.json', $case['confirmed']);
                $this->fail('Expected target guard.');
            } catch (ImportSchemaDescriptorExtractionException $exception) {
                $this->assertSame($case['reason'], $exception->reasonCode);
            }
        }
    }

    public function test_only_canonical_target_is_accepted_and_default_name_is_not_a_guard(): void
    {
        $this->configure();
        Config::set('database.default', 'pgsql');
        $database = $this->createMock(DatabaseManager::class);
        $publisher = $this->createMock(JsonPublisher::class);
        $database->expects($this->never())->method('connection');
        $publisher->expects($this->never())->method('publish');
        foreach (['', 'legacy_pgsql', 'sqlite', 'target_pgsql'] as $name) {
            Config::set('mapilio.target_schema_extractor.target_connection', $name);
            try {
                (new ExtractTargetSchemaDescriptor($database, $publisher))->run('target.json', true);
                $this->fail('Expected connection policy rejection.');
            } catch (ImportSchemaDescriptorExtractionException $exception) {
                $this->assertSame('CONNECTION_NOT_ALLOWED', $exception->reasonCode);
            }
        }
    }

    public function test_legacy_endpoint_collision_uses_url_normalization_without_legacy_resolution(): void
    {
        $this->configure();
        Config::set('database.connections.pgsql', ['driver' => 'pgsql', 'url' => 'postgresql://different.example.test/other?host=target.example.test&port=5432&database=modern%5Fdb']);
        Config::set('database.connections.legacy_pgsql', ['driver' => 'pgsql', 'host' => 'target.example.test', 'port' => '5432', 'database' => 'modern_db']);
        $database = $this->createMock(DatabaseManager::class);
        $publisher = $this->createMock(JsonPublisher::class);
        $database->expects($this->never())->method('connection');
        $publisher->expects($this->never())->method('publish');
        try {
            (new ExtractTargetSchemaDescriptor($database, $publisher))->run('target.json', true);
            $this->fail('Expected endpoint collision.');
        } catch (ImportSchemaDescriptorExtractionException $exception) {
            $this->assertSame('CONNECTION_NOT_ALLOWED', $exception->reasonCode);
        }
    }

    public function test_missing_malformed_or_non_postgresql_legacy_config_fails_before_target_resolution(): void
    {
        foreach ([
            null,
            ['driver' => 'mysql', 'host' => 'legacy.example.test', 'port' => 3306, 'database' => 'legacy_db'],
            ['driver' => 'pgsql', 'url' => 'not-a-url'],
            ['driver' => 'pgsql', 'host' => '', 'port' => 5432, 'database' => 'legacy_db'],
            ['driver' => 'pgsql', 'host' => 'legacy.example.test', 'port' => 0, 'database' => 'legacy_db'],
            ['driver' => 'pgsql', 'host' => 'legacy.example.test', 'port' => 5432, 'database' => ''],
            ['driver' => 'pgsql', 'host' => 'legacy\\example.test', 'port' => 5432, 'database' => 'legacy_db'],
            ['driver' => 'pgsql', 'host' => 'legacy.example.test', 'port' => 5432, 'database' => 'legacy\\db'],
        ] as $legacyConfig) {
            $this->configure();
            Config::set('database.connections.legacy_pgsql', $legacyConfig);
            $database = $this->createMock(DatabaseManager::class);
            $publisher = $this->createMock(JsonPublisher::class);
            $database->expects($this->never())->method('connection');
            $publisher->expects($this->never())->method('publish');
            try {
                (new ExtractTargetSchemaDescriptor($database, $publisher))->run('target.json', true);
                $this->fail('Expected legacy endpoint rejection.');
            } catch (ImportSchemaDescriptorExtractionException $exception) {
                $this->assertSame('CONNECTION_NOT_ALLOWED', $exception->reasonCode);
            }
        }
    }

    public function test_trailing_dns_dot_is_canonicalized_and_ambiguous_hosts_are_rejected(): void
    {
        $this->configure();
        Config::set('database.connections.pgsql', ['driver' => 'pgsql', 'host' => 'target.example.test', 'port' => 5432, 'database' => 'modern_db']);
        Config::set('database.connections.legacy_pgsql', ['driver' => 'pgsql', 'host' => 'target.example.test.', 'port' => 5432, 'database' => 'modern_db']);
        $database = $this->createMock(DatabaseManager::class);
        $publisher = $this->createMock(JsonPublisher::class);
        $database->expects($this->never())->method('connection');
        $publisher->expects($this->never())->method('publish');
        $this->expectExceptionMessage('CONNECTION_NOT_ALLOWED');
        (new ExtractTargetSchemaDescriptor($database, $publisher))->run('target.json', true);
    }

    public function test_success_reads_only_catalog_metadata_and_publishes_descriptor_v1(): void
    {
        $this->configure();
        $events = [];
        $connection = $this->mockConnection($events);
        $database = $this->createMock(DatabaseManager::class);
        $database->expects($this->once())->method('connection')->with('pgsql')->willReturn($connection);
        $publisher = $this->createMock(JsonPublisher::class);
        $publisher->expects($this->once())->method('publish')->with(sys_get_temp_dir().'/target-schema', 'target.json', $this->callback(function (string $json): bool {
            $descriptor = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return array_keys($descriptor) === ['schema_version', 'fingerprint_algorithm', 'engine', 'schema', 'table', 'columns']
                && $descriptor['engine'] === 'postgresql'
                && array_keys($descriptor['columns'][0]) === ['position', 'name', 'type_schema', 'type_name', 'nullable', 'character_length', 'numeric_precision', 'numeric_scale', 'datetime_precision'];
        }));
        $result = (new ExtractTargetSchemaDescriptor($database, $publisher))->run('target.json', true);

        $this->assertSame(['TARGET_READ_ONLY', 'TABLE_METADATA', 'DESCRIPTOR_WRITTEN'], $result->checks);
        $this->assertSame('set transaction read only', $events[0]);
        $this->assertStringContainsString('pg_catalog.pg_class', strtolower(implode('\n', $events)));
        $this->assertStringNotContainsString('select *', strtolower(implode('\n', $events)));
        foreach ($events as $sql) {
            if (str_starts_with(strtolower(trim($sql)), 'select')) {
                $this->assertMatchesRegularExpression('/^select (?:current_setting\(\'transaction_read_only\'\)|c\.oid .*pg_catalog\.pg_class|count\(\*\).*pg_catalog\.pg_attribute|ordinal_position, .*information_schema\.columns)/is', trim($sql));
            }
        }
    }

    public function test_timeout_is_restored_after_success_connection_failure_and_query_failure(): void
    {
        $this->configure();
        putenv('PGCONNECT_TIMEOUT=prior-value');
        $publisher = $this->createMock(JsonPublisher::class);
        $publisher->expects($this->once())->method('publish');
        $database = $this->createMock(DatabaseManager::class);
        $events = [];
        $database->expects($this->once())->method('connection')->willReturn($this->mockConnection($events, expectedTimeout: 'prior-value'));
        (new ExtractTargetSchemaDescriptor($database, $publisher))->run('success.json', true);
        $this->assertSame('prior-value', getenv('PGCONNECT_TIMEOUT'));

        $failingDatabase = $this->createMock(DatabaseManager::class);
        $failingConnection = $this->createMock(Connection::class);
        $failingConnection->expects($this->once())->method('getPdo')->willThrowException(new \RuntimeException('synthetic'));
        $failingDatabase->expects($this->once())->method('connection')->willReturn($failingConnection);
        try {
            (new ExtractTargetSchemaDescriptor($failingDatabase, $publisher))->run('connection.json', true);
            $this->fail('Expected connection failure.');
        } catch (ImportSchemaDescriptorExtractionException $exception) {
            $this->assertSame('CONNECTION_FAILED', $exception->reasonCode);
        }
        $this->assertSame('prior-value', getenv('PGCONNECT_TIMEOUT'));

        $queryDatabase = $this->createMock(DatabaseManager::class);
        $queryEvents = [];
        $queryDatabase->expects($this->once())->method('connection')->willReturn($this->mockConnection($queryEvents, 'query_failure', 'prior-value'));
        try {
            (new ExtractTargetSchemaDescriptor($queryDatabase, $publisher))->run('query.json', true);
            $this->fail('Expected query failure.');
        } catch (ImportSchemaDescriptorExtractionException $exception) {
            $this->assertSame('QUERY_FAILED', $exception->reasonCode);
        }
        $this->assertSame('prior-value', getenv('PGCONNECT_TIMEOUT'));
        putenv('PGCONNECT_TIMEOUT');
    }

    public function test_read_only_and_metadata_failures_do_not_publish(): void
    {
        foreach (['read_only_failure' => 'READ_ONLY_UNVERIFIED', 'metadata_failure' => 'COLUMN_METADATA_INVALID'] as $mode => $reason) {
            $this->configure();
            $events = [];
            $database = $this->createMock(DatabaseManager::class);
            $database->expects($this->once())->method('connection')->willReturn($this->mockConnection($events, $mode));
            $publisher = $this->createMock(JsonPublisher::class);
            $publisher->expects($this->never())->method('publish');
            try {
                (new ExtractTargetSchemaDescriptor($database, $publisher))->run('failure.json', true);
                $this->fail('Expected metadata failure.');
            } catch (ImportSchemaDescriptorExtractionException $exception) {
                $this->assertSame($reason, $exception->reasonCode);
            }
        }
    }

    public function test_cli_errors_are_sanitized(): void
    {
        Config::set('app.env', 'production');
        $this->artisan('mapilio:extract-target-schema', ['--output' => '/private/secret.json', '--confirm-read-only-target' => true])
            ->assertFailed()
            ->expectsOutput('SCHEMA_DESCRIPTOR_EXTRACTION_FAILED')
            ->expectsOutput('PRODUCTION_BLOCKED')
            ->doesntExpectOutput('/private/secret.json');
    }

    /**
     * @param  list<string>  $events
     * @return Connection&MockObject
     */
    private function mockConnection(array &$events, string $mode = 'success', ?string $expectedTimeout = null): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn(new \PDO('sqlite::memory:'));
        $connection->method('transaction')->willReturnCallback(function (callable $callback) use ($connection, $expectedTimeout): array {
            if ($expectedTimeout !== null) {
                $this->assertSame($expectedTimeout, getenv('PGCONNECT_TIMEOUT'));
            }

            return $callback($connection);
        });
        $connection->method('statement')->willReturnCallback(function (string $sql) use (&$events): bool {
            $events[] = $sql;

            return true;
        });
        $connection->method('selectOne')->willReturnCallback(function (string $sql) use (&$events, $mode): object {
            $events[] = $sql;
            if ($mode === 'query_failure') {
                throw new \RuntimeException('synthetic');
            }
            if (str_contains($sql, 'transaction_read_only')) {
                return (object) ['setting' => $mode === 'read_only_failure' ? 'off' : 'on'];
            }
            if ($mode === 'metadata_failure' && str_contains($sql, 'pg_class')) {
                return (object) ['oid' => 'not-an-integer'];
            }
            if (str_contains($sql, 'pg_class')) {
                return (object) ['oid' => 42];
            }

            return (object) ['aggregate' => 1];
        });
        $connection->method('select')->willReturnCallback(function (string $sql) use (&$events): array {
            $events[] = $sql;

            return [(object) ['ordinal_position' => 1, 'column_name' => 'id', 'udt_schema' => 'pg_catalog', 'udt_name' => 'int4', 'is_nullable' => 'NO', 'character_maximum_length' => null, 'numeric_precision' => 32, 'numeric_scale' => 0, 'datetime_precision' => null, 'data_type' => 'integer', 'domain_schema' => null, 'domain_name' => null, 'is_generated' => 'NEVER', 'identity_generation' => null]];
        });

        return $connection;
    }
}

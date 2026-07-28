<?php

namespace Tests\Feature\Database;

use App\Domain\DataMigration\LegacyImportPreflightException;
use App\Domain\DataMigration\RunLegacyImportPreflight;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LegacyImportPreflightTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputDirectory = sys_get_temp_dir().'/mapilio-legacy-preflight-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->outputDirectory, 0700, true);
        Config::set('app.env', 'testing');
        Config::set('mapilio.legacy_import_preflight.enabled', true);
        Config::set('mapilio.legacy_import_preflight.table_allowlist', ['legacy_users', 'legacy_tracks']);
        Config::set('mapilio.legacy_import_preflight.output_directory', $this->outputDirectory);
        Config::set('mapilio.legacy_database_connection', 'legacy_synthetic');
        Config::set('database.connections.legacy_synthetic', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('legacy_synthetic');

        $connection = DB::connection('legacy_synthetic');
        $connection->statement('create table legacy_users (id integer, email text)');
        $connection->statement('create table legacy_tracks (id integer, latitude real, longitude real)');
        $connection->insert('insert into legacy_users (id, email) values (?, ?)', [1, 'synthetic@example.test']);
        $connection->insert('insert into legacy_users (id, email) values (?, ?)', [2, 'synthetic2@example.test']);
        $connection->insert('insert into legacy_tracks (id, latitude, longitude) values (?, ?, ?)', [1, 41.0, 29.0]);
    }

    protected function tearDown(): void
    {
        DB::purge('legacy_synthetic');
        File::deleteDirectory($this->outputDirectory);

        parent::tearDown();
    }

    public function test_disabled_preflight_fails_closed(): void
    {
        Config::set('mapilio.legacy_import_preflight.enabled', false);

        $this->artisan('mapilio:legacy-import-preflight', [
            '--output' => 'manifest.json',
            '--confirm-read-only-source' => true,
        ])->assertFailed()->expectsOutput('PREFLIGHT_NOT_ENABLED');
    }

    public function test_production_is_refused_before_resolving_a_connection(): void
    {
        Config::set('app.env', 'production');
        Config::set('mapilio.legacy_database_connection', 'connection_that_does_not_exist');

        $this->artisan('mapilio:legacy-import-preflight', [
            '--output' => 'manifest.json',
            '--confirm-read-only-source' => true,
        ])->assertFailed()->expectsOutput('PRODUCTION_BLOCKED');
    }

    public function test_confirmation_allowlist_connection_and_filename_guards_are_fail_closed(): void
    {
        $cases = [
            [[], 'TABLE_ALLOWLIST_EMPTY'],
            [['legacy.users'], 'TABLE_ALLOWLIST_INVALID'],
            [['legacy_users;drop'], 'TABLE_ALLOWLIST_INVALID'],
            [array_fill(0, 51, 'legacy_users'), 'TABLE_ALLOWLIST_INVALID'],
        ];

        $this->artisan('mapilio:legacy-import-preflight', ['--output' => 'manifest.json'])
            ->assertFailed()->expectsOutput('CONFIRMATION_REQUIRED');

        foreach ($cases as [$allowlist, $reason]) {
            Config::set('mapilio.legacy_import_preflight.table_allowlist', $allowlist);
            $this->artisan('mapilio:legacy-import-preflight', [
                '--output' => 'manifest.json',
                '--confirm-read-only-source' => true,
            ])->assertFailed()->expectsOutput($reason);
        }
    }

    public function test_unknown_or_disallowed_connections_fail_without_connection_resolution(): void
    {
        foreach ([['missing_connection', 'CONNECTION_NOT_ALLOWED'], ['mysql', 'CONNECTION_NOT_ALLOWED']] as [$name, $reason]) {
            Config::set('mapilio.legacy_database_connection', $name);

            $this->artisan('mapilio:legacy-import-preflight', [
                '--output' => 'manifest.json',
                '--confirm-read-only-source' => true,
            ])->assertFailed()->expectsOutput($reason);
        }
    }

    public function test_output_name_must_be_a_new_json_basename_and_symlinks_are_rejected(): void
    {
        foreach (['../manifest.json', 'nested/manifest.json', 'manifest.txt', 'manifest.php', 'Manifest.json'] as $filename) {
            $this->artisan('mapilio:legacy-import-preflight', [
                '--output' => $filename,
                '--confirm-read-only-source' => true,
            ])->assertFailed()->expectsOutput('OUTPUT_INVALID');
        }

        Config::set('mapilio.legacy_import_preflight.table_allowlist', ['legacy_users', 'legacy_users']);
        $this->artisan('mapilio:legacy-import-preflight', [
            '--output' => 'duplicate.json',
            '--confirm-read-only-source' => true,
        ])->assertFailed()->expectsOutput('TABLE_ALLOWLIST_INVALID');

        Config::set('mapilio.legacy_import_preflight.table_allowlist', ['Legacy_users']);
        $this->artisan('mapilio:legacy-import-preflight', [
            '--output' => 'uppercase.json',
            '--confirm-read-only-source' => true,
        ])->assertFailed()->expectsOutput('TABLE_ALLOWLIST_INVALID');

        Config::set('mapilio.legacy_import_preflight.table_allowlist', ['legacy_users', 'legacy_tracks']);

        File::put($this->outputDirectory.'/existing.json', '{}');
        $this->artisan('mapilio:legacy-import-preflight', [
            '--output' => 'existing.json',
            '--confirm-read-only-source' => true,
        ])->assertFailed()->expectsOutput('OUTPUT_EXISTS');

        symlink($this->outputDirectory.'/existing.json', $this->outputDirectory.'/linked.json');
        $this->artisan('mapilio:legacy-import-preflight', [
            '--output' => 'linked.json',
            '--confirm-read-only-source' => true,
        ])->assertFailed()->expectsOutput('OUTPUT_EXISTS');
    }

    public function test_success_writes_the_exact_non_sensitive_manifest_and_does_not_write_database_data(): void
    {
        $beforeUsers = DB::connection('legacy_synthetic')->table('legacy_users')->count();
        $beforeTracks = DB::connection('legacy_synthetic')->table('legacy_tracks')->count();

        $this->artisan('mapilio:legacy-import-preflight', [
            '--output' => 'manifest.json',
            '--confirm-read-only-source' => true,
        ])->assertSuccessful()
            ->doesntExpectOutput('legacy_users')
            ->doesntExpectOutput('synthetic@example.test')
            ->doesntExpectOutput($this->outputDirectory);

        $manifest = json_decode(File::get($this->outputDirectory.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0600, fileperms($this->outputDirectory.'/manifest.json') & 0777);
        $this->assertSame(['schema_version', 'generated_at', 'run_id', 'environment_class', 'driver', 'connection_name', 'tables'], array_keys($manifest));
        $this->assertSame(1, $manifest['schema_version']);
        $this->assertSame('testing', $manifest['environment_class']);
        $this->assertSame('sqlite', $manifest['driver']);
        $this->assertSame('legacy_synthetic', $manifest['connection_name']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $manifest['run_id']);
        $this->assertSame([
            ['table' => 'legacy_users', 'exists' => true, 'column_count' => 2, 'row_count' => 2, 'status' => 'PASS', 'reason_code' => 'OK'],
            ['table' => 'legacy_tracks', 'exists' => true, 'column_count' => 3, 'row_count' => 1, 'status' => 'PASS', 'reason_code' => 'OK'],
        ], $manifest['tables']);
        $this->assertSame($beforeUsers, DB::connection('legacy_synthetic')->table('legacy_users')->count());
        $this->assertSame($beforeTracks, DB::connection('legacy_synthetic')->table('legacy_tracks')->count());

        $this->artisan('mapilio:legacy-import-preflight', [
            '--output' => 'manifest.json',
            '--confirm-read-only-source' => true,
        ])->assertFailed()->expectsOutput('OUTPUT_EXISTS');
    }

    public function test_private_modes_are_enforced_and_query_failure_cleans_the_reservation(): void
    {
        $this->assertSame(0700, fileperms($this->outputDirectory) & 0777);

        $insecureDirectory = sys_get_temp_dir().'/mapilio-legacy-preflight-insecure-'.bin2hex(random_bytes(8));
        File::makeDirectory($insecureDirectory, 0750, true);
        Config::set('mapilio.legacy_import_preflight.output_directory', $insecureDirectory);
        Config::set('mapilio.legacy_database_connection', 'legacy_synthetic');
        $this->artisan('mapilio:legacy-import-preflight', [
            '--output' => 'insecure.json',
            '--confirm-read-only-source' => true,
        ])->assertFailed()->expectsOutput('OUTPUT_INVALID');
        $this->assertFileDoesNotExist($insecureDirectory.'/insecure.json');
        File::deleteDirectory($insecureDirectory);

        Config::set('mapilio.legacy_import_preflight.output_directory', $this->outputDirectory);
        $database = $this->createMock(DatabaseManager::class);
        $database->expects($this->once())->method('connection')->with('legacy_synthetic')
            ->willReturnCallback(function (): never {
                File::put($this->outputDirectory.'/query-failure.json', 'foreign replacement');
                throw new \RuntimeException('synthetic query failure');
            });
        try {
            (new RunLegacyImportPreflight($database))->run('query-failure.json', true);
            $this->fail('Expected the synthetic query failure to be sanitized.');
        } catch (LegacyImportPreflightException $exception) {
            $this->assertSame('QUERY_FAILED', $exception->reasonCode);
        }
        $this->assertFileExists($this->outputDirectory.'/query-failure.json');
        $this->assertSame('foreign replacement', File::get($this->outputDirectory.'/query-failure.json'));
        $this->assertSame([], File::glob($this->outputDirectory.'/*.tmp'));
    }

    public function test_replacement_between_inspection_and_publish_fails_without_overwriting_foreign_target(): void
    {
        $connection = $this->createMock(Connection::class);
        $selectOneCalls = 0;
        $connection->method('selectOne')->willReturnCallback(function (string $sql) use (&$selectOneCalls): object {
            $selectOneCalls++;
            if ($selectOneCalls === 4) {
                File::put($this->outputDirectory.'/publish-race.json', 'foreign publish replacement');
            }

            return str_contains($sql, 'sqlite_master')
                ? (object) ['name' => 'legacy_users']
                : (object) ['aggregate' => 1];
        });
        $connection->method('select')->willReturnCallback(static fn (string $sql): array => [
            (object) ['name' => 'id'],
        ]);
        $database = $this->createMock(DatabaseManager::class);
        $database->expects($this->once())->method('connection')->with('legacy_synthetic')->willReturn($connection);

        try {
            (new RunLegacyImportPreflight($database))->run('publish-race.json', true);
            $this->fail('Expected a publish race to fail closed.');
        } catch (LegacyImportPreflightException $exception) {
            $this->assertSame('OUTPUT_EXISTS', $exception->reasonCode);
        }

        $this->assertSame('foreign publish replacement', File::get($this->outputDirectory.'/publish-race.json'));
    }

    public function test_existing_target_is_refused_before_connection_resolution(): void
    {
        File::put($this->outputDirectory.'/already-there.json', 'existing');
        Config::set('mapilio.legacy_database_connection', 'legacy_synthetic');

        $this->artisan('mapilio:legacy-import-preflight', [
            '--output' => 'already-there.json',
            '--confirm-read-only-source' => true,
        ])->assertFailed()->expectsOutput('OUTPUT_EXISTS');
        $this->assertSame('existing', File::get($this->outputDirectory.'/already-there.json'));
    }

    public function test_postgresql_read_only_and_timeout_ordering_is_mocked_without_network_access(): void
    {
        Config::set('mapilio.legacy_database_connection', 'legacy_postgresql_synthetic');
        Config::set('database.connections.legacy_postgresql_synthetic', ['driver' => 'pgsql']);
        Config::set('mapilio.legacy_import_preflight.postgresql.connect_timeout_seconds', 7);
        Config::set('mapilio.legacy_import_preflight.postgresql.statement_timeout_ms', 5000);
        Config::set('mapilio.legacy_import_preflight.postgresql.lock_timeout_ms', 1000);
        Config::set('mapilio.legacy_import_preflight.postgresql.max_runtime_ms', 3000);

        $events = [];
        $previousConnectTimeout = getenv('PGCONNECT_TIMEOUT');
        putenv('PGCONNECT_TIMEOUT=previous-value');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('transaction')->willReturnCallback(function (callable $callback) use ($connection, &$events): array {
            $events[] = 'transaction';
            $this->assertSame('previous-value', getenv('PGCONNECT_TIMEOUT'));

            return $callback($connection);
        });
        $connection->expects($this->once())->method('getPdo')->willReturnCallback(function (): \PDO {
            $this->assertSame('7', getenv('PGCONNECT_TIMEOUT'));

            return new \PDO('sqlite::memory:');
        });
        $connection->method('statement')->willReturnCallback(function (string $sql) use (&$events): bool {
            $events[] = $sql;

            return true;
        });
        $connection->method('selectOne')->willReturnCallback(function (string $sql) use (&$events): object {
            $events[] = $sql;
            if (str_contains($sql, 'transaction_read_only')) {
                return (object) ['setting' => 'on'];
            }
            if (str_contains($sql, 'information_schema.tables')) {
                return (object) ['table_name' => 'legacy_users'];
            }
            if (str_contains($sql, 'information_schema.columns')) {
                return (object) ['aggregate' => 2];
            }

            return (object) ['aggregate' => 2];
        });

        $database = $this->createMock(DatabaseManager::class);
        $database->expects($this->once())->method('connection')->with('legacy_postgresql_synthetic')
            ->willReturnCallback(function (string $name) use ($connection): Connection {
                $this->assertSame('legacy_postgresql_synthetic', $name);
                $this->assertSame('7', getenv('PGCONNECT_TIMEOUT'));

                return $connection;
            });
        try {
            $result = (new RunLegacyImportPreflight($database))->run('postgres.json', true);
            $this->assertSame('previous-value', getenv('PGCONNECT_TIMEOUT'));
        } finally {
            if ($previousConnectTimeout === false) {
                putenv('PGCONNECT_TIMEOUT');
            } else {
                putenv('PGCONNECT_TIMEOUT='.$previousConnectTimeout);
            }
        }

        $this->assertTrue($result->successful);
        $this->assertSame('set transaction read only', $events[1]);
        $this->assertSame("set local lock_timeout = '1000ms'", $events[2]);
        $initialStatementTimeout = (int) preg_replace('/[^0-9]/', '', $events[3]);
        $this->assertGreaterThan(0, $initialStatementTimeout);
        $this->assertLessThanOrEqual(3000, $initialStatementTimeout);
        $this->assertStringContainsString('transaction_read_only', $events[4]);
        $statementTimeouts = array_values(array_filter($events, static fn (string $event): bool => str_starts_with($event, 'set local statement_timeout')));
        foreach ($statementTimeouts as $statementTimeout) {
            $this->assertLessThanOrEqual(3000, (int) preg_replace('/[^0-9]/', '', $statementTimeout));
        }
        foreach ($events as $index => $event) {
            if (str_starts_with($event, 'select ')) {
                $this->assertSame("set local lock_timeout = '1000ms'", $events[$index - 2]);
                $this->assertStringStartsWith('set local statement_timeout', $events[$index - 1]);
            }
        }
        $this->assertStringContainsString('information_schema.tables', $events[7]);
        $this->assertStringNotContainsString('password', File::get($this->outputDirectory.'/postgres.json'));
        $manifestStat = stat($this->outputDirectory.'/postgres.json');
        $this->assertNotFalse($manifestStat);
        $this->assertSame(1, $manifestStat[3]);
        $this->assertSame([], File::glob($this->outputDirectory.'/.'.'*.tmp'));
    }

    public function test_initially_unset_pgconnect_timeout_is_restored_on_success_before_transaction(): void
    {
        Config::set('mapilio.legacy_database_connection', 'legacy_postgresql_synthetic');
        Config::set('database.connections.legacy_postgresql_synthetic', ['driver' => 'pgsql']);
        Config::set('mapilio.legacy_import_preflight.postgresql.connect_timeout_seconds', 5);
        $previousConnectTimeout = getenv('PGCONNECT_TIMEOUT');
        putenv('PGCONNECT_TIMEOUT');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('getPdo')->willReturnCallback(function (): \PDO {
            $this->assertSame('5', getenv('PGCONNECT_TIMEOUT'));

            return new \PDO('sqlite::memory:');
        });
        $connection->method('transaction')->willReturnCallback(function (callable $callback) use ($connection): array {
            $this->assertFalse(getenv('PGCONNECT_TIMEOUT'));

            return $callback($connection);
        });
        $connection->method('statement')->willReturn(true);
        $connection->method('selectOne')->willReturnCallback(static function (string $sql): object {
            if (str_contains($sql, 'transaction_read_only')) {
                return (object) ['setting' => 'on'];
            }
            if (str_contains($sql, 'information_schema.tables')) {
                return (object) ['table_name' => 'legacy_users'];
            }

            return (object) ['aggregate' => 1];
        });
        $database = $this->createMock(DatabaseManager::class);
        $database->expects($this->once())->method('connection')->with('legacy_postgresql_synthetic')
            ->willReturnCallback(function () use ($connection): Connection {
                return $connection;
            });

        try {
            $result = (new RunLegacyImportPreflight($database))->run('postgres-unset.json', true);
            $this->assertFalse(getenv('PGCONNECT_TIMEOUT'));
            $this->assertTrue($result->successful);
        } finally {
            if ($previousConnectTimeout === false) {
                putenv('PGCONNECT_TIMEOUT');
            } else {
                putenv('PGCONNECT_TIMEOUT='.$previousConnectTimeout);
            }
        }
    }

    public function test_initially_unset_pgconnect_timeout_is_restored_on_connection_failure(): void
    {
        Config::set('mapilio.legacy_database_connection', 'legacy_postgresql_synthetic');
        Config::set('database.connections.legacy_postgresql_synthetic', ['driver' => 'pgsql']);
        Config::set('mapilio.legacy_import_preflight.postgresql.connect_timeout_seconds', 5);
        $previousConnectTimeout = getenv('PGCONNECT_TIMEOUT');
        putenv('PGCONNECT_TIMEOUT');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('getPdo')->willReturnCallback(function (): \PDO {
            $this->assertSame('5', getenv('PGCONNECT_TIMEOUT'));
            throw new \RuntimeException('synthetic PDO failure');
        });
        $database = $this->createMock(DatabaseManager::class);
        $database->expects($this->once())->method('connection')->with('legacy_postgresql_synthetic')
            ->willReturnCallback(function () use ($connection): Connection {
                $this->assertSame('5', getenv('PGCONNECT_TIMEOUT'));

                return $connection;
            });

        try {
            (new RunLegacyImportPreflight($database))->run('connection-failure.json', true);
            $this->fail('Expected the synthetic connection failure to be sanitized.');
        } catch (LegacyImportPreflightException $exception) {
            $this->assertSame('QUERY_FAILED', $exception->reasonCode);
            $this->assertFalse(getenv('PGCONNECT_TIMEOUT'));
        } finally {
            if ($previousConnectTimeout === false) {
                putenv('PGCONNECT_TIMEOUT');
            } else {
                putenv('PGCONNECT_TIMEOUT='.$previousConnectTimeout);
            }
        }

        $this->assertFileDoesNotExist($this->outputDirectory.'/connection-failure.json');
    }

    public function test_previously_set_pgconnect_timeout_is_restored_on_connection_failure(): void
    {
        Config::set('mapilio.legacy_database_connection', 'legacy_postgresql_synthetic');
        Config::set('database.connections.legacy_postgresql_synthetic', ['driver' => 'pgsql']);
        Config::set('mapilio.legacy_import_preflight.postgresql.connect_timeout_seconds', 7);
        $previousConnectTimeout = getenv('PGCONNECT_TIMEOUT');
        putenv('PGCONNECT_TIMEOUT=previous-value');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('getPdo')->willReturnCallback(function (): \PDO {
            $this->assertSame('7', getenv('PGCONNECT_TIMEOUT'));
            throw new \RuntimeException('synthetic PDO failure');
        });
        $database = $this->createMock(DatabaseManager::class);
        $database->expects($this->once())->method('connection')->with('legacy_postgresql_synthetic')
            ->willReturnCallback(function () use ($connection): Connection {
                $this->assertSame('7', getenv('PGCONNECT_TIMEOUT'));

                return $connection;
            });

        try {
            (new RunLegacyImportPreflight($database))->run('connection-failure-set.json', true);
            $this->fail('Expected the synthetic connection failure to be sanitized.');
        } catch (LegacyImportPreflightException $exception) {
            $this->assertSame('QUERY_FAILED', $exception->reasonCode);
            $this->assertSame('previous-value', getenv('PGCONNECT_TIMEOUT'));
        } finally {
            if ($previousConnectTimeout === false) {
                putenv('PGCONNECT_TIMEOUT');
            } else {
                putenv('PGCONNECT_TIMEOUT='.$previousConnectTimeout);
            }
        }

        $this->assertFileDoesNotExist($this->outputDirectory.'/connection-failure-set.json');
    }

    public function test_missing_table_is_reported_in_a_failure_manifest(): void
    {
        Config::set('mapilio.legacy_import_preflight.table_allowlist', ['legacy_users', 'missing_legacy_table']);

        $this->artisan('mapilio:legacy-import-preflight', [
            '--output' => 'missing.json',
            '--confirm-read-only-source' => true,
        ])->assertFailed()->expectsOutput('TABLE_MISSING');

        $manifest = json_decode(File::get($this->outputDirectory.'/missing.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('FAIL', $manifest['tables'][1]['status']);
        $this->assertSame('TABLE_MISSING', $manifest['tables'][1]['reason_code']);
    }
}

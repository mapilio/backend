<?php

namespace App\Domain\DataMigration;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Throwable;

/**
 * @phpstan-type TableInspectionResult array{table: string, exists: bool, column_count: int|null, row_count: int|null, status: 'PASS'|'FAIL', reason_code: string, passed: bool}
 * @phpstan-type ManifestTable array{table: string, exists: bool, column_count: int|null, row_count: int|null, status: 'PASS'|'FAIL', reason_code: string}
 * @phpstan-type PreflightManifest array{schema_version: int, generated_at: string, run_id: string, environment_class: string, driver: string, connection_name: string, tables: list<ManifestTable>}
 */
final class RunLegacyImportPreflight
{
    private const MAX_TABLES = 50;

    private const MAX_COLUMNS = 1000;

    private const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'staging'];

    public function __construct(private readonly DatabaseManager $database) {}

    public function run(?string $output, bool $confirmed): LegacyImportPreflightResult
    {
        $environment = (string) config('app.env', app()->environment());

        if (! in_array($environment, self::ALLOWED_ENVIRONMENTS, true)) {
            throw new LegacyImportPreflightException('PRODUCTION_BLOCKED');
        }

        if (! config('mapilio.legacy_import_preflight.enabled', false)) {
            throw new LegacyImportPreflightException('PREFLIGHT_NOT_ENABLED');
        }

        if (! $confirmed) {
            throw new LegacyImportPreflightException('CONFIRMATION_REQUIRED');
        }

        $filename = $this->validateFilename($output);
        $tables = $this->validateAllowlist(config('mapilio.legacy_import_preflight.table_allowlist', []));
        $connectionName = config('mapilio.legacy_database_connection');

        if (! is_string($connectionName) || trim($connectionName) === '') {
            throw new LegacyImportPreflightException('CONNECTION_NOT_ALLOWED');
        }

        $connectionConfig = config('database.connections', [])[$connectionName] ?? null;
        $driver = is_array($connectionConfig) ? strtolower((string) ($connectionConfig['driver'] ?? '')) : '';

        if (! is_array($connectionConfig) || ! in_array($driver, ['sqlite', 'pgsql'], true)) {
            throw new LegacyImportPreflightException('CONNECTION_NOT_ALLOWED');
        }

        if ($driver === 'sqlite' && $environment === 'staging') {
            throw new LegacyImportPreflightException('CONNECTION_NOT_ALLOWED');
        }

        $output = $this->prepareOutput($filename);
        try {
            $tableResults = $driver === 'pgsql'
                ? $this->connectAndInspectPostgres($connectionName, $tables)
                : $this->inspectSqlite($this->database->connection($connectionName), $tables);
        } catch (LegacyImportPreflightException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new LegacyImportPreflightException('QUERY_FAILED');
        }

        try {
            $manifest = $this->manifest($environment, $driver, $connectionName, $tableResults);
            $allPassed = ! in_array(false, array_column($tableResults, 'passed'), true);
            $this->publishManifest($output, $manifest);
        } catch (LegacyImportPreflightException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new LegacyImportPreflightException('MANIFEST_WRITE_FAILED');
        }

        if (! $allPassed) {
            $reason = 'QUERY_FAILED';
            foreach ($manifest['tables'] as $table) {
                if ($table['status'] === 'FAIL') {
                    $reason = $table['reason_code'];
                    break;
                }
            }

            return new LegacyImportPreflightResult(false, ['SOURCE_READ_ONLY', 'TABLE_INSPECTION'], $reason);
        }

        return new LegacyImportPreflightResult(true, ['SOURCE_READ_ONLY', 'TABLE_INSPECTION', 'MANIFEST_WRITTEN']);
    }

    private function validateFilename(?string $output): string
    {
        if (! is_string($output) || $output === '' || str_contains($output, "\0")
            || str_contains($output, '/') || str_contains($output, '\\')
            || ! preg_match('/^[a-z0-9][a-z0-9._-]*\\.json$/D', $output)) {
            throw new LegacyImportPreflightException('OUTPUT_INVALID');
        }

        return $output;
    }

    /** @return list<string> */
    private function validateAllowlist(mixed $configured): array
    {
        $tables = is_string($configured) ? explode(',', $configured) : (is_array($configured) ? $configured : []);
        $tables = array_map(static fn (mixed $table): string => is_string($table) ? trim($table) : '', $tables);

        if ($tables === [] || (count($tables) === 1 && $tables[0] === '')) {
            throw new LegacyImportPreflightException('TABLE_ALLOWLIST_EMPTY');
        }
        if (count($tables) > self::MAX_TABLES) {
            throw new LegacyImportPreflightException('TABLE_ALLOWLIST_INVALID');
        }

        foreach ($tables as $table) {
            if (! preg_match('/^[a-z_][a-z0-9_]*$/D', $table)) {
                throw new LegacyImportPreflightException('TABLE_ALLOWLIST_INVALID');
            }
        }

        if (count($tables) !== count(array_unique($tables))) {
            throw new LegacyImportPreflightException('TABLE_ALLOWLIST_INVALID');
        }

        return array_values($tables);
    }

    /**
     * @param  list<string>  $tables
     * @return list<TableInspectionResult>
     */
    private function inspectSqlite(Connection $connection, array $tables): array
    {
        $results = [];
        foreach ($tables as $table) {
            try {
                $exists = $connection->selectOne(
                    "select name from sqlite_master where type = 'table' and name = ?",
                    [$table],
                ) !== null;
                if (! $exists) {
                    $results[] = $this->tableResult($table, false, null, null, 'TABLE_MISSING');

                    continue;
                }

                $columns = $connection->select("pragma table_info({$table})");
                if (count($columns) > self::MAX_COLUMNS) {
                    $results[] = $this->tableResult($table, true, count($columns), null, 'QUERY_FAILED');

                    continue;
                }
                $rowCount = (int) ($connection->selectOne("select count(*) as aggregate from {$table}")->aggregate ?? 0);
                $results[] = $this->tableResult($table, true, count($columns), $rowCount, 'OK');
            } catch (Throwable) {
                $results[] = $this->tableResult($table, true, null, null, 'QUERY_FAILED');
            }
        }

        return $results;
    }

    /**
     * @param  list<string>  $tables
     * @return list<TableInspectionResult>
     */
    private function connectAndInspectPostgres(string $connectionName, array $tables): array
    {
        $previousTimeout = getenv('PGCONNECT_TIMEOUT');
        putenv('PGCONNECT_TIMEOUT='.$this->postgresTimeout('connect_timeout_seconds', 5, 1, 30));

        try {
            $connection = $this->database->connection($connectionName);
            $connection->getPdo();
        } finally {
            if ($previousTimeout === false) {
                putenv('PGCONNECT_TIMEOUT');
            } else {
                putenv('PGCONNECT_TIMEOUT='.$previousTimeout);
            }
        }

        return $connection->transaction(fn (Connection $db): array => $this->inspectPostgres($db, $tables));
    }

    /**
     * @param  list<string>  $tables
     * @return list<TableInspectionResult>
     */
    private function inspectPostgres(Connection $connection, array $tables): array
    {
        $startedAt = hrtime(true);
        $statementTimeout = $this->postgresTimeout('statement_timeout_ms', 5000, 100, 60000);
        $lockTimeout = $this->postgresTimeout('lock_timeout_ms', 1000, 100, 10000);
        $maxRuntime = $this->postgresTimeout('max_runtime_ms', 30000, 1000, 120000);

        try {
            $connection->statement('set transaction read only');
            $this->setPostgresSelectTimeouts($connection, $startedAt, $maxRuntime, $statementTimeout, $lockTimeout);
            if ((string) ($connection->selectOne("select current_setting('transaction_read_only') as setting")->setting ?? '') !== 'on') {
                throw new LegacyImportPreflightException('READ_ONLY_UNVERIFIED');
            }
        } catch (LegacyImportPreflightException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new LegacyImportPreflightException('READ_ONLY_UNVERIFIED');
        }

        $results = [];
        foreach ($tables as $table) {
            try {
                $this->setPostgresSelectTimeouts($connection, $startedAt, $maxRuntime, $statementTimeout, $lockTimeout);
                $exists = $connection->selectOne(
                    'select table_name from information_schema.tables where table_schema = current_schema() and table_name = ?',
                    [$table],
                ) !== null;
                if (! $exists) {
                    $results[] = $this->tableResult($table, false, null, null, 'TABLE_MISSING');

                    continue;
                }

                $this->setPostgresSelectTimeouts($connection, $startedAt, $maxRuntime, $statementTimeout, $lockTimeout);
                $columnCount = (int) ($connection->selectOne(
                    'select count(*) as aggregate from information_schema.columns where table_schema = current_schema() and table_name = ?',
                    [$table],
                )->aggregate ?? 0);
                if ($columnCount > self::MAX_COLUMNS) {
                    $results[] = $this->tableResult($table, true, $columnCount, null, 'QUERY_FAILED');

                    continue;
                }

                $this->setPostgresSelectTimeouts($connection, $startedAt, $maxRuntime, $statementTimeout, $lockTimeout);
                $rowCount = (int) ($connection->selectOne("select count(*) as aggregate from {$table}")->aggregate ?? 0);
                $results[] = $this->tableResult($table, true, $columnCount, $rowCount, 'OK');
            } catch (LegacyImportPreflightException $exception) {
                throw $exception;
            } catch (Throwable) {
                $results[] = $this->tableResult($table, true, null, null, 'QUERY_FAILED');
            }
        }

        return $results;
    }

    /** @return TableInspectionResult */
    private function tableResult(string $table, bool $exists, ?int $columns, ?int $rows, string $reason): array
    {
        return [
            'table' => $table,
            'exists' => $exists,
            'column_count' => $columns,
            'row_count' => $rows,
            'status' => $reason === 'OK' ? 'PASS' : 'FAIL',
            'reason_code' => $reason,
            'passed' => $reason === 'OK',
        ];
    }

    private function postgresTimeout(string $key, int $default, int $minimum, int $maximum): int
    {
        $value = (int) config("mapilio.legacy_import_preflight.postgresql.{$key}", $default);

        return max($minimum, min($maximum, $value));
    }

    private function remainingMilliseconds(int $startedAt, int $maximumMilliseconds): int
    {
        $remaining = ($maximumMilliseconds * 1_000_000) - (hrtime(true) - $startedAt);

        return intdiv($remaining, 1_000_000);
    }

    private function setPostgresSelectTimeouts(
        Connection $connection,
        int $startedAt,
        int $maximumMilliseconds,
        int $statementTimeout,
        int $lockTimeout,
    ): void {
        $remaining = $this->remainingMilliseconds($startedAt, $maximumMilliseconds);
        if ($remaining <= 0) {
            throw new LegacyImportPreflightException('QUERY_FAILED');
        }

        $connection->statement("set local lock_timeout = '".min($lockTimeout, $remaining)."ms'");
        $connection->statement("set local statement_timeout = '".min($statementTimeout, $remaining)."ms'");
    }

    /**
     * @param  list<TableInspectionResult>  $tableResults
     * @return PreflightManifest
     */
    private function manifest(string $environment, string $driver, string $connectionName, array $tableResults): array
    {
        $tables = $tableResults;
        foreach ($tables as &$table) {
            unset($table['passed']);
        }
        unset($table);

        return [
            'schema_version' => 1,
            'generated_at' => now('UTC')->format('Y-m-d\\TH:i:s\\Z'),
            'run_id' => (string) Str::uuid(),
            'environment_class' => $environment,
            'driver' => $driver,
            'connection_name' => $connectionName,
            'tables' => $tables,
        ];
    }

    /** @return array{directory: string, path: string, identity: array{device: int, inode: int}} */
    private function prepareOutput(string $filename): array
    {
        $directory = config('mapilio.legacy_import_preflight.output_directory');
        if (! is_string($directory) || $directory === '' || is_link($directory)) {
            throw new LegacyImportPreflightException('OUTPUT_INVALID');
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new LegacyImportPreflightException('OUTPUT_INVALID');
        }
        if (! $this->isPrivateDirectory($directory)) {
            throw new LegacyImportPreflightException('OUTPUT_INVALID');
        }

        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        if (is_link($path) || file_exists($path)) {
            throw new LegacyImportPreflightException('OUTPUT_EXISTS');
        }

        $identity = $this->fileIdentity($directory);
        if ($identity === null) {
            throw new LegacyImportPreflightException('OUTPUT_INVALID');
        }

        return ['directory' => $directory, 'path' => $path, 'identity' => $identity];
    }

    /**
     * @param  array{directory: string, path: string, identity: array{device: int, inode: int}}  $output
     * @param  PreflightManifest  $manifest
     */
    private function publishManifest(array $output, array $manifest): void
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
        $temporary = $output['directory'].DIRECTORY_SEPARATOR.'.'.bin2hex(random_bytes(16)).'.tmp';
        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            throw new LegacyImportPreflightException('MANIFEST_WRITE_FAILED');
        }
        @chmod($temporary, 0600);
        $temporaryIdentity = $this->fileIdentity($temporary);
        if (! $this->isPrivateFile($temporary) || $temporaryIdentity === null) {
            fclose($handle);
            $this->cleanupFile($temporary, $temporaryIdentity);
            throw new LegacyImportPreflightException('MANIFEST_WRITE_FAILED');
        }

        try {
            $length = strlen($json);
            $written = 0;
            while ($written < $length) {
                $chunk = @fwrite($handle, substr($json, $written));
                if ($chunk === false || $chunk === 0) {
                    throw new LegacyImportPreflightException('MANIFEST_WRITE_FAILED');
                }
                $written += $chunk;
            }
            if (! @fflush($handle)) {
                throw new LegacyImportPreflightException('MANIFEST_WRITE_FAILED');
            }
            if (function_exists('fsync') && ! @fsync($handle)) {
                throw new LegacyImportPreflightException('MANIFEST_WRITE_FAILED');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            $this->cleanupFile($temporary, $temporaryIdentity);
            throw $exception;
        }
        fclose($handle);

        if (! $this->identitiesMatch($output['identity'], $this->fileIdentity($output['directory']))) {
            $this->cleanupFile($temporary, $temporaryIdentity);
            throw new LegacyImportPreflightException('MANIFEST_WRITE_FAILED');
        }
        if (! @link($temporary, $output['path'])) {
            $this->cleanupFile($temporary, $temporaryIdentity);
            throw new LegacyImportPreflightException(file_exists($output['path']) || is_link($output['path'])
                ? 'OUTPUT_EXISTS'
                : 'MANIFEST_WRITE_FAILED');
        }
        @chmod($output['path'], 0600);
        if (! $this->isPrivateFile($output['path'])
            || ! $this->identitiesMatch($temporaryIdentity, $this->fileIdentity($output['path']))) {
            $this->cleanupFile($temporary, $temporaryIdentity);
            throw new LegacyImportPreflightException('MANIFEST_WRITE_FAILED');
        }
        $this->cleanupFile($temporary, $temporaryIdentity);
        if (file_exists($temporary) || is_link($temporary)) {
            throw new LegacyImportPreflightException('MANIFEST_WRITE_FAILED');
        }
    }

    /** @param array{device: int, inode: int}|null $identity */
    private function cleanupFile(string $path, ?array $identity): void
    {
        if (! is_link($path) && is_file($path) && $this->identitiesMatch($identity, $this->fileIdentity($path))) {
            @unlink($path);
        }
    }

    /** @return array{device: int, inode: int}|null */
    private function fileIdentity(string $path): ?array
    {
        $stat = @stat($path);
        if ($stat === false) {
            return null;
        }

        return ['device' => (int) $stat[0], 'inode' => (int) $stat[1]];
    }

    /**
     * @param  array{device: int, inode: int}|null  $expected
     * @param  array{device: int, inode: int}|null  $actual
     */
    private function identitiesMatch(?array $expected, ?array $actual): bool
    {
        return $expected !== null && $actual !== null && $expected === $actual;
    }

    private function isPrivateDirectory(string $directory): bool
    {
        if (is_link($directory) || ! is_dir($directory)) {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return true;
        }

        $mode = @fileperms($directory);
        if ($mode === false || ($mode & 0077) !== 0) {
            return false;
        }

        return ! function_exists('posix_geteuid') || @fileowner($directory) === posix_geteuid();
    }

    private function isPrivateFile(string $path): bool
    {
        if (is_link($path) || ! is_file($path)) {
            return false;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            return true;
        }

        $mode = @fileperms($path);
        if ($mode === false || ($mode & 0077) !== 0) {
            return false;
        }

        return ! function_exists('posix_geteuid') || @fileowner($path) === posix_geteuid();
    }
}

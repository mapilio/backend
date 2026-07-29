<?php

namespace App\Domain\DataMigration;

use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Throwable;

final class ExtractImportSchemaDescriptor
{
    private const IDENTIFIER = '/^[a-z_][a-z0-9_]*$/D';

    private const MAX_COLUMNS = 1000;

    private const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'staging'];

    private const SUPPORTED_TYPES = ['bigint', 'bigserial', 'bit', 'boolean', 'box', 'bpchar', 'bytea', 'char', 'cidr', 'date', 'decimal', 'float4', 'float8', 'inet', 'int', 'int2', 'int4', 'int8', 'interval', 'json', 'jsonb', 'line', 'lseg', 'macaddr', 'money', 'numeric', 'path', 'pg_lsn', 'point', 'polygon', 'real', 'serial', 'smallint', 'text', 'time', 'timestamp', 'timestamptz', 'uuid', 'varbit', 'varchar'];

    public function __construct(private readonly DatabaseManager $database, private readonly JsonPublisher $publisher) {}

    public function run(?string $output, bool $confirmed): object
    {
        if (! in_array((string) config('app.env', app()->environment()), self::ALLOWED_ENVIRONMENTS, true)) {
            throw new ImportSchemaDescriptorExtractionException('PRODUCTION_BLOCKED');
        }
        if (! config('mapilio.import_schema_extractor.enabled', false)) {
            throw new ImportSchemaDescriptorExtractionException('EXTRACTOR_NOT_ENABLED');
        }
        if (! $confirmed) {
            throw new ImportSchemaDescriptorExtractionException('CONFIRMATION_REQUIRED');
        }
        if (! is_string($output) || ! preg_match('/^[a-z0-9][a-z0-9._-]*\.json$/D', $output)) {
            throw new ImportSchemaDescriptorExtractionException('OUTPUT_INVALID');
        }
        $connectionName = config('mapilio.import_schema_extractor.source_connection');
        $allowed = config('mapilio.import_schema_extractor.source_connection_allowlist', []);
        $allowed = is_array($allowed) ? $allowed : [];
        $defaultConnection = config('database.default');
        if (! is_string($connectionName) || $connectionName === '' || ! in_array($connectionName, $allowed, true)
            || (is_string($defaultConnection) && $connectionName === $defaultConnection)) {
            throw new ImportSchemaDescriptorExtractionException('CONNECTION_NOT_ALLOWED');
        }
        $connectionConfig = config('database.connections.'.$connectionName);
        if (! is_array($connectionConfig) || strtolower((string) ($connectionConfig['driver'] ?? '')) !== 'pgsql') {
            throw new ImportSchemaDescriptorExtractionException('CONNECTION_NOT_ALLOWED');
        }
        $sourceEndpoint = $this->postgresEndpoint($connectionConfig);
        if ($sourceEndpoint === null) {
            throw new ImportSchemaDescriptorExtractionException('CONNECTION_NOT_ALLOWED');
        }
        if (is_string($defaultConnection)) {
            $defaultConfig = config('database.connections.'.$defaultConnection);
            if (is_array($defaultConfig)) {
                $normalizedDefault = $this->normalizeConnectionConfig($defaultConfig);
                if ($normalizedDefault === null) {
                    throw new ImportSchemaDescriptorExtractionException('CONNECTION_NOT_ALLOWED');
                }
                if (strtolower((string) ($normalizedDefault['driver'] ?? '')) !== 'pgsql') {
                    $normalizedDefault = null;
                }
            }
            if (isset($normalizedDefault)) {
                $defaultEndpoint = $this->postgresEndpoint($normalizedDefault);
                if ($defaultEndpoint === null || hash_equals($defaultEndpoint, $sourceEndpoint)) {
                    throw new ImportSchemaDescriptorExtractionException('CONNECTION_NOT_ALLOWED');
                }
            }
        }
        $schema = $this->identifier(config('mapilio.import_schema_extractor.schema'));
        $table = $this->identifier(config('mapilio.import_schema_extractor.table'));
        if ($schema === null || $table === null) {
            throw new ImportSchemaDescriptorExtractionException('IDENTIFIER_INVALID');
        }
        if ($schema === 'pg_catalog' || $schema === 'information_schema' || str_starts_with($schema, 'pg_toast')) {
            throw new ImportSchemaDescriptorExtractionException('SCHEMA_NOT_ALLOWED');
        }

        $directory = config('mapilio.import_schema_extractor.output_directory');
        if (! is_string($directory) || $directory === '') {
            throw new ImportSchemaDescriptorExtractionException('OUTPUT_INVALID');
        }
        $descriptor = $this->connectAndExtract($connectionName, $schema, $table);
        try {
            $json = json_encode($descriptor, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new ImportSchemaDescriptorExtractionException('DESCRIPTOR_INVALID');
        }
        if (strlen($json) > ComputeImportSchemaFingerprint::MAX_BYTES) {
            throw new ImportSchemaDescriptorExtractionException('DESCRIPTOR_TOO_LARGE');
        }
        $this->publisher->publish($directory, $output, $json);

        return (object) ['checks' => ['SOURCE_READ_ONLY', 'TABLE_METADATA', 'DESCRIPTOR_WRITTEN']];
    }

    private function connectAndExtract(string $name, string $schema, string $table): array
    {
        $previous = getenv('PGCONNECT_TIMEOUT');
        putenv('PGCONNECT_TIMEOUT='.max(1, min(30, (int) config('mapilio.import_schema_extractor.postgresql.connect_timeout_seconds', 5))));
        try {
            $connection = $this->database->connection($name);
            $connection->getPdo();
        } catch (Throwable) {
            throw new ImportSchemaDescriptorExtractionException('CONNECTION_FAILED');
        } finally {
            $previous === false ? putenv('PGCONNECT_TIMEOUT') : putenv('PGCONNECT_TIMEOUT='.$previous);
        }
        try {
            return $connection->transaction(fn (Connection $db): array => $this->extract($db, $schema, $table));
        } catch (ImportSchemaDescriptorExtractionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ImportSchemaDescriptorExtractionException('QUERY_FAILED');
        }
    }

    private function extract(Connection $db, string $schema, string $table): array
    {
        $started = hrtime(true);
        $max = max(1000, min(120000, (int) config('mapilio.import_schema_extractor.postgresql.max_runtime_ms', 30000)));
        try {
            $db->statement('set transaction read only');
            $this->timeouts($db, $started, $max);
            if ((string) ($db->selectOne("select current_setting('transaction_read_only') as setting")->setting ?? '') !== 'on') {
                throw new ImportSchemaDescriptorExtractionException('READ_ONLY_UNVERIFIED');
            }
            $this->timeouts($db, $started, $max);
            $tableRow = $db->selectOne("select c.oid from pg_catalog.pg_class c join pg_catalog.pg_namespace n on n.oid = c.relnamespace where n.nspname = ? and c.relname = ? and c.relkind = 'r'", [$schema, $table]);
            if ($tableRow === null) {
                throw new ImportSchemaDescriptorExtractionException('TABLE_NOT_ORDINARY_BASE');
            }
            $this->timeouts($db, $started, $max);
            if (! is_object($tableRow)) {
                throw new ImportSchemaDescriptorExtractionException('COLUMN_METADATA_INVALID');
            }
            $oid = $this->requiredInteger($tableRow, 'oid', 1, PHP_INT_MAX);
            $countRow = $db->selectOne('select count(*) as aggregate from pg_catalog.pg_attribute where attrelid = ? and attnum > 0 and not attisdropped', [$oid]);
            if (! is_object($countRow)) {
                throw new ImportSchemaDescriptorExtractionException('COLUMN_METADATA_INVALID');
            }
            $count = $this->requiredInteger($countRow, 'aggregate', 1, self::MAX_COLUMNS);
            $this->timeouts($db, $started, $max);
            $rows = $db->select('select ordinal_position, column_name, udt_schema, udt_name, is_nullable, character_maximum_length, numeric_precision, numeric_scale, datetime_precision, data_type, domain_schema, domain_name, is_generated, identity_generation from information_schema.columns where table_schema = ? and table_name = ? order by ordinal_position', [$schema, $table]);
            if (count($rows) !== $count) {
                throw new ImportSchemaDescriptorExtractionException('COLUMN_METADATA_INVALID');
            }
            $columns = [];
            $names = [];
            foreach ($rows as $index => $row) {
                if (! is_object($row)) {
                    throw new ImportSchemaDescriptorExtractionException('COLUMN_METADATA_INVALID');
                }
                $position = $this->requiredInteger($row, 'ordinal_position', 1, self::MAX_COLUMNS);
                $name = $this->requiredString($row, 'column_name');
                $typeSchema = $this->requiredString($row, 'udt_schema');
                $typeName = $this->requiredString($row, 'udt_name');
                $nullable = $this->requiredString($row, 'is_nullable');
                if ($position !== $index + 1 || ! preg_match(self::IDENTIFIER, $name)
                    || isset($names[$name]) || ! preg_match(self::IDENTIFIER, $typeSchema)
                    || ! preg_match(self::IDENTIFIER, $typeName) || ! in_array($nullable, ['YES', 'NO'], true)
                    || $this->unsupported($row, $typeSchema, $typeName)) {
                    throw new ImportSchemaDescriptorExtractionException('COLUMN_METADATA_INVALID');
                }
                $names[$name] = true;
                $columns[] = ['position' => $position, 'name' => $name, 'type_schema' => $typeSchema, 'type_name' => $typeName, 'nullable' => $nullable === 'YES', 'character_length' => $this->optionalInteger($row, 'character_maximum_length'), 'numeric_precision' => $this->optionalInteger($row, 'numeric_precision'), 'numeric_scale' => $this->optionalInteger($row, 'numeric_scale'), 'datetime_precision' => $this->optionalInteger($row, 'datetime_precision')];
            }

            return ['schema_version' => 1, 'fingerprint_algorithm' => ComputeImportSchemaFingerprint::FINGERPRINT_ALGORITHM, 'engine' => 'postgresql', 'schema' => $schema, 'table' => $table, 'columns' => $columns];
        } catch (ImportSchemaDescriptorExtractionException $e) {
            throw $e;
        } catch (Throwable) {
            throw new ImportSchemaDescriptorExtractionException('QUERY_FAILED');
        }
    }

    private function unsupported(object $row, string $schema, string $name): bool
    {
        return $schema !== 'pg_catalog'
            || $this->requiredNullable($row, 'domain_schema') !== null
            || $this->requiredNullable($row, 'domain_name') !== null
            || in_array($this->requiredString($row, 'data_type'), ['ARRAY', 'USER-DEFINED'], true)
            || str_starts_with($name, '_')
            || $this->requiredString($row, 'is_generated') !== 'NEVER'
            || $this->requiredNullable($row, 'identity_generation') !== null
            || ! in_array($name, self::SUPPORTED_TYPES, true);
    }

    private function requiredString(object $row, string $property): string
    {
        if (! property_exists($row, $property) || ! is_string($row->{$property})) {
            throw new ImportSchemaDescriptorExtractionException('COLUMN_METADATA_INVALID');
        }

        return $row->{$property};
    }

    private function requiredInteger(object $row, string $property, int $minimum, int $maximum): int
    {
        if (! property_exists($row, $property)) {
            throw new ImportSchemaDescriptorExtractionException('COLUMN_METADATA_INVALID');
        }
        $value = $row->{$property};
        if (! is_int($value) && (! is_string($value) || preg_match('/^\d+$/D', $value) !== 1)) {
            throw new ImportSchemaDescriptorExtractionException('COLUMN_METADATA_INVALID');
        }
        if (is_string($value) && strlen($value) > 18) {
            throw new ImportSchemaDescriptorExtractionException('COLUMN_METADATA_INVALID');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]);
        if ($integer === false) {
            throw new ImportSchemaDescriptorExtractionException('COLUMN_METADATA_INVALID');
        }

        return $integer;
    }

    private function optionalInteger(object $row, string $property): ?int
    {
        if (! property_exists($row, $property)) {
            throw new ImportSchemaDescriptorExtractionException('COLUMN_METADATA_INVALID');
        }
        if ($row->{$property} === null) {
            return null;
        }

        return $this->requiredInteger($row, $property, 0, 1_000_000);
    }

    private function requiredNullable(object $row, string $property): ?string
    {
        if (! property_exists($row, $property) || ($row->{$property} !== null && ! is_string($row->{$property}))) {
            throw new ImportSchemaDescriptorExtractionException('COLUMN_METADATA_INVALID');
        }

        return $row->{$property};
    }

    private function identifier(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::IDENTIFIER, $value) === 1 ? $value : null;
    }

    private function postgresEndpoint(array $config): ?string
    {
        $config = $this->normalizeConnectionConfig($config);
        if ($config === null) {
            return null;
        }
        if (strtolower((string) ($config['driver'] ?? '')) !== 'pgsql') {
            return null;
        }

        $host = $config['host'] ?? null;
        $port = $config['port'] ?? 5432;
        $database = $config['database'] ?? null;
        if (! is_string($host) || $host === '') {
            return null;
        }
        if ((! is_int($port) && (! is_string($port) || preg_match('/^\d+$/D', $port) !== 1))
            || (int) $port < 1 || (int) $port > 65535 || ! is_string($database) || $database === ''
            || preg_match('/[\x00-\x20\/]/', $database) === 1) {
            return null;
        }

        return strtolower($host).':'.(int) $port.':'.$database;
    }

    /** @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    private function normalizeConnectionConfig(array $config): ?array
    {
        try {
            return (new ConfigurationUrlParser)->parseConfiguration($config);
        } catch (Throwable) {
            return null;
        }
    }

    private function timeLeft(int $started, int $max): int
    {
        return $max - intdiv(hrtime(true) - $started, 1_000_000);
    }

    private function timeouts(Connection $db, int $started, int $max): void
    {
        $left = $this->timeLeft($started, $max);
        if ($left <= 0) {
            throw new ImportSchemaDescriptorExtractionException('QUERY_TIMEOUT');
        }
        $statement = min($left, max(100, min(60000, (int) config('mapilio.import_schema_extractor.postgresql.statement_timeout_ms', 5000))));
        $lock = min($left, max(100, min(10000, (int) config('mapilio.import_schema_extractor.postgresql.lock_timeout_ms', 1000))));
        $db->statement("set local lock_timeout = '{$lock}ms'");
        $db->statement("set local statement_timeout = '{$statement}ms'");
    }
}

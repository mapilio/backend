<?php

namespace App\Domain\DataMigration;

use Illuminate\Database\Connection;
use Throwable;

/** Policy-neutral, metadata-only PostgreSQL descriptor reader. */
final class PostgresqlCatalogReader
{
    private const IDENTIFIER = '/^[a-z_][a-z0-9_]*$/D';

    private const MAX_COLUMNS = 1000;

    private const SUPPORTED_TYPES = ['bigint', 'bigserial', 'bit', 'boolean', 'box', 'bpchar', 'bytea', 'char', 'cidr', 'date', 'decimal', 'float4', 'float8', 'inet', 'int', 'int2', 'int4', 'int8', 'interval', 'json', 'jsonb', 'line', 'lseg', 'macaddr', 'money', 'numeric', 'path', 'pg_lsn', 'point', 'polygon', 'real', 'serial', 'smallint', 'text', 'time', 'timestamp', 'timestamptz', 'uuid', 'varbit', 'varchar'];

    public function read(Connection $connection, string $schema, string $table, PostgresqlCatalogReadOptions $options): array
    {
        return $connection->transaction(fn (Connection $db): array => $this->extract($db, $schema, $table, $options));
    }

    private function extract(Connection $db, string $schema, string $table, PostgresqlCatalogReadOptions $options): array
    {
        $started = hrtime(true);
        $max = $options->maxRuntimeMs;
        try {
            $db->statement('set transaction read only');
            $this->timeouts($db, $started, $max, $options);
            if ((string) ($db->selectOne("select current_setting('transaction_read_only') as setting")->setting ?? '') !== 'on') {
                throw new ImportSchemaDescriptorExtractionException('READ_ONLY_UNVERIFIED');
            }
            $this->timeouts($db, $started, $max, $options);
            $tableRow = $db->selectOne("select c.oid from pg_catalog.pg_class c join pg_catalog.pg_namespace n on n.oid = c.relnamespace where n.nspname = ? and c.relname = ? and c.relkind = 'r'", [$schema, $table]);
            if ($tableRow === null) {
                throw new ImportSchemaDescriptorExtractionException('TABLE_NOT_ORDINARY_BASE');
            }
            $this->timeouts($db, $started, $max, $options);
            if (! is_object($tableRow)) {
                throw new ImportSchemaDescriptorExtractionException('COLUMN_METADATA_INVALID');
            }
            $oid = $this->requiredInteger($tableRow, 'oid', 1, PHP_INT_MAX);
            $countRow = $db->selectOne('select count(*) as aggregate from pg_catalog.pg_attribute where attrelid = ? and attnum > 0 and not attisdropped', [$oid]);
            if (! is_object($countRow)) {
                throw new ImportSchemaDescriptorExtractionException('COLUMN_METADATA_INVALID');
            }
            $count = $this->requiredInteger($countRow, 'aggregate', 1, self::MAX_COLUMNS);
            $this->timeouts($db, $started, $max, $options);
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
                if ($position !== $index + 1 || ! preg_match(self::IDENTIFIER, $name) || isset($names[$name])
                    || ! preg_match(self::IDENTIFIER, $typeSchema) || ! preg_match(self::IDENTIFIER, $typeName)
                    || ! in_array($nullable, ['YES', 'NO'], true) || $this->unsupported($row, $typeSchema, $typeName)) {
                    throw new ImportSchemaDescriptorExtractionException('COLUMN_METADATA_INVALID');
                }
                $names[$name] = true;
                $columns[] = ['position' => $position, 'name' => $name, 'type_schema' => $typeSchema, 'type_name' => $typeName, 'nullable' => $nullable === 'YES', 'character_length' => $this->optionalInteger($row, 'character_maximum_length'), 'numeric_precision' => $this->optionalInteger($row, 'numeric_precision'), 'numeric_scale' => $this->optionalInteger($row, 'numeric_scale'), 'datetime_precision' => $this->optionalInteger($row, 'datetime_precision')];
            }

            return ['schema_version' => 1, 'fingerprint_algorithm' => ComputeImportSchemaFingerprint::FINGERPRINT_ALGORITHM, 'engine' => 'postgresql', 'schema' => $schema, 'table' => $table, 'columns' => $columns];
        } catch (ImportSchemaDescriptorExtractionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ImportSchemaDescriptorExtractionException('QUERY_FAILED');
        }
    }

    private function unsupported(object $row, string $schema, string $name): bool
    {
        return $schema !== 'pg_catalog' || $this->requiredNullable($row, 'domain_schema') !== null || $this->requiredNullable($row, 'domain_name') !== null
            || in_array($this->requiredString($row, 'data_type'), ['ARRAY', 'USER-DEFINED'], true) || str_starts_with($name, '_')
            || $this->requiredString($row, 'is_generated') !== 'NEVER' || $this->requiredNullable($row, 'identity_generation') !== null
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

        return $row->{$property} === null ? null : $this->requiredInteger($row, $property, 0, 1_000_000);
    }

    private function requiredNullable(object $row, string $property): ?string
    {
        if (! property_exists($row, $property) || ($row->{$property} !== null && ! is_string($row->{$property}))) {
            throw new ImportSchemaDescriptorExtractionException('COLUMN_METADATA_INVALID');
        }

        return $row->{$property};
    }

    private function timeLeft(int $started, int $max): int
    {
        return $max - intdiv(hrtime(true) - $started, 1_000_000);
    }

    private function timeouts(Connection $db, int $started, int $max, PostgresqlCatalogReadOptions $options): void
    {
        $left = $this->timeLeft($started, $max);
        if ($left <= 0) {
            throw new ImportSchemaDescriptorExtractionException('QUERY_TIMEOUT');
        }
        $statement = min($left, $options->statementTimeoutMs);
        $lock = min($left, $options->lockTimeoutMs);
        $db->statement("set local lock_timeout = '{$lock}ms'");
        $db->statement("set local statement_timeout = '{$statement}ms'");
    }
}

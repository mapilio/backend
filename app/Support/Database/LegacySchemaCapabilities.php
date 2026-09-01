<?php

namespace App\Support\Database;

use Illuminate\Database\DatabaseManager;
use RuntimeException;

final class LegacySchemaCapabilities
{
    /**
     * @var array<string, array<string, bool>>
     */
    private array $tableExistence = [];

    /**
     * @var array<string, array<string, array<string, true>>>
     */
    private array $columnSets = [];

    public function __construct(private readonly DatabaseManager $connections) {}

    public function hasTable(string $table, ?string $connectionName = null): bool
    {
        $resolvedConnectionName = $this->resolveConnectionName($connectionName);

        if (array_key_exists($table, $this->tableExistence[$resolvedConnectionName] ?? [])) {
            return $this->tableExistence[$resolvedConnectionName][$table];
        }

        $exists = $this->connections->connection($resolvedConnectionName)
            ->getSchemaBuilder()
            ->hasTable($table);

        $this->tableExistence[$resolvedConnectionName][$table] = $exists;

        return $exists;
    }

    public function hasColumn(string $table, string $column, ?string $connectionName = null): bool
    {
        if (! $this->hasTable($table, $connectionName)) {
            return false;
        }

        $resolvedConnectionName = $this->resolveConnectionName($connectionName);
        $columns = $this->columnSet($table, $resolvedConnectionName);

        return array_key_exists(strtolower($column), $columns);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function filterExistingColumns(
        string $table,
        array $values,
        ?string $connectionName = null,
    ): array {
        if (! $this->hasTable($table, $connectionName)) {
            return [];
        }

        $resolvedConnectionName = $this->resolveConnectionName($connectionName);
        $columns = $this->columnSet($table, $resolvedConnectionName);
        $filtered = [];

        foreach ($values as $column => $value) {
            if (array_key_exists(strtolower($column), $columns)) {
                $filtered[$column] = $value;
            }
        }

        return $filtered;
    }

    /**
     * @return array<string, true>
     */
    private function columnSet(string $table, string $connectionName): array
    {
        if (array_key_exists($table, $this->columnSets[$connectionName] ?? [])) {
            return $this->columnSets[$connectionName][$table];
        }

        $columns = $this->connections->connection($connectionName)
            ->getSchemaBuilder()
            ->getColumnListing($table);
        $columnSet = array_fill_keys(array_map(strtolower(...), $columns), true);

        // Publish only a complete, successful column read to keep failures retryable.
        $this->columnSets[$connectionName][$table] = $columnSet;

        return $columnSet;
    }

    private function resolveConnectionName(?string $connectionName): string
    {
        $name = $connectionName ?? config('mapilio.legacy_database_connection');

        if (! is_string($name) || trim($name) === '') {
            throw new RuntimeException('Legacy database connection is not configured.');
        }

        return $name;
    }
}

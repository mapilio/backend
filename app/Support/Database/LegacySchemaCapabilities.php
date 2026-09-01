<?php

namespace App\Support\Database;

use Illuminate\Database\DatabaseManager;
use RuntimeException;

final class LegacySchemaCapabilities
{
    /**
     * @var array<string, array<string, array{exists: bool, columns: array<string, true>}>>
     */
    private array $snapshots = [];

    public function __construct(private readonly DatabaseManager $connections) {}

    public function hasTable(string $table, ?string $connectionName = null): bool
    {
        return $this->snapshot($table, $connectionName)['exists'];
    }

    public function hasColumn(string $table, string $column, ?string $connectionName = null): bool
    {
        $snapshot = $this->snapshot($table, $connectionName);

        return $snapshot['exists'] && isset($snapshot['columns'][strtolower($column)]);
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
        $snapshot = $this->snapshot($table, $connectionName);

        if (! $snapshot['exists']) {
            return [];
        }

        $filtered = [];

        foreach ($values as $column => $value) {
            if (isset($snapshot['columns'][strtolower($column)])) {
                $filtered[$column] = $value;
            }
        }

        return $filtered;
    }

    /**
     * @return array{exists: bool, columns: array<string, true>}
     */
    private function snapshot(string $table, ?string $connectionName): array
    {
        $resolvedConnectionName = $this->resolveConnectionName($connectionName);

        if (isset($this->snapshots[$resolvedConnectionName][$table])) {
            return $this->snapshots[$resolvedConnectionName][$table];
        }

        $schema = $this->connections->connection($resolvedConnectionName)->getSchemaBuilder();
        $exists = $schema->hasTable($table);
        $columns = $exists ? $schema->getColumnListing($table) : [];
        $snapshot = [
            'exists' => $exists,
            'columns' => array_fill_keys(array_map(strtolower(...), $columns), true),
        ];

        // Publish only a complete, successful metadata read to keep failures retryable.
        $this->snapshots[$resolvedConnectionName][$table] = $snapshot;

        return $snapshot;
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

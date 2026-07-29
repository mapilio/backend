<?php

namespace App\Domain\DataMigration;

/** Immutable bounded limits for one metadata-only catalog read. */
final readonly class PostgresqlCatalogReadOptions
{
    public int $maxRuntimeMs;

    public int $statementTimeoutMs;

    public int $lockTimeoutMs;

    /** @param array<string, mixed> $values */
    public function __construct(array $values)
    {
        $this->maxRuntimeMs = max(1000, min(120000, (int) ($values['max_runtime_ms'] ?? 30000)));
        $this->statementTimeoutMs = max(100, min(60000, (int) ($values['statement_timeout_ms'] ?? 5000)));
        $this->lockTimeoutMs = max(100, min(10000, (int) ($values['lock_timeout_ms'] ?? 1000)));
    }
}

<?php

namespace App\Support\Queue;

use RuntimeException;

final class QueueRuntimeConfiguration
{
    public const LONGEST_JOB_TIMEOUT_SECONDS = 600;

    public const RETRY_MARGIN_SECONDS = 60;

    public const MINIMUM_RETRY_WINDOW_SECONDS = self::LONGEST_JOB_TIMEOUT_SECONDS + self::RETRY_MARGIN_SECONDS;

    public static function assertSafe(mixed $connectionName, mixed $connections): void
    {
        if (! is_string($connectionName) || trim($connectionName) === '') {
            throw new RuntimeException('The default queue connection is not configured.');
        }

        if (! is_array($connections)) {
            throw new RuntimeException('Queue connections are not configured.');
        }

        self::assertConnection($connectionName, $connections, []);
    }

    /**
     * @param  array<string, mixed>  $connections
     * @param  list<string>  $parents
     */
    private static function assertConnection(string $name, array $connections, array $parents): void
    {
        if (in_array($name, $parents, true)) {
            throw new RuntimeException("Queue failover configuration contains a cycle at [{$name}].");
        }

        $connection = $connections[$name] ?? null;

        if (! is_array($connection)) {
            throw new RuntimeException("Queue connection [{$name}] is not configured.");
        }

        $driver = $connection['driver'] ?? null;

        if (! is_string($driver) || trim($driver) === '') {
            throw new RuntimeException("Queue connection [{$name}] has no driver.");
        }

        if (in_array($driver, ['sync', 'deferred', 'background', 'null'], true)) {
            return;
        }

        if ($driver === 'failover') {
            self::assertFailoverConnections($name, $connection, $connections, [...$parents, $name]);

            return;
        }

        $retryWindow = $connection['retry_after'] ?? $connection['visibility_timeout'] ?? null;

        if (! is_int($retryWindow) || $retryWindow < self::MINIMUM_RETRY_WINDOW_SECONDS) {
            throw new RuntimeException(
                "Queue connection [{$name}] must provide a retry or visibility window of at least "
                .self::MINIMUM_RETRY_WINDOW_SECONDS.' seconds.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $connection
     * @param  array<string, mixed>  $connections
     * @param  list<string>  $parents
     */
    private static function assertFailoverConnections(
        string $name,
        array $connection,
        array $connections,
        array $parents,
    ): void {
        $failoverConnections = $connection['connections'] ?? null;

        if (! is_array($failoverConnections) || $failoverConnections === []) {
            throw new RuntimeException("Queue failover connection [{$name}] has no child connections.");
        }

        foreach ($failoverConnections as $failoverConnection) {
            if (! is_string($failoverConnection) || trim($failoverConnection) === '') {
                throw new RuntimeException("Queue failover connection [{$name}] contains an invalid child connection.");
            }

            self::assertConnection($failoverConnection, $connections, $parents);
        }
    }
}

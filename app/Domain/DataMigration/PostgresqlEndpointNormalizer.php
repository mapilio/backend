<?php

namespace App\Domain\DataMigration;

use Illuminate\Database\ConfigurationUrlParser;
use Throwable;

/** Normalizes PostgreSQL config identity without resolving a connection or DNS. */
final class PostgresqlEndpointNormalizer
{
    /** @param array<string, mixed> $config */
    public function normalize(array $config): ?string
    {
        $config = $this->parse($config);
        if ($config === null || strtolower((string) ($config['driver'] ?? '')) !== 'pgsql') {
            return null;
        }
        $host = $config['host'] ?? null;
        $port = $config['port'] ?? 5432;
        $database = $config['database'] ?? null;
        if (! is_string($host) || ! $this->validHost($host)
            || ((! is_int($port) && (! is_string($port) || preg_match('/^\d+$/D', $port) !== 1))
                || (int) $port < 1 || (int) $port > 65535)
            || ! is_string($database) || $database === '' || $this->ambiguous($database)) {
            return null;
        }

        return $this->canonicalHost($host).':'.(int) $port.':'.$database;
    }

    /** Returns only the effective driver; parsed credentials never leave this service. */
    /** @param array<string, mixed> $config */
    public function effectiveDriver(array $config): ?string
    {
        $config = $this->parse($config);
        if ($config === null || ! is_string($config['driver'] ?? null) || $config['driver'] === '') {
            return null;
        }

        return strtolower($config['driver']);
    }

    /** @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    private function parse(array $config): ?array
    {
        try {
            return (new ConfigurationUrlParser)->parseConfiguration($config);
        } catch (Throwable) {
            return null;
        }
    }

    private function validHost(string $host): bool
    {
        if ($host === '' || trim($host) !== $host || $this->ambiguous($host)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\.?$/iD', $host) === 1;
    }

    private function canonicalHost(string $host): string
    {
        return strtolower(rtrim($host, '.'));
    }

    private function ambiguous(string $value): bool
    {
        return preg_match('/[\x00-\x20]/', $value) === 1 || str_contains($value, '/') || str_contains($value, '\\');
    }
}

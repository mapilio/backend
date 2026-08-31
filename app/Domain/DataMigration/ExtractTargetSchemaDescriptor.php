<?php

namespace App\Domain\DataMigration;

use Illuminate\Database\DatabaseManager;
use Throwable;

/** Target-policy wrapper for the shared PostgreSQL catalog reader. */
final class ExtractTargetSchemaDescriptor
{
    private const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'staging'];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly JsonPublisher $publisher,
        ?PostgresqlCatalogReader $reader = null,
        ?PostgresqlEndpointNormalizer $endpointNormalizer = null,
    ) {
        $this->reader = $reader ?? new PostgresqlCatalogReader;
        $this->endpointNormalizer = $endpointNormalizer ?? new PostgresqlEndpointNormalizer;
    }

    private readonly PostgresqlCatalogReader $reader;

    private readonly PostgresqlEndpointNormalizer $endpointNormalizer;

    public function run(?string $output, bool $confirmed): ImportSchemaDescriptorExtractionResult
    {
        if (! in_array((string) config('app.env', app()->environment()), self::ALLOWED_ENVIRONMENTS, true)) {
            throw new ImportSchemaDescriptorExtractionException('PRODUCTION_BLOCKED');
        }
        if (! config('mapilio.target_schema_extractor.enabled', false)) {
            throw new ImportSchemaDescriptorExtractionException('EXTRACTOR_NOT_ENABLED');
        }
        if (! $confirmed) {
            throw new ImportSchemaDescriptorExtractionException('CONFIRMATION_REQUIRED');
        }
        if (! is_string($output) || ! preg_match('/^[a-z0-9][a-z0-9._-]*\.json$/D', $output)) {
            throw new ImportSchemaDescriptorExtractionException('OUTPUT_INVALID');
        }

        $connectionName = config('mapilio.target_schema_extractor.target_connection', 'pgsql');
        if ($connectionName !== 'pgsql') {
            throw new ImportSchemaDescriptorExtractionException('CONNECTION_NOT_ALLOWED');
        }
        $targetConfig = config('database.connections.pgsql');
        $targetEndpoint = is_array($targetConfig) ? $this->endpointNormalizer->normalize($targetConfig) : null;
        if ($targetEndpoint === null) {
            throw new ImportSchemaDescriptorExtractionException('CONNECTION_NOT_ALLOWED');
        }
        $legacyConfig = config('database.connections.legacy_pgsql');
        if (! is_array($legacyConfig)) {
            throw new ImportSchemaDescriptorExtractionException('CONNECTION_NOT_ALLOWED');
        }
        $legacyEndpoint = $this->endpointNormalizer->normalize($legacyConfig);
        if ($legacyEndpoint === null || hash_equals($legacyEndpoint, $targetEndpoint)) {
            throw new ImportSchemaDescriptorExtractionException('CONNECTION_NOT_ALLOWED');
        }

        $schema = $this->identifier(config('mapilio.target_schema_extractor.schema'));
        $table = $this->identifier(config('mapilio.target_schema_extractor.table'));
        if ($schema === null || $table === null) {
            throw new ImportSchemaDescriptorExtractionException('IDENTIFIER_INVALID');
        }
        if ($schema === 'pg_catalog' || $schema === 'information_schema' || str_starts_with($schema, 'pg_toast')) {
            throw new ImportSchemaDescriptorExtractionException('SCHEMA_NOT_ALLOWED');
        }
        $directory = config('mapilio.target_schema_extractor.output_directory');
        if (! is_string($directory) || $directory === '') {
            throw new ImportSchemaDescriptorExtractionException('OUTPUT_INVALID');
        }

        $previous = getenv('PGCONNECT_TIMEOUT');
        putenv('PGCONNECT_TIMEOUT='.max(1, min(30, (int) config('mapilio.target_schema_extractor.postgresql.connect_timeout_seconds', 5))));
        try {
            try {
                $connection = $this->database->connection('pgsql');
                $connection->getPdo();
            } catch (Throwable) {
                throw new ImportSchemaDescriptorExtractionException('CONNECTION_FAILED');
            }
        } finally {
            $previous === false ? putenv('PGCONNECT_TIMEOUT') : putenv('PGCONNECT_TIMEOUT='.$previous);
        }
        try {
            $descriptor = $this->reader->read($connection, $schema, $table, $this->options());
        } catch (ImportSchemaDescriptorExtractionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ImportSchemaDescriptorExtractionException('QUERY_FAILED');
        }
        try {
            $json = json_encode($descriptor, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new ImportSchemaDescriptorExtractionException('DESCRIPTOR_INVALID');
        }
        if (strlen($json) > ComputeImportSchemaFingerprint::MAX_BYTES) {
            throw new ImportSchemaDescriptorExtractionException('DESCRIPTOR_TOO_LARGE');
        }
        $this->publisher->publish($directory, $output, $json);

        return new ImportSchemaDescriptorExtractionResult(['TARGET_READ_ONLY', 'TABLE_METADATA', 'DESCRIPTOR_WRITTEN']);
    }

    private function identifier(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-z_][a-z0-9_]*$/D', $value) === 1 ? $value : null;
    }

    private function options(): PostgresqlCatalogReadOptions
    {
        return new PostgresqlCatalogReadOptions((array) config('mapilio.target_schema_extractor.postgresql', []));
    }
}

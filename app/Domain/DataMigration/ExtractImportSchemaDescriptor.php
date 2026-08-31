<?php

namespace App\Domain\DataMigration;

use Illuminate\Database\DatabaseManager;
use Throwable;

/** @phpstan-import-type SchemaDescriptor from PostgresqlCatalogReader */
final class ExtractImportSchemaDescriptor
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
        $defaultConnection = config('database.default');
        if (! is_string($connectionName) || $connectionName === '' || ! is_array($allowed) || ! in_array($connectionName, $allowed, true) || (is_string($defaultConnection) && $connectionName === $defaultConnection)) {
            throw new ImportSchemaDescriptorExtractionException('CONNECTION_NOT_ALLOWED');
        }
        $connectionConfig = config('database.connections.'.$connectionName);
        if (! is_array($connectionConfig) || $this->endpointNormalizer->effectiveDriver($connectionConfig) !== 'pgsql') {
            throw new ImportSchemaDescriptorExtractionException('CONNECTION_NOT_ALLOWED');
        }
        $sourceEndpoint = $this->endpointNormalizer->normalize($connectionConfig);
        if ($sourceEndpoint === null) {
            throw new ImportSchemaDescriptorExtractionException('CONNECTION_NOT_ALLOWED');
        }
        if (is_string($defaultConnection)) {
            $defaultConfig = config('database.connections.'.$defaultConnection);
            if (is_array($defaultConfig)) {
                $defaultDriver = $this->endpointNormalizer->effectiveDriver($defaultConfig);
                if ($defaultDriver === null) {
                    throw new ImportSchemaDescriptorExtractionException('CONNECTION_NOT_ALLOWED');
                }
                if ($defaultDriver === 'pgsql') {
                    $defaultEndpoint = $this->endpointNormalizer->normalize($defaultConfig);
                    if ($defaultEndpoint === null || hash_equals($defaultEndpoint, $sourceEndpoint)) {
                        throw new ImportSchemaDescriptorExtractionException('CONNECTION_NOT_ALLOWED');
                    }
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
        $descriptor = $this->connectAndExtract($connectionName, $schema, $table, 'mapilio.import_schema_extractor');
        try {
            $json = json_encode($descriptor, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new ImportSchemaDescriptorExtractionException('DESCRIPTOR_INVALID');
        }
        if (strlen($json) > ComputeImportSchemaFingerprint::MAX_BYTES) {
            throw new ImportSchemaDescriptorExtractionException('DESCRIPTOR_TOO_LARGE');
        }
        $this->publisher->publish($directory, $output, $json);

        return new ImportSchemaDescriptorExtractionResult(['SOURCE_READ_ONLY', 'TABLE_METADATA', 'DESCRIPTOR_WRITTEN']);
    }

    /** @return SchemaDescriptor */
    private function connectAndExtract(string $name, string $schema, string $table, string $configKey): array
    {
        $previous = getenv('PGCONNECT_TIMEOUT');
        putenv('PGCONNECT_TIMEOUT='.max(1, min(30, (int) config($configKey.'.postgresql.connect_timeout_seconds', 5))));
        try {
            try {
                $connection = $this->database->connection($name);
                $connection->getPdo();
            } catch (Throwable) {
                throw new ImportSchemaDescriptorExtractionException('CONNECTION_FAILED');
            }
        } finally {
            $previous === false ? putenv('PGCONNECT_TIMEOUT') : putenv('PGCONNECT_TIMEOUT='.$previous);
        }
        try {
            return $this->reader->read($connection, $schema, $table, $this->options($configKey));
        } catch (ImportSchemaDescriptorExtractionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ImportSchemaDescriptorExtractionException('QUERY_FAILED');
        }
    }

    private function options(string $configKey): PostgresqlCatalogReadOptions
    {
        return new PostgresqlCatalogReadOptions((array) config($configKey.'.postgresql', []));
    }

    private function identifier(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-z_][a-z0-9_]*$/D', $value) === 1 ? $value : null;
    }
}

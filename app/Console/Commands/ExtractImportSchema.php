<?php

namespace App\Console\Commands;

use App\Domain\DataMigration\ExtractImportSchemaDescriptor;
use App\Domain\DataMigration\ImportSchemaDescriptorExtractionException;
use Illuminate\Console\Command;
use Throwable;

final class ExtractImportSchema extends Command
{
    protected $signature = 'mapilio:extract-import-schema {--output= : Basename for the private JSON descriptor} {--confirm-read-only-source : Confirm the configured PostgreSQL source is read only}';

    protected $description = 'Extract a PostgreSQL import schema descriptor without reading application data';

    public function handle(ExtractImportSchemaDescriptor $extractor): int
    {
        try {
            $result = $extractor->run(is_string($this->option('output')) ? $this->option('output') : null, (bool) $this->option('confirm-read-only-source'));
        } catch (ImportSchemaDescriptorExtractionException $e) {
            $this->error('SCHEMA_DESCRIPTOR_EXTRACTION_FAILED');
            $this->line($e->reasonCode);

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('SCHEMA_DESCRIPTOR_EXTRACTION_FAILED');
            $this->line('QUERY_FAILED');

            return self::FAILURE;
        }
        foreach ($result->checks as $check) {
            $this->line($check.': PASS');
        }

        return self::SUCCESS;
    }
}

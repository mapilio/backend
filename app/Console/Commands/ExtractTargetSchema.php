<?php

namespace App\Console\Commands;

use App\Domain\DataMigration\ExtractTargetSchemaDescriptor;
use App\Domain\DataMigration\ImportSchemaDescriptorExtractionException;
use Illuminate\Console\Command;
use Throwable;

final class ExtractTargetSchema extends Command
{
    protected $signature = 'mapilio:extract-target-schema {--output= : Basename for the private JSON descriptor} {--confirm-read-only-target : Confirm the configured PostgreSQL target is read only}';

    protected $description = 'Extract a PostgreSQL target schema descriptor without reading application data';

    public function handle(ExtractTargetSchemaDescriptor $extractor): int
    {
        try {
            $result = $extractor->run(is_string($this->option('output')) ? $this->option('output') : null, (bool) $this->option('confirm-read-only-target'));
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

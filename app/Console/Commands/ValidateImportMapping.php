<?php

namespace App\Console\Commands;

use App\Domain\DataMigration\ImportMappingValidationException;
use App\Domain\DataMigration\ValidateImportMapping as MappingValidator;
use Illuminate\Console\Command;
use Throwable;

final class ValidateImportMapping extends Command
{
    protected $signature = 'mapilio:validate-import-mapping {manifest} {--source-fingerprint=} {--target-fingerprint=}';

    protected $description = 'Validate a static identity import mapping manifest';

    public function handle(MappingValidator $validator): int
    {
        try {
            $result = $validator->validate($this->argument('manifest'), $this->option('source-fingerprint'), $this->option('target-fingerprint'));
        } catch (ImportMappingValidationException $exception) {
            $this->error('MAPPING_VALIDATION_FAILED');
            $this->line($exception->reasonCode);

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('MAPPING_VALIDATION_FAILED');
            $this->line('MANIFEST_SCHEMA_INVALID');

            return self::FAILURE;
        }
        foreach ($result->checks as $check) {
            $this->line($check.': PASS');
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Domain\DataMigration\ComputeImportSchemaFingerprint;
use App\Domain\DataMigration\ImportSchemaFingerprintException;
use Illuminate\Console\Command;
use Throwable;

final class FingerprintImportSchema extends Command
{
    protected $signature = 'mapilio:fingerprint-import-schema {descriptor}';

    protected $description = 'Compute a deterministic fingerprint for a static import schema descriptor';

    public function handle(ComputeImportSchemaFingerprint $fingerprinter): int
    {
        try {
            $result = $fingerprinter->compute($this->argument('descriptor'));
        } catch (ImportSchemaFingerprintException $exception) {
            $this->error('SCHEMA_FINGERPRINT_FAILED');
            $this->line($exception->reasonCode);

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('SCHEMA_FINGERPRINT_FAILED');
            $this->line('CANONICALIZATION_FAILED');

            return self::FAILURE;
        }
        $this->line('SCHEMA_DESCRIPTOR: PASS');
        $this->line('CANONICALIZATION: PASS');
        $this->line('SCHEMA_FINGERPRINT: '.$result->fingerprint);

        return self::SUCCESS;
    }
}

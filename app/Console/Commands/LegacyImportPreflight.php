<?php

namespace App\Console\Commands;

use App\Domain\DataMigration\LegacyImportPreflightException;
use App\Domain\DataMigration\RunLegacyImportPreflight;
use Illuminate\Console\Command;
use Throwable;

final class LegacyImportPreflight extends Command
{
    protected $signature = 'mapilio:legacy-import-preflight
        {--output= : Basename for the private JSON manifest}
        {--confirm-read-only-source : Confirm that the configured source must be read only}';

    protected $description = 'Inspect an explicitly allowlisted legacy database without writing data';

    public function handle(RunLegacyImportPreflight $preflight): int
    {
        try {
            $result = $preflight->run(
                is_string($this->option('output')) ? $this->option('output') : null,
                (bool) $this->option('confirm-read-only-source'),
            );
        } catch (LegacyImportPreflightException $exception) {
            $this->error('PREFLIGHT_FAILED');
            $this->line($exception->reasonCode);

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('PREFLIGHT_FAILED');
            $this->line('MANIFEST_WRITE_FAILED');

            return self::FAILURE;
        }

        foreach ($result->checks as $check) {
            $this->line($check.': PASS');
        }

        if (! $result->successful) {
            $this->line((string) $result->reasonCode);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

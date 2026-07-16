<?php

namespace App\Console\Commands;

use App\Domain\OperationsDashboard\Actions\BackupReadinessException;
use App\Domain\OperationsDashboard\Actions\VerifyBackupReadinessEvidence;
use Illuminate\Console\Command;

class VerifyBackupReadiness extends Command
{
    protected $signature = 'mapilio:verify-backup-readiness
        {--manifest= : Read backup evidence from this file instead of MAPILIO_BACKUP_EVIDENCE_PATH}';

    protected $description = 'Fail unless external PostgreSQL backup and restore evidence meets deployment policy';

    public function handle(VerifyBackupReadinessEvidence $verifier): int
    {
        $manifest = $this->option('manifest');

        try {
            $result = $verifier->verify(is_string($manifest) && $manifest !== '' ? $manifest : null);
        } catch (BackupReadinessException $exception) {
            $this->error($exception->getMessage());
            $this->table(
                ['Check', 'Result'],
                array_map(static fn (string $failure): array => [$failure, 'FAIL'], $exception->failures),
            );

            return self::FAILURE;
        }

        $this->table(
            ['Check', 'Result'],
            array_map(static fn (string $check): array => [$check, 'PASS'], $result['checks']),
        );
        $this->info('Backup readiness evidence meets the configured deployment policy.');

        return self::SUCCESS;
    }
}

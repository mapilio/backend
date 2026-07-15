<?php

namespace App\Console\Commands;

use App\Domain\ImagerySequences\Actions\RunImageUploadContractSmoke;
use Illuminate\Console\Command;
use Throwable;

class SmokeImageUploadContract extends Command
{
    protected $signature = 'mapilio:smoke-image-upload
        {--mode=all : all, mobile, or chunk}
        {--confirm-write : Acknowledge that the configured staging image server will retain smoke artifacts}';

    protected $description = 'Run the staging-only mobile and mapilio-kit image upload contract smoke test';

    public function handle(RunImageUploadContractSmoke $smoke): int
    {
        if (! $this->option('confirm-write')) {
            $this->error('Refusing to write: pass --confirm-write after reviewing the staging target and cleanup policy.');

            return self::FAILURE;
        }

        try {
            $result = $smoke->run((string) $this->option('mode'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Check', 'Result'],
            array_map(static fn (string $check): array => [$check, 'PASS'], $result['checks']),
        );
        $this->warn('Smoke artifacts are not deleted automatically because the legacy image server has no cleanup API.');
        $this->line('Cleanup identifiers:');

        foreach ($result['artifacts'] as $name => $value) {
            $this->line("  {$name}: {$value}");
        }

        return self::SUCCESS;
    }
}

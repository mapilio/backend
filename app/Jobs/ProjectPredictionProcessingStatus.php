<?php

namespace App\Jobs;

use App\Domain\AiJobsPredictions\Actions\ProjectPredictionProcessingStatus as ProjectPredictionProcessingStatusAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProjectPredictionProcessingStatus implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $receiptId) {}

    public function handle(ProjectPredictionProcessingStatusAction $statuses): void
    {
        $statuses->project($this->receiptId);
    }

    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function uniqueId(): string
    {
        return (string) $this->receiptId;
    }

    public function tags(): array
    {
        return ['ai-status-projection', "receipt:{$this->receiptId}"];
    }
}

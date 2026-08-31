<?php

namespace App\Jobs;

use App\Domain\AiJobsPredictions\Actions\DispatchSequencePrediction as DispatchSequencePredictionAction;
use App\Domain\AiJobsPredictions\Actions\MarkSequenceProcessingFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DispatchSequencePrediction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly string $sequenceUuid) {}

    public function handle(DispatchSequencePredictionAction $predictions): void
    {
        $predictions->dispatch($this->sequenceUuid);
    }

    public function failed(Throwable $exception): void
    {
        app(MarkSequenceProcessingFailed::class)->markBySequenceUuid($this->sequenceUuid);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return [
            'ai-prediction',
            "sequence:{$this->sequenceUuid}",
        ];
    }
}

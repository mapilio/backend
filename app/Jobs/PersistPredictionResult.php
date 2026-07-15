<?php

namespace App\Jobs;

use App\Domain\AiJobsPredictions\Actions\PersistPredictionResult as PersistPredictionResultAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PersistPredictionResult implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly int $receiptId) {}

    public function handle(PersistPredictionResultAction $results): void
    {
        $results->persist($this->receiptId);
    }

    public function tags(): array
    {
        return [
            'ai-result-persistence',
            "receipt:{$this->receiptId}",
        ];
    }
}

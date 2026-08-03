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

        if (config('mapilio.ai_status_projection.enabled')) {
            ProjectPredictionProcessingStatus::dispatch($this->receiptId)
                ->onQueue((string) config('mapilio.ai_status_projection.queue', 'ai-status-projections'));
        }

        if (config('mapilio.geo_publication.registration_enabled')) {
            RegisterAiDetectionPublication::dispatch($this->receiptId)
                ->onQueue((string) config('mapilio.geo_publication.queue', 'geo-publications'));
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return [
            'ai-result-persistence',
            "receipt:{$this->receiptId}",
        ];
    }
}

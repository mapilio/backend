<?php

namespace App\Jobs;

use App\Domain\AiJobsPredictions\Actions\ValidatePredictionCallbackReceipt as ValidateReceiptAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ValidatePredictionCallbackReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $receiptId) {}

    public function handle(ValidateReceiptAction $receipts): void
    {
        $validated = $receipts->validate($this->receiptId);

        if ($validated && config('mapilio.ai_result_persistence.enabled')) {
            PersistPredictionResult::dispatch($this->receiptId)
                ->onQueue((string) config('mapilio.ai_result_persistence.queue', 'ai-results'));
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return [
            'ai-callback',
            "receipt:{$this->receiptId}",
        ];
    }
}

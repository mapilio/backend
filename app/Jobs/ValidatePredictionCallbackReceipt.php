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
        $receipts->validate($this->receiptId);
    }

    public function tags(): array
    {
        return [
            'ai-callback',
            "receipt:{$this->receiptId}",
        ];
    }
}

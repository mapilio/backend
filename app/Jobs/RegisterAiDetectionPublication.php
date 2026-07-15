<?php

namespace App\Jobs;

use App\Domain\GeoPublishing\Actions\RegisterAiDetectionPublication as RegisterAiDetectionPublicationAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RegisterAiDetectionPublication implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $receiptId) {}

    public function handle(RegisterAiDetectionPublicationAction $publications): void
    {
        $publications->register($this->receiptId);
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function uniqueId(): string
    {
        return (string) $this->receiptId;
    }

    public function tags(): array
    {
        return ['geo-publication-registration', "receipt:{$this->receiptId}"];
    }
}

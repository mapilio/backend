<?php

namespace App\Jobs;

use App\Domain\GeoPublishing\Actions\PrepareAiDetectionPublication as PrepareAiDetectionPublicationAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PrepareAiDetectionPublication implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $publicationId) {}

    public function handle(PrepareAiDetectionPublicationAction $publications): void
    {
        $publications->prepare($this->publicationId);
    }

    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function uniqueId(): string
    {
        return (string) $this->publicationId;
    }

    public function tags(): array
    {
        return ['geo-publication-preparation', "publication:{$this->publicationId}"];
    }
}

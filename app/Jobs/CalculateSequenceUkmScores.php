<?php

namespace App\Jobs;

use App\Domain\ImagerySequences\Actions\CalculateSequenceUkmScores as CalculateSequenceUkmScoresAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CalculateSequenceUkmScores implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $sequenceUuid) {}

    public function handle(CalculateSequenceUkmScoresAction $scores): void
    {
        $scores->calculate($this->sequenceUuid);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function uniqueId(): string
    {
        return $this->sequenceUuid;
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'ukm-scoring',
            "sequence:{$this->sequenceUuid}",
        ];
    }
}

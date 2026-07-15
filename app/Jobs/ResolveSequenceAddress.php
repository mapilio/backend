<?php

namespace App\Jobs;

use App\Domain\ImagerySequences\Actions\ResolveSequenceAddress as ResolveSequenceAddressAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ResolveSequenceAddress implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $sequenceUuid) {}

    public function handle(ResolveSequenceAddressAction $addresses): void
    {
        $addresses->resolve($this->sequenceUuid);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
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
            'sequence-address',
            "sequence:{$this->sequenceUuid}",
        ];
    }
}

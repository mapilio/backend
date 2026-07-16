<?php

namespace App\Domain\ImagerySequences\Queries;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LeaderboardWinnerQuery
{
    public function __construct(
        private readonly LeaderboardQuery $leaderboardQuery,
    ) {}

    public function get(array $filters = []): array
    {
        $payload = [
            'is_finished' => false,
            'is_calculated' => false,
        ];

        if (empty($filters['start_at']) || empty($filters['finish_at'])) {
            return $payload;
        }

        $startAt = Carbon::parse($filters['start_at']);
        $finishAt = Carbon::parse($filters['finish_at']);
        $challenge = $this->challenge($startAt, $finishAt);

        $payload['is_finished'] = $finishAt->lessThan(Carbon::now());
        $payload['is_calculated'] = $challenge !== null && (bool) $challenge->is_calculated;

        if ($payload['is_calculated']) {
            $payload['leaderboard'] = $this->leaderboardQuery->get($filters, 3);
        }

        return $payload;
    }

    private function challenge(Carbon $startAt, Carbon $finishAt): ?object
    {
        $connectionName = config('mapilio.legacy_database_connection');

        if (! Schema::connection($connectionName)->hasTable('default_challenge_challenge')) {
            return null;
        }

        return DB::connection($connectionName)
            ->table('default_challenge_challenge')
            ->whereDate('start_at', $startAt)
            ->whereDate('finish_at', $finishAt)
            ->whereNull('deleted_at')
            ->first();
    }
}

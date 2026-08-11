<?php

namespace App\Domain\ImagerySequences\Queries;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @phpstan-import-type LeaderboardFilters from LeaderboardQuery
 * @phpstan-import-type LeaderboardRow from LeaderboardQuery
 *
 * @phpstan-type WinnerPayload array{is_finished: bool, is_calculated: bool, leaderboard?: list<LeaderboardRow>}
 */
class LeaderboardWinnerQuery
{
    public function __construct(
        private readonly LeaderboardQuery $leaderboardQuery,
    ) {}

    /**
     * @param  LeaderboardFilters  $filters
     * @return WinnerPayload
     */
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
        $calculationStatus = $this->challengeCalculationStatus($startAt, $finishAt);

        $payload['is_finished'] = $finishAt->lessThan(Carbon::now());
        $payload['is_calculated'] = $calculationStatus ?? false;

        if ($payload['is_calculated']) {
            $payload['leaderboard'] = $this->leaderboardQuery->get($filters, 3);
        }

        return $payload;
    }

    private function challengeCalculationStatus(Carbon $startAt, Carbon $finishAt): ?bool
    {
        $connectionName = config('mapilio.legacy_database_connection');

        if (! Schema::connection($connectionName)->hasTable('default_challenge_challenge')) {
            return null;
        }

        $value = DB::connection($connectionName)
            ->table('default_challenge_challenge')
            ->whereDate('start_at', $startAt)
            ->whereDate('finish_at', $finishAt)
            ->whereNull('deleted_at')
            ->value('is_calculated');

        return $value === null ? null : (bool) $value;
    }
}

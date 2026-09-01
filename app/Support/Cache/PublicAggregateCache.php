<?php

namespace App\Support\Cache;

use Closure;
use Illuminate\Support\Facades\Cache;

final class PublicAggregateCache
{
    public const LEADERBOARD_KEY_PREFIX = 'mapilio:public:v1:imagery:leaderboard:score:';

    public const ORGANIZATION_LEADERBOARD_KEY_PREFIX = 'mapilio:public:v1:organizations:leaderboard:score:';

    public const COUNTRY_IMAGE_COUNT_KEY = 'mapilio:public:v1:imagery:country-image-count';

    /**
     * @param  Closure(): list<array<string, mixed>>  $callback
     * @return list<array<string, mixed>>
     */
    public function leaderboard(int $scoreVersion, Closure $callback): array
    {
        return $this->remember($this->leaderboardKey($scoreVersion), $callback);
    }

    public function leaderboardKey(int $scoreVersion): string
    {
        return self::LEADERBOARD_KEY_PREFIX.$scoreVersion
            .':limit:'.$this->effectiveLeaderboardLimit()
            .':roles:'.$this->rolePolicyFingerprint();
    }

    /**
     * @param  Closure(): list<array<string, mixed>>  $callback
     * @return list<array<string, mixed>>
     */
    public function organizationLeaderboard(int $scoreVersion, Closure $callback): array
    {
        return $this->remember($this->organizationLeaderboardKey($scoreVersion), $callback);
    }

    public function organizationLeaderboardKey(int $scoreVersion): string
    {
        return self::ORGANIZATION_LEADERBOARD_KEY_PREFIX.$scoreVersion;
    }

    /**
     * @param  Closure(): list<array<string, mixed>>  $callback
     * @return list<array<string, mixed>>
     */
    public function countryImageCounts(Closure $callback): array
    {
        return $this->remember(self::COUNTRY_IMAGE_COUNT_KEY, $callback);
    }

    private function effectiveLeaderboardLimit(): int
    {
        return max(1, min((int) config('mapilio.leaderboard.limit', 30), 100));
    }

    private function rolePolicyFingerprint(): string
    {
        return hash('sha256', serialize([
            config('mapilio.leaderboard.excluded_role_slugs'),
            config('mapilio.leaderboard.public_role_slugs'),
        ]));
    }

    /**
     * @template TValue of array|string|int|float|bool|null
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    private function remember(string $key, Closure $callback): mixed
    {
        if (! (bool) config('mapilio.public_aggregate_cache.enabled', true)) {
            return $callback();
        }

        return Cache::flexible(
            $key,
            [
                (int) config('mapilio.public_aggregate_cache.fresh_seconds', 60),
                (int) config('mapilio.public_aggregate_cache.stale_through_seconds', 300),
            ],
            $callback,
            ['seconds' => (int) config('mapilio.public_aggregate_cache.refresh_lock_seconds', 10)],
        );
    }
}

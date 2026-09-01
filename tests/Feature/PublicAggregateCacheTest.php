<?php

namespace Tests\Feature;

use App\Domain\ImagerySequences\Queries\CountryImageCountQuery;
use App\Domain\ImagerySequences\Queries\LeaderboardQuery;
use App\Domain\Organizations\Queries\OrganizationLeaderboardQuery;
use App\Support\Cache\PublicAggregateCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Tests\TestCase;

class PublicAggregateCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mapilio.public_aggregate_cache.enabled', true);
        config()->set('mapilio.public_aggregate_cache.fresh_seconds', 60);
        config()->set('mapilio.public_aggregate_cache.stale_through_seconds', 300);
        config()->set('mapilio.public_aggregate_cache.refresh_lock_seconds', 10);
        Cache::flush();
    }

    public function test_leaderboard_aliases_share_one_computation_and_exact_wrapper(): void
    {
        $rows = [['id' => 10, 'point' => '200']];
        $query = $this->createMock(LeaderboardQuery::class);
        $query->expects($this->once())
            ->method('get')
            ->with([], null, LeaderboardQuery::SCORE_VERSION_SEQUENCE)
            ->willReturn($rows);
        $this->app->instance(LeaderboardQuery::class, $query);

        $expected = ['data' => ['leaderboard' => $rows]];

        $this->getJson('/api/leaderboard')->assertOk()->assertExactJson($expected);
        $this->getJson('/api/v1/imagery/leaderboard')->assertOk()->assertExactJson($expected);

        $this->assertSame(
            $rows,
            Cache::get(app(PublicAggregateCache::class)->leaderboardKey(LeaderboardQuery::SCORE_VERSION_SEQUENCE)),
        );
    }

    public function test_leaderboard_score_versions_are_separate_bounded_keys(): void
    {
        $sequenceRows = [['id' => 1, 'point' => '100']];
        $imageRows = [['id' => 1, 'point' => '125']];
        $query = $this->createMock(LeaderboardQuery::class);
        $query->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (array $filters, ?int $limit, int $scoreVersion) use ($sequenceRows, $imageRows): array {
                $this->assertSame([], $filters);
                $this->assertNull($limit);

                return $scoreVersion === LeaderboardQuery::SCORE_VERSION_SEQUENCE ? $sequenceRows : $imageRows;
            });
        $this->app->instance(LeaderboardQuery::class, $query);

        $this->getJson('/api/leaderboard')->assertOk();
        $this->getJson('/api/v2/leaderboard')->assertOk();

        $this->assertSame(
            $sequenceRows,
            Cache::get(app(PublicAggregateCache::class)->leaderboardKey(LeaderboardQuery::SCORE_VERSION_SEQUENCE)),
        );
        $this->assertSame(
            $imageRows,
            Cache::get(app(PublicAggregateCache::class)->leaderboardKey(LeaderboardQuery::SCORE_VERSION_IMAGE)),
        );
    }

    public function test_organization_leaderboard_aliases_share_one_computation_and_exact_wrapper(): void
    {
        $rows = [['organization_key' => 'org-a', 'point' => '200']];
        $query = $this->createMock(OrganizationLeaderboardQuery::class);
        $query->expects($this->once())
            ->method('get')
            ->with(OrganizationLeaderboardQuery::SCORE_VERSION_SEQUENCE)
            ->willReturn($rows);
        $this->app->instance(OrganizationLeaderboardQuery::class, $query);

        $expected = ['data' => ['leaderboard' => $rows]];

        $this->getJson('/api/leaderboard-organization')->assertOk()->assertExactJson($expected);
        $this->getJson('/api/v1/organizations/leaderboard')->assertOk()->assertExactJson($expected);

        $this->assertSame(
            $rows,
            Cache::get(app(PublicAggregateCache::class)->organizationLeaderboardKey(
                OrganizationLeaderboardQuery::SCORE_VERSION_SEQUENCE,
            )),
        );
    }

    public function test_organization_leaderboard_score_versions_use_separate_bounded_keys(): void
    {
        $sequenceRows = [['organization_key' => 'org-a', 'point' => '100']];
        $ukmRows = [['organization_key' => 'org-a', 'point' => '125']];
        $query = $this->createMock(OrganizationLeaderboardQuery::class);
        $query->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (int $scoreVersion) use ($sequenceRows, $ukmRows): array {
                return $scoreVersion === OrganizationLeaderboardQuery::SCORE_VERSION_SEQUENCE
                    ? $sequenceRows
                    : $ukmRows;
            });
        $this->app->instance(OrganizationLeaderboardQuery::class, $query);

        $this->getJson('/api/leaderboard-organization')->assertOk();
        $this->getJson('/api/leaderboard-organization-v2')->assertOk();

        $cache = app(PublicAggregateCache::class);
        $sequenceKey = $cache->organizationLeaderboardKey(OrganizationLeaderboardQuery::SCORE_VERSION_SEQUENCE);
        $ukmKey = $cache->organizationLeaderboardKey(OrganizationLeaderboardQuery::SCORE_VERSION_UKM);

        $this->assertNotSame($sequenceKey, $ukmKey);
        $this->assertSame($sequenceRows, Cache::get($sequenceKey));
        $this->assertSame($ukmRows, Cache::get($ukmKey));
        $this->assertLessThanOrEqual(100, strlen($sequenceKey));
    }

    public function test_filtered_leaderboards_bypass_cache_for_each_filter_name(): void
    {
        $rows = [['id' => 10, 'point' => '200']];
        $query = $this->createMock(LeaderboardQuery::class);
        $query->expects($this->exactly(6))
            ->method('get')
            ->willReturnCallback(function (array $filters, ?int $limit, int $scoreVersion) use ($rows): array {
                $this->assertNull($limit);
                $this->assertSame(LeaderboardQuery::SCORE_VERSION_SEQUENCE, $scoreVersion);
                $this->assertCount(1, $filters);

                return $rows;
            });
        $this->app->instance(LeaderboardQuery::class, $query);

        foreach (['user_id=10', 'start_at=2026-01-01', 'finish_at=2026-01-31'] as $filter) {
            $this->getJson('/api/leaderboard?'.$filter)->assertOk();
            $this->getJson('/api/leaderboard?'.$filter)->assertOk();
        }

        $this->assertFalse(Cache::has(app(PublicAggregateCache::class)->leaderboardKey(LeaderboardQuery::SCORE_VERSION_SEQUENCE)));
    }

    public function test_leaderboard_role_policy_changes_use_new_keys_while_aliases_share(): void
    {
        config()->set('mapilio.leaderboard.excluded_role_slugs', ['internal']);
        config()->set('mapilio.leaderboard.public_role_slugs', ['user']);

        $query = $this->createMock(LeaderboardQuery::class);
        $query->expects($this->exactly(3))
            ->method('get')
            ->with([], null, LeaderboardQuery::SCORE_VERSION_SEQUENCE)
            ->willReturnOnConsecutiveCalls(
                [['id' => 1, 'point' => '100']],
                [['id' => 2, 'point' => '200']],
                [['id' => 3, 'point' => '300']],
            );
        $this->app->instance(LeaderboardQuery::class, $query);
        $cache = app(PublicAggregateCache::class);

        $firstKey = $cache->leaderboardKey(LeaderboardQuery::SCORE_VERSION_SEQUENCE);
        $this->getJson('/api/leaderboard')->assertOk();
        $this->getJson('/api/v1/imagery/leaderboard')->assertOk();

        config()->set('mapilio.leaderboard.excluded_role_slugs', ['internal', 'staff']);
        $secondKey = $cache->leaderboardKey(LeaderboardQuery::SCORE_VERSION_SEQUENCE);
        $this->getJson('/api/leaderboard')->assertOk();
        $this->getJson('/api/v1/imagery/leaderboard')->assertOk();

        config()->set('mapilio.leaderboard.public_role_slugs', ['user', 'member']);
        $thirdKey = $cache->leaderboardKey(LeaderboardQuery::SCORE_VERSION_SEQUENCE);
        $this->getJson('/api/leaderboard')->assertOk();
        $this->getJson('/api/v1/imagery/leaderboard')->assertOk();

        $this->assertNotSame($firstKey, $secondKey);
        $this->assertNotSame($secondKey, $thirdKey);
        $this->assertStringNotContainsString('internal', $firstKey);
        $this->assertStringNotContainsString('user', $firstKey);
        $this->assertLessThanOrEqual(160, strlen($firstKey));
    }

    public function test_leaderboard_limit_changes_use_new_keys_and_clamped_equivalents_share(): void
    {
        config()->set('mapilio.leaderboard.limit', 30);

        $query = $this->createMock(LeaderboardQuery::class);
        $query->expects($this->exactly(3))
            ->method('get')
            ->with([], null, LeaderboardQuery::SCORE_VERSION_SEQUENCE)
            ->willReturnOnConsecutiveCalls(
                [['id' => 1, 'point' => '100']],
                [['id' => 2, 'point' => '200']],
                [['id' => 3, 'point' => '300']],
            );
        $this->app->instance(LeaderboardQuery::class, $query);
        $cache = app(PublicAggregateCache::class);

        $defaultKey = $cache->leaderboardKey(LeaderboardQuery::SCORE_VERSION_SEQUENCE);
        $this->getJson('/api/leaderboard')->assertOk();
        $this->getJson('/api/v1/imagery/leaderboard')->assertOk();

        config()->set('mapilio.leaderboard.limit', 50);
        $changedKey = $cache->leaderboardKey(LeaderboardQuery::SCORE_VERSION_SEQUENCE);
        $this->getJson('/api/leaderboard')->assertOk();
        $this->getJson('/api/v1/imagery/leaderboard')->assertOk();

        config()->set('mapilio.leaderboard.limit', 1000);
        $firstClampedKey = $cache->leaderboardKey(LeaderboardQuery::SCORE_VERSION_SEQUENCE);
        $this->getJson('/api/leaderboard')->assertOk();

        config()->set('mapilio.leaderboard.limit', 500);
        $secondClampedKey = $cache->leaderboardKey(LeaderboardQuery::SCORE_VERSION_SEQUENCE);
        $this->getJson('/api/v1/imagery/leaderboard')->assertOk();

        $this->assertNotSame($defaultKey, $changedKey);
        $this->assertNotSame($changedKey, $firstClampedKey);
        $this->assertSame($firstClampedKey, $secondClampedKey);
        $this->assertStringContainsString(':limit:100:', $firstClampedKey);
    }

    public function test_country_counts_aliases_share_one_computation_and_exact_wrapper(): void
    {
        $rows = collect([['name' => 'Algeria', 'image_count' => 500]]);
        $query = $this->createMock(CountryImageCountQuery::class);
        $query->expects($this->once())->method('get')->willReturn($rows);
        $this->app->instance(CountryImageCountQuery::class, $query);

        $expected = ['data' => $rows->all()];

        $this->getJson('/api/country-image-count')->assertOk()->assertExactJson($expected);
        $this->getJson('/api/v1/imagery/country-image-count')->assertOk()->assertExactJson($expected);

        $this->assertSame($rows->all(), Cache::get(PublicAggregateCache::COUNTRY_IMAGE_COUNT_KEY));
    }

    public function test_invalid_leaderboard_response_is_not_cached(): void
    {
        $query = $this->createMock(LeaderboardQuery::class);
        $query->expects($this->exactly(2))
            ->method('get')
            ->with(['user_id' => 'invalid'], null, LeaderboardQuery::SCORE_VERSION_SEQUENCE)
            ->willThrowException(new InvalidArgumentException("'user_id' must be an integer!"));
        $this->app->instance(LeaderboardQuery::class, $query);

        $expected = [
            'success' => false,
            'message' => ["'user_id' must be an integer!"],
            'error_code' => 400,
        ];

        $this->getJson('/api/leaderboard?user_id=invalid')->assertStatus(400)->assertExactJson($expected);
        $this->getJson('/api/leaderboard?user_id=invalid')->assertStatus(400)->assertExactJson($expected);

        $this->assertFalse(Cache::has(app(PublicAggregateCache::class)->leaderboardKey(LeaderboardQuery::SCORE_VERSION_SEQUENCE)));
    }

    public function test_disabled_cache_bypasses_an_existing_value(): void
    {
        $firstRows = [['id' => 1, 'point' => '100']];
        $secondRows = [['id' => 2, 'point' => '200']];
        $query = $this->createMock(LeaderboardQuery::class);
        $query->expects($this->exactly(2))
            ->method('get')
            ->with([], null, LeaderboardQuery::SCORE_VERSION_SEQUENCE)
            ->willReturnOnConsecutiveCalls($firstRows, $secondRows);
        $this->app->instance(LeaderboardQuery::class, $query);

        $this->getJson('/api/leaderboard')->assertExactJson(['data' => ['leaderboard' => $firstRows]]);
        config()->set('mapilio.public_aggregate_cache.enabled', false);
        $this->getJson('/api/leaderboard')->assertExactJson(['data' => ['leaderboard' => $secondRows]]);
    }

    public function test_disabled_organization_leaderboard_cache_bypasses_an_existing_value(): void
    {
        $firstRows = [['organization_key' => 'org-a', 'point' => '100']];
        $secondRows = [['organization_key' => 'org-b', 'point' => '200']];
        $query = $this->createMock(OrganizationLeaderboardQuery::class);
        $query->expects($this->exactly(2))
            ->method('get')
            ->with(OrganizationLeaderboardQuery::SCORE_VERSION_SEQUENCE)
            ->willReturnOnConsecutiveCalls($firstRows, $secondRows);
        $this->app->instance(OrganizationLeaderboardQuery::class, $query);

        $this->getJson('/api/leaderboard-organization')
            ->assertExactJson(['data' => ['leaderboard' => $firstRows]]);
        config()->set('mapilio.public_aggregate_cache.enabled', false);
        $this->getJson('/api/v1/organizations/leaderboard')
            ->assertExactJson(['data' => ['leaderboard' => $secondRows]]);
    }

    public function test_failed_deferred_refresh_keeps_the_stale_value(): void
    {
        $cache = app(PublicAggregateCache::class);
        $calls = 0;
        $staleValue = [['name' => 'Algeria', 'image_count' => 500]];

        try {
            Carbon::setTestNow(Carbon::create(2026, 9, 1, 12));

            $this->assertSame($staleValue, $cache->countryImageCounts(function () use (&$calls, $staleValue): array {
                $calls++;

                return $staleValue;
            }));

            Carbon::setTestNow(Carbon::now()->addSeconds(61));
            $this->assertSame($staleValue, $cache->countryImageCounts(function () use (&$calls): array {
                $calls++;
                throw new \RuntimeException('refresh failed');
            }));

            app(DeferredCallbackCollection::class)->invoke();

            $this->assertSame(2, $calls);
            $this->assertSame($staleValue, Cache::get(PublicAggregateCache::COUNTRY_IMAGE_COUNT_KEY));
        } finally {
            Carbon::setTestNow();
        }
    }
}

<?php

namespace Tests\Feature\Legacy;

use App\Domain\ImagerySequences\Queries\LeaderboardQuery;
use App\Domain\ImagerySequences\Queries\LeaderboardWinnerQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LeaderboardWinnerCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('default_challenge_challenge', function ($table): void {
            $table->id();
            $table->date('start_at');
            $table->date('finish_at');
            $table->boolean('is_calculated')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function test_legacy_leaderboard_winner_default_response_shape(): void
    {
        $this->getJson('/api/leaderboard-winner')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'is_finished' => false,
                    'is_calculated' => false,
                ],
            ]);
    }

    public function test_legacy_v2_leaderboard_winner_default_response_shape(): void
    {
        $this->getJson('/api/v2/leaderboard-winner')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'is_finished' => false,
                    'is_calculated' => false,
                ],
            ]);
    }

    public function test_leaderboard_winner_date_window_without_calculated_challenge(): void
    {
        $this->getJson('/api/leaderboard-winner?start_at=2026-01-01&finish_at=2026-01-31')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'is_finished' => true,
                    'is_calculated' => false,
                ],
            ]);
    }

    public function test_leaderboard_winner_uses_false_status_from_matching_challenge(): void
    {
        DB::table('default_challenge_challenge')->insert([
            'start_at' => '2026-01-01',
            'finish_at' => '2026-01-31',
            'is_calculated' => false,
        ]);

        $this->getJson('/api/leaderboard-winner?start_at=2026-01-01&finish_at=2026-01-31')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'is_finished' => true,
                    'is_calculated' => false,
                ],
            ]);
    }

    public function test_calculated_challenge_requests_top_three_leaderboard_rows(): void
    {
        DB::table('default_challenge_challenge')->insert([
            'start_at' => '2026-01-01',
            'finish_at' => '2026-01-31',
            'is_calculated' => true,
        ]);

        $leaderboard = new class extends LeaderboardQuery
        {
            /** @var list<array{filters: array<string, mixed>, limit: int|null, score_version: int}> */
            public array $calls = [];

            /**
             * @param  array<string, mixed>  $filters
             * @return list<array{id: int, username: string|null, display_name: string|null, user_profile_photo: string|null, point: string, total_length: string, total_images: int, roles: string|null}>
             */
            public function get(array $filters = [], ?int $limit = null, int $scoreVersion = self::SCORE_VERSION_SEQUENCE): array
            {
                $this->calls[] = [
                    'filters' => $filters,
                    'limit' => $limit,
                    'score_version' => $scoreVersion,
                ];

                return [];
            }
        };

        $filters = ['start_at' => '2026-01-01', 'finish_at' => '2026-01-31'];
        $payload = (new LeaderboardWinnerQuery($leaderboard))->get($filters);

        $this->assertSame([
            'is_finished' => true,
            'is_calculated' => true,
            'leaderboard' => [],
        ], $payload);
        $this->assertSame([[
            'filters' => $filters,
            'limit' => 3,
            'score_version' => LeaderboardQuery::SCORE_VERSION_SEQUENCE,
        ]], $leaderboard->calls);
    }

    public function test_versioned_leaderboard_winner_alias_returns_same_contract(): void
    {
        $legacy = $this->getJson('/api/leaderboard-winner')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/imagery/leaderboard-winner')
            ->assertOk()
            ->json();

        $this->assertSame($legacy, $versioned);
    }
}

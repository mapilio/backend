<?php

namespace Tests\Feature\Legacy;

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

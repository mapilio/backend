<?php

namespace Tests\Feature\Legacy;

use App\Domain\ImagerySequences\Queries\LeaderboardQuery;
use App\Domain\ImagerySequences\Queries\LeaderboardWinnerQuery;
use Illuminate\Support\Carbon;
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

    private function withFrozenTime(string $now, callable $callback): mixed
    {
        Carbon::setTestNow(Carbon::parse($now));

        try {
            return $callback();
        } finally {
            Carbon::setTestNow(null);
        }
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
        $this->withFrozenTime('2026-02-01 12:00:00', function (): void {
            $this->getJson('/api/leaderboard-winner?start_at=2026-01-01&finish_at=2026-01-31')
                ->assertOk()
                ->assertExactJson([
                    'data' => [
                        'is_finished' => true,
                        'is_calculated' => false,
                    ],
                ]);
        });
    }

    public function test_leaderboard_winner_uses_false_status_from_matching_challenge(): void
    {
        DB::table('default_challenge_challenge')->insert([
            'start_at' => '2026-01-01',
            'finish_at' => '2026-01-31',
            'is_calculated' => false,
        ]);

        $this->withFrozenTime('2026-02-01 12:00:00', function (): void {
            $this->getJson('/api/leaderboard-winner?start_at=2026-01-01&finish_at=2026-01-31')
                ->assertOk()
                ->assertExactJson([
                    'data' => [
                        'is_finished' => true,
                        'is_calculated' => false,
                    ],
                ]);
        });
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

        $this->withFrozenTime('2026-02-01 12:00:00', function () use ($leaderboard): void {
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
        });
    }

    public function test_versioned_leaderboard_winner_calculated_response_matches_legacy(): void
    {
        DB::table('default_challenge_challenge')->insert([
            'start_at' => '2026-01-01',
            'finish_at' => '2026-01-31',
            'is_calculated' => true,
        ]);

        $rows = [
            [
                'id' => 101,
                'username' => 'synthetic_one',
                'display_name' => 'Synthetic One',
                'user_profile_photo' => null,
                'point' => '350',
                'total_length' => '2.00',
                'total_images' => 4,
                'roles' => '{user}',
            ],
            [
                'id' => 102,
                'username' => 'synthetic_two',
                'display_name' => null,
                'user_profile_photo' => 'https://images.example/synthetic-two.jpg',
                'point' => '200',
                'total_length' => '9.67',
                'total_images' => 3,
                'roles' => null,
            ],
            [
                'id' => 103,
                'username' => null,
                'display_name' => 'Synthetic Three',
                'user_profile_photo' => null,
                'point' => '100',
                'total_length' => '0.50',
                'total_images' => 1,
                'roles' => '{mapper}',
            ],
        ];

        $leaderboard = new class($rows) extends LeaderboardQuery
        {
            /** @var list<array{filters: array<string, mixed>, limit: int|null, score_version: int}> */
            public array $calls = [];

            /**
             * @param  list<array{id: int, username: string|null, display_name: string|null, user_profile_photo: string|null, point: string, total_length: string, total_images: int, roles: string|null}>  $rows
             */
            public function __construct(private readonly array $rows) {}

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

                return $this->rows;
            }
        };

        $this->app->instance(LeaderboardQuery::class, $leaderboard);
        $filters = '?start_at=2026-01-01&finish_at=2026-01-31';
        $expected = ['data' => [
            'is_finished' => true,
            'is_calculated' => true,
            'leaderboard' => $rows,
        ]];

        [$legacy, $versioned] = $this->withFrozenTime('2026-02-01 12:00:00', function () use ($filters, $expected): array {
            $legacy = $this->getJson('/api/leaderboard-winner'.$filters)
                ->assertOk()
                ->assertExactJson($expected)
                ->json();
            $versioned = $this->getJson('/api/v1/imagery/leaderboard-winner'.$filters)
                ->assertOk()
                ->assertExactJson($expected)
                ->json();

            return [$legacy, $versioned];
        });

        $this->assertSame($legacy, $versioned);
        foreach ($versioned['data']['leaderboard'] as $row) {
            $this->assertSame([
                'id',
                'username',
                'display_name',
                'user_profile_photo',
                'point',
                'total_length',
                'total_images',
                'roles',
            ], array_keys($row));
            $this->assertIsInt($row['id']);
            $this->assertTrue($row['username'] === null || is_string($row['username']));
            $this->assertTrue($row['display_name'] === null || is_string($row['display_name']));
            $this->assertTrue($row['user_profile_photo'] === null || is_string($row['user_profile_photo']));
            $this->assertIsString($row['point']);
            $this->assertIsString($row['total_length']);
            $this->assertIsInt($row['total_images']);
            $this->assertTrue($row['roles'] === null || is_string($row['roles']));
        }
        $this->assertSame([
            ['filters' => [
                'start_at' => '2026-01-01',
                'finish_at' => '2026-01-31',
            ], 'limit' => 3, 'score_version' => LeaderboardQuery::SCORE_VERSION_SEQUENCE],
            ['filters' => [
                'start_at' => '2026-01-01',
                'finish_at' => '2026-01-31',
            ], 'limit' => 3, 'score_version' => LeaderboardQuery::SCORE_VERSION_SEQUENCE],
        ], $leaderboard->calls);
    }

    public function test_versioned_leaderboard_winner_forwards_valid_user_id_on_calculated_path(): void
    {
        DB::table('default_challenge_challenge')->insert([
            'start_at' => '2026-01-01',
            'finish_at' => '2026-01-31',
            'is_calculated' => true,
        ]);

        $rows = [[
            'id' => 10,
            'username' => 'synthetic_user',
            'display_name' => 'Synthetic User',
            'user_profile_photo' => null,
            'point' => '250',
            'total_length' => '4.25',
            'total_images' => 2,
            'roles' => '{user}',
        ]];
        $leaderboard = new class($rows) extends LeaderboardQuery
        {
            /** @var list<array{filters: array<string, mixed>, limit: int|null, score_version: int}> */
            public array $calls = [];

            /**
             * @param  list<array{id: int, username: string|null, display_name: string|null, user_profile_photo: string|null, point: string, total_length: string, total_images: int, roles: string|null}>  $rows
             */
            public function __construct(private readonly array $rows) {}

            public function get(array $filters = [], ?int $limit = null, int $scoreVersion = self::SCORE_VERSION_SEQUENCE): array
            {
                $this->calls[] = [
                    'filters' => $filters,
                    'limit' => $limit,
                    'score_version' => $scoreVersion,
                ];

                return $this->rows;
            }
        };

        $this->app->instance(LeaderboardQuery::class, $leaderboard);
        $filters = [
            'user_id' => '10',
            'start_at' => '2026-01-01',
            'finish_at' => '2026-01-31',
        ];
        $query = '?user_id=10&start_at=2026-01-01&finish_at=2026-01-31';
        $expected = ['data' => [
            'is_finished' => true,
            'is_calculated' => true,
            'leaderboard' => $rows,
        ]];

        [$legacy, $versioned] = $this->withFrozenTime('2026-02-01 12:00:00', function () use ($query, $expected): array {
            $legacy = $this->getJson('/api/leaderboard-winner'.$query)
                ->assertOk()
                ->assertExactJson($expected)
                ->json();
            $versioned = $this->getJson('/api/v1/imagery/leaderboard-winner'.$query)
                ->assertOk()
                ->assertExactJson($expected)
                ->json();

            return [$legacy, $versioned];
        });

        $this->assertSame($legacy, $versioned);
        $this->assertSame([
            ['filters' => $filters, 'limit' => 3, 'score_version' => LeaderboardQuery::SCORE_VERSION_SEQUENCE],
            ['filters' => $filters, 'limit' => 3, 'score_version' => LeaderboardQuery::SCORE_VERSION_SEQUENCE],
        ], $leaderboard->calls);
    }

    public function test_versioned_leaderboard_winner_partial_date_returns_flags_only(): void
    {
        foreach ([
            'start_at=2026-01-01',
            'finish_at=2026-01-31',
            'start_at=&finish_at=2026-01-31',
            'start_at=2026-01-01&finish_at=',
        ] as $query) {
            $this->getJson('/api/v1/imagery/leaderboard-winner?'.$query)
                ->assertOk()
                ->assertExactJson([
                    'data' => [
                        'is_finished' => false,
                        'is_calculated' => false,
                    ],
                ]);
        }
    }

    public function test_versioned_leaderboard_winner_finish_boundaries_are_deterministic(): void
    {
        $cases = [
            ['2026-02-01 11:59:59', true],
            ['2026-02-01 12:00:00', false],
            ['2026-02-01 12:00:01', false],
        ];

        $this->withFrozenTime('2026-02-01 12:00:00', function () use ($cases): void {
            foreach ($cases as [$finishAt, $isFinished]) {
                $query = http_build_query([
                    'start_at' => '2026-01-01',
                    'finish_at' => $finishAt,
                ]);

                $this->getJson('/api/v1/imagery/leaderboard-winner?'.$query)
                    ->assertOk()
                    ->assertExactJson([
                        'data' => [
                            'is_finished' => $isFinished,
                            'is_calculated' => false,
                        ],
                    ]);
            }
        });
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

<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LeaderboardCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.leaderboard.excluded_role_slugs', ['internal']);

        Schema::create('default_users_users', function ($table): void {
            $table->integer('id')->primary();
            $table->string('username');
            $table->string('display_name')->nullable();
            $table->string('user_profile_photo')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_mapilio_sequence_detail', function ($table): void {
            $table->id();
            $table->integer('created_by_id');
            $table->string('sequence_uuid');
            $table->decimal('sequence_point', 12, 2);
            $table->decimal('length_km', 12, 2);
            $table->boolean('anomaly')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_mapilio_imagery', function ($table): void {
            $table->id();
            $table->integer('created_by_id');
            $table->string('sequence_uuid');
            $table->decimal('ukm_score', 12, 2)->nullable();
            $table->decimal('gps_score', 12, 2)->nullable();
            $table->decimal('time_score', 12, 2)->nullable();
            $table->decimal('distance_score', 12, 2)->nullable();
            $table->boolean('anomaly')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_users_roles', function ($table): void {
            $table->integer('id')->primary();
            $table->string('slug');
        });

        Schema::create('default_users_users_roles', function ($table): void {
            $table->integer('entry_id');
            $table->integer('related_id');
        });

        Schema::getConnection()->table('default_users_users')->insert([
            [
                'id' => 10,
                'username' => 'alice',
                'display_name' => 'Alice',
                'user_profile_photo' => 'https://images.example/alice.jpg',
                'deleted_at' => null,
            ],
            [
                'id' => 20,
                'username' => 'bob',
                'display_name' => 'Bob',
                'user_profile_photo' => null,
                'deleted_at' => null,
            ],
            [
                'id' => 30,
                'username' => 'staff',
                'display_name' => 'Staff',
                'user_profile_photo' => null,
                'deleted_at' => null,
            ],
        ]);

        Schema::getConnection()->table('default_mapilio_sequence_detail')->insert([
            [
                'created_by_id' => 10,
                'sequence_uuid' => 'seq-a',
                'sequence_point' => 120,
                'length_km' => 5.12,
                'anomaly' => false,
                'created_at' => '2026-01-01 12:00:00',
                'deleted_at' => null,
            ],
            [
                'created_by_id' => 10,
                'sequence_uuid' => 'seq-b',
                'sequence_point' => 80,
                'length_km' => 4.55,
                'anomaly' => false,
                'created_at' => '2026-01-02 12:00:00',
                'deleted_at' => null,
            ],
            [
                'created_by_id' => 20,
                'sequence_uuid' => 'seq-c',
                'sequence_point' => 350,
                'length_km' => 2,
                'anomaly' => false,
                'created_at' => '2026-01-03 12:00:00',
                'deleted_at' => null,
            ],
            [
                'created_by_id' => 30,
                'sequence_uuid' => 'seq-d',
                'sequence_point' => 900,
                'length_km' => 12,
                'anomaly' => false,
                'created_at' => '2026-01-04 12:00:00',
                'deleted_at' => null,
            ],
        ]);

        Schema::getConnection()->table('default_mapilio_imagery')->insert([
            ['created_by_id' => 10, 'sequence_uuid' => 'seq-a', 'ukm_score' => 40, 'gps_score' => 5, 'time_score' => 3, 'distance_score' => 2, 'anomaly' => false, 'created_at' => '2026-01-01 12:00:00', 'deleted_at' => null],
            ['created_by_id' => 10, 'sequence_uuid' => 'seq-a', 'ukm_score' => 50, 'gps_score' => 5, 'time_score' => 3, 'distance_score' => 2, 'anomaly' => false, 'created_at' => '2026-01-01 12:01:00', 'deleted_at' => null],
            ['created_by_id' => 10, 'sequence_uuid' => 'seq-b', 'ukm_score' => 20, 'gps_score' => 2, 'time_score' => 2, 'distance_score' => 1, 'anomaly' => false, 'created_at' => '2026-01-02 12:00:00', 'deleted_at' => null],
            ['created_by_id' => 20, 'sequence_uuid' => 'seq-c', 'ukm_score' => 400, 'gps_score' => 50, 'time_score' => 30, 'distance_score' => 20, 'anomaly' => false, 'created_at' => '2026-01-03 12:00:00', 'deleted_at' => null],
            ['created_by_id' => 30, 'sequence_uuid' => 'seq-d', 'ukm_score' => 900, 'gps_score' => 50, 'time_score' => 30, 'distance_score' => 19, 'anomaly' => false, 'created_at' => '2026-01-04 12:00:00', 'deleted_at' => null],
        ]);

        Schema::getConnection()->table('default_users_roles')->insert([
            ['id' => 1, 'slug' => 'user'],
            ['id' => 2, 'slug' => 'internal'],
        ]);

        Schema::getConnection()->table('default_users_users_roles')->insert([
            ['entry_id' => 10, 'related_id' => 1],
            ['entry_id' => 30, 'related_id' => 2],
        ]);
    }

    public function test_legacy_leaderboard_path_preserves_response_shape(): void
    {
        $this->getJson('/api/leaderboard')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'leaderboard' => [
                        [
                            'id' => 20,
                            'username' => 'bob',
                            'display_name' => 'Bob',
                            'user_profile_photo' => null,
                            'point' => '350',
                            'total_length' => '2.00',
                            'total_images' => 1,
                            'roles' => null,
                        ],
                        [
                            'id' => 10,
                            'username' => 'alice',
                            'display_name' => 'Alice',
                            'user_profile_photo' => 'https://images.example/alice.jpg',
                            'point' => '200',
                            'total_length' => '9.67',
                            'total_images' => 3,
                            'roles' => '{user}',
                        ],
                    ],
                ],
            ]);
    }

    public function test_versioned_leaderboard_alias_returns_same_contract(): void
    {
        $legacy = $this->getJson('/api/leaderboard')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/imagery/leaderboard')
            ->assertOk()
            ->json();

        $this->assertSame($legacy, $versioned);
    }

    public function test_legacy_v2_leaderboard_path_uses_image_score_contract(): void
    {
        $this->getJson('/api/v2/leaderboard')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'leaderboard' => [
                        [
                            'id' => 20,
                            'username' => 'bob',
                            'display_name' => 'Bob',
                            'user_profile_photo' => null,
                            'point' => '500',
                            'total_length' => '2.00',
                            'total_images' => 1,
                            'roles' => null,
                        ],
                        [
                            'id' => 10,
                            'username' => 'alice',
                            'display_name' => 'Alice',
                            'user_profile_photo' => 'https://images.example/alice.jpg',
                            'point' => '135',
                            'total_length' => '9.67',
                            'total_images' => 3,
                            'roles' => '{user}',
                        ],
                    ],
                ],
            ]);
    }

    public function test_legacy_v2_leaderboard_respects_user_filter(): void
    {
        $this->getJson('/api/v2/leaderboard?user_id=10')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'leaderboard' => [
                        [
                            'id' => 10,
                            'username' => 'alice',
                            'display_name' => 'Alice',
                            'user_profile_photo' => 'https://images.example/alice.jpg',
                            'point' => '135',
                            'total_length' => '9.67',
                            'total_images' => 3,
                            'roles' => '{user}',
                        ],
                    ],
                ],
            ]);
    }

    public function test_get_point_by_user_preserves_legacy_wrapper_and_pagination(): void
    {
        $this->getJson('/api/get-point-by-user?user_id=10')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'leaderboard' => [
                            [
                                'id' => 10,
                                'username' => 'alice',
                                'display_name' => 'Alice',
                                'user_profile_photo' => 'https://images.example/alice.jpg',
                                'point' => '200',
                                'total_length' => '9.67',
                                'total_images' => 3,
                                'roles' => '{user}',
                            ],
                        ],
                    ],
                ],
                'pagination' => [
                    'current_page' => 1,
                    'first_page_url' => '/api/get-point-by-user?user_id=10&page=1',
                    'from' => 1,
                    'last_page' => 1,
                    'last_page_url' => '/api/get-point-by-user?user_id=10&page=1',
                    'links' => [
                        [
                            'url' => null,
                            'label' => '&laquo; Previous',
                            'active' => false,
                        ],
                        [
                            'url' => '/api/get-point-by-user?user_id=10&page=1',
                            'label' => '1',
                            'active' => true,
                        ],
                        [
                            'url' => null,
                            'label' => 'Next &raquo;',
                            'active' => false,
                        ],
                    ],
                    'next_page_url' => null,
                    'path' => '/api/get-point-by-user',
                    'per_page' => 15,
                    'prev_page_url' => null,
                    'to' => 1,
                    'total' => 0,
                ],
            ]);
    }

    public function test_versioned_user_points_alias_returns_same_payload_data(): void
    {
        $legacy = $this->getJson('/api/get-point-by-user?user_id=10')
            ->assertOk()
            ->json('data');

        $versioned = $this->getJson('/api/v1/imagery/user-points?user_id=10')
            ->assertOk()
            ->json('data');

        $this->assertSame($legacy, $versioned);
    }

    public function test_get_point_by_user_preserves_missing_user_error_shape(): void
    {
        $this->getJson('/api/get-point-by-user')
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'user_id' is required!"],
                'error_code' => 400,
            ]);
    }
}

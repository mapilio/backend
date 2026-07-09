<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrganizationLeaderboardCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('default_organizations_organization', function ($table): void {
            $table->id();
            $table->string('organization_key');
            $table->string('organization_name')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_mapilio_sequence_detail', function ($table): void {
            $table->id();
            $table->string('sequence_uuid');
            $table->string('organization_key')->nullable();
            $table->decimal('sequence_point', 12, 2)->nullable();
            $table->decimal('length_km', 12, 2)->nullable();
            $table->boolean('anomaly')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_mapilio_imagery', function ($table): void {
            $table->id();
            $table->string('sequence_uuid');
            $table->string('organization_key')->nullable();
            $table->decimal('ukm_score', 12, 2)->nullable();
            $table->boolean('anomaly')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::getConnection()->table('default_organizations_organization')->insert([
            [
                'organization_key' => 'org-a',
                'organization_name' => 'Open Roads',
                'deleted_at' => null,
            ],
            [
                'organization_key' => 'org-b',
                'organization_name' => 'City Mapping',
                'deleted_at' => null,
            ],
            [
                'organization_key' => 'org-deleted',
                'organization_name' => 'Deleted Org',
                'deleted_at' => '2026-01-01 00:00:00',
            ],
        ]);

        Schema::getConnection()->table('default_mapilio_sequence_detail')->insert([
            ['sequence_uuid' => 'seq-a1', 'organization_key' => 'org-a', 'sequence_point' => 100, 'length_km' => 3.33, 'anomaly' => false, 'deleted_at' => null],
            ['sequence_uuid' => 'seq-a2', 'organization_key' => 'org-a', 'sequence_point' => 50, 'length_km' => 2, 'anomaly' => false, 'deleted_at' => null],
            ['sequence_uuid' => 'seq-b1', 'organization_key' => 'org-b', 'sequence_point' => 200, 'length_km' => 1, 'anomaly' => false, 'deleted_at' => null],
            ['sequence_uuid' => 'seq-anomaly', 'organization_key' => 'org-b', 'sequence_point' => 900, 'length_km' => 9, 'anomaly' => true, 'deleted_at' => null],
            ['sequence_uuid' => 'seq-deleted', 'organization_key' => 'org-b', 'sequence_point' => 800, 'length_km' => 8, 'anomaly' => false, 'deleted_at' => '2026-01-02 00:00:00'],
            ['sequence_uuid' => 'seq-deleted-org', 'organization_key' => 'org-deleted', 'sequence_point' => 700, 'length_km' => 7, 'anomaly' => false, 'deleted_at' => null],
            ['sequence_uuid' => 'seq-no-org', 'organization_key' => null, 'sequence_point' => 600, 'length_km' => 6, 'anomaly' => false, 'deleted_at' => null],
        ]);

        Schema::getConnection()->table('default_mapilio_imagery')->insert([
            ['sequence_uuid' => 'seq-a1', 'organization_key' => 'org-a', 'ukm_score' => 100, 'anomaly' => false, 'deleted_at' => null],
            ['sequence_uuid' => 'seq-a1', 'organization_key' => 'org-a', 'ukm_score' => 150, 'anomaly' => false, 'deleted_at' => null],
            ['sequence_uuid' => 'seq-a2', 'organization_key' => 'org-a', 'ukm_score' => 50, 'anomaly' => false, 'deleted_at' => null],
            ['sequence_uuid' => 'seq-b1', 'organization_key' => 'org-b', 'ukm_score' => 40, 'anomaly' => false, 'deleted_at' => null],
            ['sequence_uuid' => 'seq-anomaly', 'organization_key' => 'org-b', 'ukm_score' => 900, 'anomaly' => true, 'deleted_at' => null],
            ['sequence_uuid' => 'seq-deleted', 'organization_key' => 'org-b', 'ukm_score' => 800, 'anomaly' => false, 'deleted_at' => '2026-01-02 00:00:00'],
            ['sequence_uuid' => 'seq-deleted-org', 'organization_key' => 'org-deleted', 'ukm_score' => 700, 'anomaly' => false, 'deleted_at' => null],
        ]);
    }

    public function test_legacy_organization_leaderboard_path_preserves_sequence_point_contract(): void
    {
        $this->getJson('/api/leaderboard-organization')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'leaderboard' => [
                        [
                            'organization_key' => 'org-b',
                            'organization_name' => 'City Mapping',
                            'point' => '200',
                            'total_length' => '1.00',
                            'total_images' => 1,
                        ],
                        [
                            'organization_key' => 'org-a',
                            'organization_name' => 'Open Roads',
                            'point' => '150',
                            'total_length' => '5.33',
                            'total_images' => 3,
                        ],
                    ],
                ],
            ]);
    }

    public function test_legacy_organization_v2_leaderboard_path_uses_ukm_score_contract(): void
    {
        $this->getJson('/api/leaderboard-organization-v2')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'leaderboard' => [
                        [
                            'organization_key' => 'org-a',
                            'organization_name' => 'Open Roads',
                            'point' => '300',
                            'total_length' => '5.33',
                            'total_images' => 3,
                        ],
                        [
                            'organization_key' => 'org-b',
                            'organization_name' => 'City Mapping',
                            'point' => '40',
                            'total_length' => '1.00',
                            'total_images' => 1,
                        ],
                    ],
                ],
            ]);
    }

    public function test_versioned_organization_leaderboard_alias_returns_same_contract(): void
    {
        $legacy = $this->getJson('/api/leaderboard-organization')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/organizations/leaderboard')
            ->assertOk()
            ->json();

        $this->assertSame($legacy, $versioned);
    }
}

<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MarketplaceCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('default_projects_project', function ($table): void {
            $table->id();
            $table->string('marketplace_name')->nullable();
            $table->text('marketplace_description')->nullable();
            $table->string('project_key')->nullable();
            $table->string('project_organization_key')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->integer('project_shape_id')->nullable();
            $table->integer('project_entry_id')->nullable();
            $table->boolean('is_marketplace')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_shapes_shape', function ($table): void {
            $table->id();
            $table->text('polygon_geojson')->nullable();
            $table->float('centroid_lon')->nullable();
            $table->float('centroid_lat')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_organizations_organization', function ($table): void {
            $table->id();
            $table->string('organization_key')->nullable();
            $table->string('organization_name')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_projects_imagery_capture', function ($table): void {
            $table->id();
            $table->string('project_camera_type')->nullable();
        });

        Schema::getConnection()->table('default_shapes_shape')->insert([
            [
                'id' => 10,
                'polygon_geojson' => '{"type":"Polygon","coordinates":[[[29,41],[30,41],[30,42],[29,42],[29,41]]]}',
                'centroid_lon' => 29.5,
                'centroid_lat' => 41.5,
                'deleted_at' => null,
            ],
            [
                'id' => 11,
                'polygon_geojson' => '{"type":"Polygon","coordinates":[[[1,1],[2,1],[2,2],[1,2],[1,1]]]}',
                'centroid_lon' => 1.5,
                'centroid_lat' => 1.5,
                'deleted_at' => null,
            ],
        ]);

        Schema::getConnection()->table('default_organizations_organization')->insert([
            [
                'organization_key' => 'org-main',
                'organization_name' => 'Mapilio Official',
                'deleted_at' => null,
            ],
        ]);

        Schema::getConnection()->table('default_projects_imagery_capture')->insert([
            ['id' => 20, 'project_camera_type' => 'phone'],
            ['id' => 21, 'project_camera_type' => 'dashcam'],
        ]);

        Schema::getConnection()->table('default_projects_project')->insert([
            [
                'id' => 2,
                'marketplace_name' => 'Istanbul Capture',
                'marketplace_description' => 'Collect street-level imagery in Istanbul.',
                'project_key' => 'project-istanbul',
                'project_organization_key' => 'org-main',
                'created_at' => '2026-07-01 10:11:12',
                'project_shape_id' => 10,
                'project_entry_id' => 20,
                'is_marketplace' => true,
                'deleted_at' => null,
            ],
            [
                'id' => 3,
                'marketplace_name' => 'Near Equator Capture',
                'marketplace_description' => 'Collect a compact sample area.',
                'project_key' => 'project-equator',
                'project_organization_key' => 'org-main',
                'created_at' => '2026-07-02 11:12:13',
                'project_shape_id' => 11,
                'project_entry_id' => 21,
                'is_marketplace' => true,
                'deleted_at' => null,
            ],
            [
                'id' => 4,
                'marketplace_name' => 'Private Project',
                'marketplace_description' => 'Not visible in marketplace.',
                'project_key' => 'project-private',
                'project_organization_key' => 'org-main',
                'created_at' => '2026-07-03 12:13:14',
                'project_shape_id' => 10,
                'project_entry_id' => 20,
                'is_marketplace' => false,
                'deleted_at' => null,
            ],
        ]);
    }

    public function test_legacy_marketplaces_preserves_geojson_string_contract(): void
    {
        $expectedGeojson = json_encode([
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'properties' => [
                        'marketplace_name' => 'Istanbul Capture',
                        'marketplace_description' => 'Collect street-level imagery in Istanbul.',
                        'id' => 2,
                        'project_key' => 'project-istanbul',
                        'organization_key' => 'org-main',
                        'created_at' => '2026-07-01T10:11:12',
                        'owner' => 'Mapilio Official',
                        'project_camera_type' => 'phone',
                        'distance_km' => '0',
                    ],
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[
                            [29, 41],
                            [30, 41],
                            [30, 42],
                            [29, 42],
                            [29, 41],
                        ]],
                    ],
                ],
                [
                    'type' => 'Feature',
                    'properties' => [
                        'marketplace_name' => 'Near Equator Capture',
                        'marketplace_description' => 'Collect a compact sample area.',
                        'id' => 3,
                        'project_key' => 'project-equator',
                        'organization_key' => 'org-main',
                        'created_at' => '2026-07-02T11:12:13',
                        'owner' => 'Mapilio Official',
                        'project_camera_type' => 'dashcam',
                        'distance_km' => '0',
                    ],
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[
                            [1, 1],
                            [2, 1],
                            [2, 2],
                            [1, 2],
                            [1, 1],
                        ]],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $this->getJson('/api/get-marketplaces')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'geojson' => $expectedGeojson,
                ],
            ]);
    }

    public function test_marketplaces_malformed_created_timestamps_return_null(): void
    {
        Schema::getConnection()->table('default_projects_project')->update([
            'created_at' => 'not-a-date',
        ]);

        $response = $this->getJson('/api/get-marketplaces')
            ->assertOk()
            ->json();
        $geojson = json_decode($response['data']['geojson'], true);

        foreach ($geojson['features'] as $feature) {
            $this->assertNull($feature['properties']['created_at']);
        }
    }

    public function test_versioned_marketplaces_alias_returns_same_contract(): void
    {
        $legacy = $this->getJson('/api/get-marketplaces')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/projects/marketplaces')
            ->assertOk()
            ->json();

        $this->assertSame($legacy, $versioned);
    }

    public function test_marketplaces_coordinates_sort_by_distance_and_return_numeric_distance(): void
    {
        $response = $this->getJson('/api/get-marketplaces?lat=1&lon=1')
            ->assertOk()
            ->json();

        $geojson = json_decode($response['data']['geojson'], true);

        $this->assertSame('project-equator', $geojson['features'][0]['properties']['project_key']);
        $this->assertSame('project-istanbul', $geojson['features'][1]['properties']['project_key']);
        $this->assertIsFloat($geojson['features'][0]['properties']['distance_km']);
    }

    public function test_zero_coordinates_preserve_legacy_empty_parameter_behavior(): void
    {
        $response = $this->getJson('/api/get-marketplaces?lat=0&lon=0')
            ->assertOk()
            ->json();

        $geojson = json_decode($response['data']['geojson'], true);

        $this->assertSame('project-istanbul', $geojson['features'][0]['properties']['project_key']);
        $this->assertSame('0', $geojson['features'][0]['properties']['distance_km']);
    }

    public function test_marketplaces_rejects_invalid_active_coordinates_before_sql(): void
    {
        $this->getJson('/api/get-marketplaces?lat=1&lon=not-a-number')
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'lat' and 'lon' must be numeric coordinates."],
                'error_code' => 400,
            ]);
    }
}

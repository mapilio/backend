<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_versioned_marketplaces_preserves_exact_default_geojson_string_contract(): void
    {
        $legacy = $this->marketplaces('/api/get-marketplaces');
        $versioned = $this->marketplaces('/api/v1/projects/marketplaces');

        $this->assertSame($legacy, $versioned);
        $this->assertSame(['data'], array_keys($versioned));
        $this->assertSame(['geojson'], array_keys($versioned['data']));
        $this->assertIsString($versioned['data']['geojson']);

        $geojson = $this->decodeGeojson($versioned);
        $this->assertSame(['type', 'features'], array_keys($geojson));
        $this->assertSame('FeatureCollection', $geojson['type']);
        $this->assertIsArray($geojson['features']);
        $this->assertEqualsCanonicalizing([2, 3], array_map(
            static fn (array $feature): int => $feature['properties']['id'],
            $geojson['features'],
        ));

        foreach ($geojson['features'] as $feature) {
            $this->assertMarketplaceFeature($feature);
            $this->assertIsString($feature['properties']['distance_km']);
            $this->assertSame('0', $feature['properties']['distance_km']);
        }
    }

    public function test_versioned_marketplaces_preserves_nullable_fields_and_null_geometry(): void
    {
        Schema::getConnection()->table('default_projects_project')->where('id', 3)->update([
            'marketplace_name' => null,
            'marketplace_description' => null,
            'project_key' => null,
            'project_organization_key' => null,
            'created_at' => null,
            'project_shape_id' => null,
            'project_entry_id' => null,
        ]);

        $geojson = $this->decodeGeojson($this->marketplaces('/api/v1/projects/marketplaces'));
        $feature = $this->featureById($geojson, 3);
        $properties = $feature['properties'];

        $this->assertMarketplaceFeature($feature);
        $this->assertSame([
            'marketplace_name' => null,
            'marketplace_description' => null,
            'id' => 3,
            'project_key' => null,
            'organization_key' => null,
            'created_at' => null,
            'owner' => null,
            'project_camera_type' => null,
            'distance_km' => '0',
        ], $properties);
        $this->assertNull($feature['geometry']);
    }

    public function test_versioned_marketplaces_malformed_created_timestamps_match_legacy(): void
    {
        Schema::getConnection()->table('default_projects_project')->update([
            'created_at' => 'not-a-date',
        ]);

        $legacy = $this->decodeGeojson($this->marketplaces('/api/get-marketplaces'));
        $versioned = $this->decodeGeojson($this->marketplaces('/api/v1/projects/marketplaces'));

        $this->assertSame($legacy, $versioned);
        foreach ($versioned['features'] as $feature) {
            $this->assertNull($feature['properties']['created_at']);
        }
    }

    public function test_versioned_marketplaces_substitute_invalid_utf8_like_legacy(): void
    {
        $description = "Before \xC3\x28 after";

        Schema::getConnection()->table('default_projects_project')->where('id', 2)->update([
            'marketplace_description' => $description,
        ]);

        $legacy = $this->marketplaces('/api/get-marketplaces');
        $versioned = $this->marketplaces('/api/v1/projects/marketplaces');

        $this->assertSame($legacy, $versioned);
        $decoded = $this->decodeGeojson($versioned);
        $this->assertSame(
            "Before \xEF\xBF\xBD( after",
            $this->featureById($decoded, 2)['properties']['marketplace_description'],
        );
    }

    public function test_versioned_marketplaces_coordinates_match_legacy_order_and_types(): void
    {
        $legacy = $this->decodeGeojson($this->marketplaces('/api/get-marketplaces?lat=1&lon=1'));
        $versioned = $this->decodeGeojson($this->marketplaces('/api/v1/projects/marketplaces?lat=1&lon=1'));

        $this->assertSame($legacy, $versioned);
        $this->assertSame([
            'project-equator',
            'project-istanbul',
        ], array_map(
            static fn (array $feature): string => $feature['properties']['project_key'],
            $versioned['features'],
        ));
        foreach ($versioned['features'] as $feature) {
            $this->assertIsFloat($feature['properties']['distance_km']);
        }
    }

    #[DataProvider('inactiveCoordinateQueryProvider')]
    public function test_versioned_marketplaces_inactive_or_partial_coordinate_strings_match_legacy(string $query): void
    {
        $legacy = $this->marketplaces('/api/get-marketplaces'.$query);
        $versioned = $this->marketplaces('/api/v1/projects/marketplaces'.$query);

        $this->assertSame($legacy, $versioned);
        $geojson = $this->decodeGeojson($versioned);
        foreach ($geojson['features'] as $feature) {
            $this->assertSame('0', $feature['properties']['distance_km']);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function inactiveCoordinateQueryProvider(): array
    {
        return [
            'missing coordinates' => [''],
            'zero pair' => ['?lat=0&lon=0'],
            'zero latitude' => ['?lat=0&lon=1'],
            'zero longitude' => ['?lat=1&lon=0'],
            'empty latitude' => ['?lat=&lon=1'],
            'empty longitude' => ['?lat=1&lon='],
        ];
    }

    public function test_versioned_marketplaces_decimal_zero_strings_remain_active(): void
    {
        $legacy = $this->decodeGeojson($this->marketplaces('/api/get-marketplaces?lat=0.0&lon=0.0'));
        $versioned = $this->decodeGeojson($this->marketplaces('/api/v1/projects/marketplaces?lat=0.0&lon=0.0'));

        $this->assertSame($legacy, $versioned);
        $this->assertSame([
            'project-equator',
            'project-istanbul',
        ], array_map(
            static fn (array $feature): string => $feature['properties']['project_key'],
            $versioned['features'],
        ));
        foreach ($versioned['features'] as $feature) {
            $this->assertIsFloat($feature['properties']['distance_km']);
        }
    }

    public function test_versioned_marketplaces_exact_centroid_zero_distance_is_numeric(): void
    {
        $legacy = $this->decodeGeojson($this->marketplaces('/api/get-marketplaces?lat=1.5&lon=1.5'));
        $versioned = $this->decodeGeojson($this->marketplaces('/api/v1/projects/marketplaces?lat=1.5&lon=1.5'));

        $this->assertSame($legacy, $versioned);
        $distance = $this->featureById($versioned, 3)['properties']['distance_km'];

        $this->assertIsInt($distance);
        $this->assertSame(0, $distance);
        $this->assertNotSame('0', $distance);
    }

    public function test_versioned_marketplaces_active_coordinates_with_missing_geometry_match_legacy(): void
    {
        Schema::getConnection()->table('default_projects_project')->where('id', 3)->update([
            'project_shape_id' => null,
        ]);

        $this->assertSame('sqlite', Schema::getConnection()->getDriverName());
        $legacy = $this->decodeGeojson($this->marketplaces('/api/get-marketplaces?lat=1&lon=1'));
        $versioned = $this->decodeGeojson($this->marketplaces('/api/v1/projects/marketplaces?lat=1&lon=1'));

        $this->assertSame($legacy, $versioned);
        $missingGeometry = $this->featureById($versioned, 3);
        $this->assertNull($missingGeometry['geometry']);
        $this->assertSame('0', $missingGeometry['properties']['distance_km']);
    }

    #[DataProvider('invalidCoordinateQueryProvider')]
    public function test_versioned_marketplaces_coordinate_400_envelopes_match_legacy(string $query, string $message): void
    {
        $expected = [
            'success' => false,
            'message' => [$message],
            'error_code' => 400,
        ];

        $legacy = $this->getJson('/api/get-marketplaces'.$query)->assertStatus(400)->json();
        $versioned = $this->getJson('/api/v1/projects/marketplaces'.$query)->assertStatus(400)->json();

        $this->assertSame($expected, $legacy);
        $this->assertSame($legacy, $versioned);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidCoordinateQueryProvider(): array
    {
        return [
            'non numeric longitude' => [
                '?lat=1&lon=not-a-number',
                "'lat' and 'lon' must be numeric coordinates.",
            ],
            'non numeric latitude' => [
                '?lat=not-a-number&lon=1',
                "'lat' and 'lon' must be numeric coordinates.",
            ],
            'latitude above range' => [
                '?lat=91&lon=1',
                "'lat' and 'lon' must be valid coordinates.",
            ],
            'longitude above range' => [
                '?lat=1&lon=181',
                "'lat' and 'lon' must be valid coordinates.",
            ],
            'latitude below range' => [
                '?lat=-91&lon=1',
                "'lat' and 'lon' must be valid coordinates.",
            ],
            'longitude below range' => [
                '?lat=1&lon=-181',
                "'lat' and 'lon' must be valid coordinates.",
            ],
        ];
    }

    public function test_versioned_marketplaces_empty_features_are_null(): void
    {
        Schema::getConnection()->table('default_projects_project')->update([
            'is_marketplace' => false,
        ]);

        $geojson = $this->decodeGeojson($this->marketplaces('/api/v1/projects/marketplaces'));

        $this->assertSame(['type', 'features'], array_keys($geojson));
        $this->assertSame('FeatureCollection', $geojson['type']);
        $this->assertNull($geojson['features']);
    }

    /**
     * @return array<string, mixed>
     */
    private function marketplaces(string $path): array
    {
        return $this->getJson($path)->assertOk()->json();
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function decodeGeojson(array $response): array
    {
        $this->assertIsString($response['data']['geojson']);
        $decoded = json_decode($response['data']['geojson'], true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $geojson
     * @return array<string, mixed>
     */
    private function featureById(array $geojson, int $id): array
    {
        foreach ($geojson['features'] as $feature) {
            if ($feature['properties']['id'] === $id) {
                return $feature;
            }
        }

        $this->fail("Marketplace feature {$id} was not found.");
    }

    /**
     * @param  array<string, mixed>  $feature
     */
    private function assertMarketplaceFeature(array $feature): void
    {
        $this->assertSame(['type', 'properties', 'geometry'], array_keys($feature));
        $this->assertSame('Feature', $feature['type']);
        $this->assertSame([
            'marketplace_name',
            'marketplace_description',
            'id',
            'project_key',
            'organization_key',
            'created_at',
            'owner',
            'project_camera_type',
            'distance_km',
        ], array_keys($feature['properties']));
        $this->assertIsInt($feature['properties']['id']);

        foreach ([
            'marketplace_name',
            'marketplace_description',
            'project_key',
            'organization_key',
            'created_at',
            'owner',
            'project_camera_type',
        ] as $field) {
            $this->assertTrue($feature['properties'][$field] === null || is_string($feature['properties'][$field]));
        }

        $this->assertTrue($feature['geometry'] === null || is_array($feature['geometry']));
        $this->assertTrue(
            $feature['properties']['distance_km'] === null
                || $feature['properties']['distance_km'] === '0'
                || is_int($feature['properties']['distance_km'])
                || is_float($feature['properties']['distance_km']),
        );
    }
}

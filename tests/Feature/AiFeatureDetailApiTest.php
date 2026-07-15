<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiFeatureDetailApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.ai_result_persistence.max_matches_per_feature', 1000);
        Config::set('mapilio.ai_feature_api.cache_ttl', 60);
        Config::set('mapilio.ai_feature_api.stale_while_revalidate', 300);
        Config::set('mapilio.image_server.cdn_base_url', 'https://images.example.test');
        Config::set('mapilio.image_server.image_path_prefix', 'im');

        $this->createTables();
        $this->seedFeatureGraph();
    }

    public function test_versioned_feature_detail_returns_the_public_detection_graph_without_n_plus_one_queries(): void
    {
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'ai_detection_') || str_contains($query->sql, 'default_mapilio_imagery')) {
                $queries[] = $query->sql;
            }
        });

        $response = $this->getJson('/api/v1/geo/ai-features/7')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public, stale-while-revalidate=300')
            ->assertJsonMissingPath('data.properties.response_id')
            ->assertJsonMissingPath('data.properties.callback_receipt_id')
            ->assertJsonPath('data.type', 'Feature')
            ->assertJsonPath('data.id', 7)
            ->assertJsonPath('data.geometry.type', 'Point')
            ->assertJsonPath('data.geometry.coordinates.0', 29.0255)
            ->assertJsonPath('data.properties.class_code', 'stop-sign')
            ->assertJsonPath('data.properties.confidence', 0.91)
            ->assertJsonPath('data.properties.verified', true)
            ->assertJsonPath('data.properties.dimensions.width', 0.8)
            ->assertJsonPath('data.properties.attributes.color', 'red')
            ->assertJsonPath('data.properties.sequence_uuid', 'sequence-ai-1')
            ->assertJsonPath('data.properties.created_at', '2026-07-15T10:00:00+00:00')
            ->assertJsonCount(1, 'data.matches')
            ->assertJsonPath('data.matches.0.source_index', 0)
            ->assertJsonPath('data.matches.0.observation_1.object_key', 'object-left')
            ->assertJsonPath('data.matches.0.observation_1.bbox', [10, 20, 110, 220])
            ->assertJsonPath('data.matches.0.observation_1.segmentation.0', [10, 20])
            ->assertJsonPath('data.matches.0.observation_1.image.uploaded_hash', 'hash/left')
            ->assertJsonPath('data.matches.0.observation_1.image.geometry.coordinates', [29.0251, 40.9911])
            ->assertJsonPath(
                'data.matches.0.observation_1.image.urls.original',
                'https://images.example.test/im/hash%2Fleft/left%20image.jpg',
            )
            ->assertJsonPath(
                'data.matches.0.observation_1.image.urls.preview_480',
                'https://images.example.test/im/hash%2Fleft/left%20image.jpg/480',
            );

        $this->assertNotNull($response->headers->get('ETag'));
        $this->assertCount(4, $queries);
    }

    public function test_missing_inactive_or_cross_sequence_imagery_is_not_exposed(): void
    {
        DB::table('default_mapilio_imagery')->where('id', 501)->update(['deleted_at' => '2026-07-15 12:00:00']);
        DB::table('default_mapilio_imagery')->where('id', 502)->update(['sequence_uuid' => 'sequence-other']);

        $this->getJson('/api/v1/geo/ai-features/7')
            ->assertOk()
            ->assertJsonPath('data.matches.0.observation_1.imagery_id', 501)
            ->assertJsonPath('data.matches.0.observation_1.image', null)
            ->assertJsonPath('data.matches.0.observation_2.imagery_id', 502)
            ->assertJsonPath('data.matches.0.observation_2.image', null);
    }

    public function test_matching_etag_returns_not_modified(): void
    {
        $etag = $this->getJson('/api/v1/geo/ai-features/7')
            ->assertOk()
            ->headers
            ->get('ETag');

        $this->withHeader('If-None-Match', (string) $etag)
            ->getJson('/api/v1/geo/ai-features/7')
            ->assertStatus(304)
            ->assertContent('');
    }

    public function test_corrupt_canonical_json_returns_a_safe_unavailable_response(): void
    {
        DB::table('ai_detection_features')->where('id', 7)->update(['geometry' => '{invalid']);

        $this->getJson('/api/v1/geo/ai-features/7')
            ->assertStatus(503)
            ->assertExactJson([
                'message' => 'AI feature detail is unavailable.',
            ]);
    }

    public function test_invalid_but_well_formed_geojson_returns_a_safe_unavailable_response(): void
    {
        DB::table('ai_detection_features')->where('id', 7)->update([
            'geometry' => json_encode(['type' => 'Point', 'coordinates' => [200, 95]]),
        ]);

        $this->getJson('/api/v1/geo/ai-features/7')
            ->assertStatus(503)
            ->assertExactJson([
                'message' => 'AI feature detail is unavailable.',
            ]);
    }

    public function test_excessive_match_graph_returns_a_safe_unavailable_response(): void
    {
        Config::set('mapilio.ai_result_persistence.max_matches_per_feature', 1);

        DB::table('ai_detection_matches')->insert([
            'id' => 52,
            'detection_feature_id' => 7,
            'observation_1_id' => 1001,
            'observation_2_id' => 1002,
            'source_index' => 1,
            'longitude' => 29.0256,
            'latitude' => 40.9916,
            'geometry' => json_encode(['type' => 'Point', 'coordinates' => [29.0256, 40.9916]]),
            'score' => 0.87,
            'created_at' => '2026-07-15 10:00:00',
            'updated_at' => '2026-07-15 10:00:00',
        ]);

        $this->getJson('/api/v1/geo/ai-features/7')
            ->assertStatus(503)
            ->assertExactJson([
                'message' => 'AI feature detail is unavailable.',
            ]);
    }

    public function test_unknown_or_invalid_feature_id_returns_the_stable_not_found_envelope(): void
    {
        $this->getJson('/api/v1/geo/ai-features/999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not Found']);

        $this->getJson('/api/v1/geo/ai-features/not-a-number')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not Found']);
    }

    private function createTables(): void
    {
        Schema::create('ai_detection_features', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('callback_receipt_id');
            $table->string('response_id');
            $table->string('sequence_uuid');
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('organization_key')->nullable();
            $table->string('project_key')->nullable();
            $table->unsignedInteger('source_index');
            $table->string('class_code');
            $table->double('confidence');
            $table->double('longitude');
            $table->double('latitude');
            $table->text('geometry');
            $table->double('width');
            $table->double('height');
            $table->double('area');
            $table->boolean('verified');
            $table->text('attributes')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_detection_observations', function ($table): void {
            $table->id();
            $table->string('response_id');
            $table->string('sequence_uuid');
            $table->string('object_key');
            $table->unsignedBigInteger('imagery_id');
            $table->double('x_min');
            $table->double('y_min');
            $table->double('x_max');
            $table->double('y_max');
            $table->double('score');
            $table->text('segmentation')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_detection_matches', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('detection_feature_id');
            $table->unsignedBigInteger('observation_1_id');
            $table->unsignedBigInteger('observation_2_id');
            $table->unsignedInteger('source_index');
            $table->double('longitude');
            $table->double('latitude');
            $table->text('geometry');
            $table->double('score');
            $table->timestamps();
        });

        Schema::create('default_mapilio_imagery', function ($table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('sequence_uuid');
            $table->string('uploaded_hash')->nullable();
            $table->string('filename')->nullable();
            $table->string('resolution')->nullable();
            $table->double('heading')->nullable();
            $table->timestamp('capture_time')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->double('longitude')->nullable();
            $table->double('latitude')->nullable();
            $table->boolean('anomaly')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });
    }

    private function seedFeatureGraph(): void
    {
        DB::table('ai_detection_features')->insert([
            'id' => 7,
            'callback_receipt_id' => 91,
            'response_id' => 'internal-response-id',
            'sequence_uuid' => 'sequence-ai-1',
            'created_by_id' => 10,
            'organization_key' => 'org-main',
            'project_key' => 'project-main',
            'source_index' => 0,
            'class_code' => 'stop-sign',
            'confidence' => 0.91,
            'longitude' => 29.0255,
            'latitude' => 40.9915,
            'geometry' => json_encode(['type' => 'Point', 'coordinates' => [29.0255, 40.9915]]),
            'width' => 0.8,
            'height' => 0.9,
            'area' => 0.72,
            'verified' => true,
            'attributes' => json_encode(['color' => 'red']),
            'created_at' => '2026-07-15 10:00:00',
            'updated_at' => '2026-07-15 10:01:00',
        ]);

        DB::table('ai_detection_observations')->insert([
            [
                'id' => 1001,
                'response_id' => 'internal-response-id',
                'sequence_uuid' => 'sequence-ai-1',
                'object_key' => 'object-left',
                'imagery_id' => 501,
                'x_min' => 10,
                'y_min' => 20,
                'x_max' => 110,
                'y_max' => 220,
                'score' => 0.92,
                'segmentation' => json_encode([[10, 20], [110, 220]]),
                'created_at' => '2026-07-15 10:00:00',
                'updated_at' => '2026-07-15 10:00:00',
            ],
            [
                'id' => 1002,
                'response_id' => 'internal-response-id',
                'sequence_uuid' => 'sequence-ai-1',
                'object_key' => 'object-right',
                'imagery_id' => 502,
                'x_min' => 12,
                'y_min' => 22,
                'x_max' => 112,
                'y_max' => 222,
                'score' => 0.88,
                'segmentation' => null,
                'created_at' => '2026-07-15 10:00:00',
                'updated_at' => '2026-07-15 10:00:00',
            ],
        ]);

        DB::table('ai_detection_matches')->insert([
            'id' => 51,
            'detection_feature_id' => 7,
            'observation_1_id' => 1001,
            'observation_2_id' => 1002,
            'source_index' => 0,
            'longitude' => 29.0255,
            'latitude' => 40.9915,
            'geometry' => json_encode(['type' => 'Point', 'coordinates' => [29.0255, 40.9915]]),
            'score' => 0.9,
            'created_at' => '2026-07-15 10:00:00',
            'updated_at' => '2026-07-15 10:00:00',
        ]);

        DB::table('default_mapilio_imagery')->insert([
            [
                'id' => 501,
                'sequence_uuid' => 'sequence-ai-1',
                'uploaded_hash' => 'hash/left',
                'filename' => 'left image.jpg',
                'resolution' => '4160x2336',
                'heading' => 90,
                'capture_time' => '2026-07-15 09:59:58',
                'created_by_id' => 10,
                'longitude' => 29.0251,
                'latitude' => 40.9911,
                'anomaly' => false,
                'deleted_at' => null,
            ],
            [
                'id' => 502,
                'sequence_uuid' => 'sequence-ai-1',
                'uploaded_hash' => 'hash-right',
                'filename' => 'right.jpg',
                'resolution' => '4160x2336',
                'heading' => 94,
                'capture_time' => '2026-07-15 09:59:59',
                'created_by_id' => 10,
                'longitude' => 29.0252,
                'latitude' => 40.9912,
                'anomaly' => false,
                'deleted_at' => null,
            ],
        ]);
    }
}

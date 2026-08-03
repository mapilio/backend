<?php

namespace Tests\Feature\Legacy;

use App\Jobs\CalculateSequenceUkmScores;
use App\Jobs\DispatchSequencePrediction;
use App\Jobs\ResolveSequenceAddress;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImageryUploadCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.mobile_auth.client_id', 'mobile-client');
        Config::set('mapilio.mobile_auth.client_secret', 'mobile-secret');
        Config::set('mapilio.mobile_auth.signing_key', 'test-signing-key');

        $this->createTables();
        $this->seedUsers();
    }

    public function test_mobile_upload_metadata_contract_records_imagery_and_sequence_detail(): void
    {
        $login = $this->login();

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/mapilio/imagery/upload', $this->mobilePayload())
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data', true)
            ->assertJsonPath('sequence_uuid', 'mobile-sequence-1')
            ->assertJsonPath('count', 2);

        $this->assertDatabaseHas('default_mapilio_imagery', [
            'created_by_id' => 10,
            'sequence_uuid' => 'mobile-sequence-1',
            'uploaded_hash' => 'mobile-hash-2',
            'filename' => 'IMG_0001.jpg',
            'organization_key' => 'org-main',
            'project_key' => 'project-main',
            'capture_address' => 'Kadikoy',
            'width' => 4032,
            'height' => 3024,
        ]);

        $this->assertDatabaseHas('default_mapilio_sequence_detail', [
            'created_by_id' => 10,
            'sequence_uuid' => 'mobile-sequence-1',
            'count' => 2,
            'last_status' => 'uploaded',
            'group_key' => 'mobile-group-1',
            'organization_key' => 'org-main',
            'project_key' => 'project-main',
        ]);

        $scores = Schema::getConnection()->table('default_mapilio_imagery')
            ->where('sequence_uuid', 'mobile-sequence-1')
            ->orderBy('id')
            ->get(['id', 'gps_score', 'time_score', 'distance_score', 'nearest_point_id']);

        $this->assertSame(3.0, (float) $scores[0]->gps_score);
        $this->assertSame(1.0, (float) $scores[0]->time_score);
        $this->assertSame(0.2, (float) $scores[0]->distance_score);
        $this->assertSame((int) $scores[1]->id, (int) $scores[0]->nearest_point_id);
        $this->assertNull($scores[1]->distance_score);

        $this->assertSame(0, Schema::getConnection()->table('default_mapilio_road')->count());
        $this->assertDatabaseHas('default_mapilio_sequence_detail', [
            'sequence_uuid' => 'mobile-sequence-1',
            'road_line_status' => 3,
        ]);
    }

    public function test_mapilio_kit_upload_metadata_contract_records_zip_hash_payload(): void
    {
        $login = $this->login();

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/mapilio/imagery/upload', $this->kitPayload())
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('sequence_uuid', 'kit-sequence-1');

        $this->assertDatabaseHas('default_mapilio_imagery', [
            'created_by_id' => 10,
            'sequence_uuid' => 'kit-sequence-1',
            'uploaded_hash' => 'kit-zip-hash',
            'filename' => 'kit-0001.jpg',
            'source' => 'mapilio-kit',
            'sourceUser' => 'alice@example.test',
        ]);

        $this->assertDatabaseHas('default_mapilio_imagery', [
            'sequence_uuid' => 'kit-sequence-1',
            'gps_score' => 3,
            'time_score' => 1,
            'distance_score' => null,
        ]);

        $this->assertDatabaseHas('default_mapilio_sequence_detail', [
            'sequence_uuid' => 'kit-sequence-1',
            'group_key' => 'kit-sequence-1',
            'count' => 1,
            'size' => 12.35,
            'road_line_status' => 3,
        ]);
    }

    public function test_upload_metadata_is_idempotent_for_same_capture_points(): void
    {
        $login = $this->login();

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/mapilio/imagery/upload', $this->mobilePayload())
            ->assertOk();

        Schema::getConnection()->table('default_mapilio_imagery')
            ->where('filename', 'IMG_0001.jpg')
            ->update(['gps_score' => 99]);

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/mapilio/imagery/upload', $this->mobilePayload())
            ->assertOk();

        $this->assertSame(2, Schema::getConnection()->table('default_mapilio_imagery')->count());
        $this->assertSame(1, Schema::getConnection()->table('default_mapilio_sequence_detail')->count());
        $this->assertSame(0, Schema::getConnection()->table('default_mapilio_road')->count());
        $this->assertDatabaseHas('default_mapilio_imagery', [
            'filename' => 'IMG_0001.jpg',
            'gps_score' => 99,
        ]);
    }

    public function test_upload_metadata_generates_road_line_for_three_or_more_nearby_points(): void
    {
        $login = $this->login();
        $payload = $this->mobilePayload();
        data_set($payload, 'options.parameters.json_data.1.latitude', 40.99109);
        data_set($payload, 'options.parameters.json_data.1.longitude', 29.02509);
        data_set($payload, 'options.parameters.json_data.2', $this->point([
            'filename' => 'IMG_0003.jpg',
            'latitude' => 40.99118,
            'longitude' => 29.02518,
            'captureTime' => '2026-07-01 12:00:04',
            'capture_address' => 'Kadikoy',
        ]));
        data_set($payload, 'options.parameters.summary.Information.total_images', 3);
        data_set($payload, 'options.parameters.summary.Information.count', 3);
        data_set($payload, 'options.parameters.summary.Information.processed_images', 3);

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/mapilio/imagery/upload', $payload)
            ->assertOk()
            ->assertJsonPath('count', 3);

        $this->assertSame(3, Schema::getConnection()->table('default_mapilio_imagery')->count());
        $this->assertSame(1, Schema::getConnection()->table('default_mapilio_road')->count());
        $this->assertDatabaseHas('default_mapilio_road', [
            'sequence_uuid' => 'mobile-sequence-1',
            'created_by_id' => 10,
            'organization_key' => 'org-main',
            'project_key' => 'project-main',
        ]);
        $this->assertSame(
            'LINESTRING(29.025 40.991, 29.02509 40.99109, 29.02518 40.99118)',
            Schema::getConnection()->table('default_mapilio_road')->value('geom'),
        );

        $scores = Schema::getConnection()->table('default_mapilio_imagery')
            ->orderBy('id')
            ->pluck('distance_score')
            ->all();

        $this->assertSame(0.8, (float) $scores[0]);
        $this->assertSame(0.8, (float) $scores[1]);
        $this->assertNull($scores[2]);

        $this->assertDatabaseHas('default_mapilio_sequence_detail', [
            'sequence_uuid' => 'mobile-sequence-1',
            'road_line_status' => 3,
            'road_line_status_message' => null,
        ]);
    }

    public function test_upload_metadata_requires_valid_bearer_token(): void
    {
        $this->postJson('/api/function/mapilio/imagery/upload', $this->mobilePayload())
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_upload_metadata_preserves_validation_error_shape(): void
    {
        $login = $this->login();

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/mapilio/imagery/upload', [
                'options' => [
                    'parameters' => [],
                ],
            ])
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'json_data' is required!"],
                'error_code' => 400,
            ]);

        $payload = $this->mobilePayload();
        data_set($payload, 'options.parameters.summary.Information.hash', '');

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/mapilio/imagery/upload', $payload)
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'summary.Information.hash' is required!"],
                'error_code' => 400,
            ]);
    }

    public function test_versioned_upload_alias_preserves_negative_contract_without_side_effects(): void
    {
        Queue::fake();

        $beforeImagery = Schema::getConnection()->table('default_mapilio_imagery')->count();
        $beforeSequences = Schema::getConnection()->table('default_mapilio_sequence_detail')->count();
        $beforeRoads = Schema::getConnection()->table('default_mapilio_road')->count();
        $assertNoSideEffects = function () use ($beforeImagery, $beforeSequences, $beforeRoads): void {
            $this->assertSame($beforeImagery, Schema::getConnection()->table('default_mapilio_imagery')->count());
            $this->assertSame($beforeSequences, Schema::getConnection()->table('default_mapilio_sequence_detail')->count());
            $this->assertSame($beforeRoads, Schema::getConnection()->table('default_mapilio_road')->count());
        };

        $this->postJson('/api/function/mapilio/imagery/upload', $this->mobilePayload())
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);
        $assertNoSideEffects();

        $this->postJson('/api/v1/imagery/uploads', $this->mobilePayload())
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);
        $assertNoSideEffects();

        $login = $this->login();

        $missingJsonData = ['options' => ['parameters' => []]];
        foreach (['/api/function/mapilio/imagery/upload', '/api/v1/imagery/uploads'] as $path) {
            $this->withToken($login->json('access_token'))
                ->postJson($path, $missingJsonData)
                ->assertStatus(400)
                ->assertExactJson([
                    'success' => false,
                    'message' => ["'json_data' is required!"],
                    'error_code' => 400,
                ]);
        }
        $assertNoSideEffects();

        $blankHash = $this->mobilePayload();
        data_set($blankHash, 'options.parameters.summary.Information.hash', '');
        foreach (['/api/function/mapilio/imagery/upload', '/api/v1/imagery/uploads'] as $path) {
            $this->withToken($login->json('access_token'))
                ->postJson($path, $blankHash)
                ->assertStatus(400)
                ->assertExactJson([
                    'success' => false,
                    'message' => ["'summary.Information.hash' is required!"],
                    'error_code' => 400,
                ]);
        }

        $assertNoSideEffects();
        Queue::assertNothingPushed();
    }

    public function test_versioned_upload_alias_matches_legacy_write_contract(): void
    {
        $login = $this->login();

        $this->withToken($login->json('access_token'))
            ->postJson('/api/v1/imagery/uploads', $this->kitPayload())
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('sequence_uuid', 'kit-sequence-1');
    }

    public function test_upload_queues_prediction_only_when_both_ai_flags_are_enabled(): void
    {
        Queue::fake();
        Config::set('mapilio.ai_prediction.enabled', true);
        Config::set('mapilio.ai_prediction.dispatch_after_upload', true);
        Config::set('mapilio.ai_prediction.queue', 'prediction-test');
        $login = $this->login();

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/mapilio/imagery/upload', $this->mobilePayload())
            ->assertOk();

        Queue::assertPushedOn('prediction-test', DispatchSequencePrediction::class);
        Queue::assertPushed(DispatchSequencePrediction::class, function (DispatchSequencePrediction $job): bool {
            return $job->sequenceUuid === 'mobile-sequence-1';
        });
    }

    public function test_upload_queues_address_enrichment_only_when_both_flags_are_enabled(): void
    {
        Queue::fake();
        Config::set('mapilio.address_enrichment.enabled', true);
        Config::set('mapilio.address_enrichment.dispatch_after_upload', false);
        Config::set('mapilio.address_enrichment.queue', 'address-test');
        $login = $this->login();

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/mapilio/imagery/upload', $this->mobilePayload())
            ->assertOk();

        Queue::assertNotPushed(ResolveSequenceAddress::class);

        Config::set('mapilio.address_enrichment.dispatch_after_upload', true);

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/mapilio/imagery/upload', $this->mobilePayload())
            ->assertOk();

        Queue::assertPushedOn('address-test', ResolveSequenceAddress::class);
        Queue::assertPushed(ResolveSequenceAddress::class, function (ResolveSequenceAddress $job): bool {
            return $job->sequenceUuid === 'mobile-sequence-1';
        });
    }

    public function test_upload_queues_ukm_scoring_only_when_both_flags_are_enabled(): void
    {
        Queue::fake();
        Config::set('mapilio.ukm_scoring.enabled', true);
        Config::set('mapilio.ukm_scoring.dispatch_after_upload', false);
        Config::set('mapilio.ukm_scoring.queue', 'ukm-test');
        $login = $this->login();

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/mapilio/imagery/upload', $this->mobilePayload())
            ->assertOk();

        Queue::assertNotPushed(CalculateSequenceUkmScores::class);

        Config::set('mapilio.ukm_scoring.dispatch_after_upload', true);

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/mapilio/imagery/upload', $this->mobilePayload())
            ->assertOk();

        Queue::assertPushedOn('ukm-test', CalculateSequenceUkmScores::class);
        Queue::assertPushed(CalculateSequenceUkmScores::class, function (CalculateSequenceUkmScores $job): bool {
            return $job->sequenceUuid === 'mobile-sequence-1';
        });
    }

    private function login()
    {
        return $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ])->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function mobilePayload(): array
    {
        return [
            'options' => [
                'parameters' => [
                    'organization_key' => 'org-main',
                    'project_key' => 'project-main',
                    'json_data' => [
                        $this->point([
                            'filename' => 'IMG_0001.jpg',
                            'latitude' => 40.991,
                            'longitude' => 29.025,
                            'captureTime' => '2026-07-01 12:00:00',
                            'capture_address' => 'Kadikoy',
                        ]),
                        $this->point([
                            'filename' => 'IMG_0002.jpg',
                            'latitude' => 40.992,
                            'longitude' => 29.026,
                            'captureTime' => '2026-07-01 12:00:02',
                            'capture_address' => 'Kadikoy',
                        ]),
                    ],
                    'summary' => [
                        'Information' => [
                            'total_images' => 2,
                            'count' => 2,
                            'processed_images' => 2,
                            'failed_images' => 0,
                            'anomaly_sequences' => [],
                            'sequence_uuid' => 'mobile-sequence-1',
                            'size' => 7.25,
                            'hash' => 'mobile-hash-2',
                            'group_key' => 'mobile-group-1',
                            'organization_key' => 'org-main',
                            'project_key' => 'project-main',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function kitPayload(): array
    {
        return [
            'options' => [
                'parameters' => [
                    'organization_key' => '',
                    'project_key' => '',
                    'json_data' => [
                        $this->point([
                            'filename' => 'kit-0001.jpg',
                            'latitude' => 41.001,
                            'longitude' => 29.101,
                            'captureTime' => '2026-07-02 08:15:00',
                            'sequenceUuid' => 'kit-sequence-1',
                            'source' => 'mapilio-kit',
                            'sourceUser' => 'alice@example.test',
                            'acceleration' => ['x' => 0.1, 'y' => 0.2, 'z' => 0.3],
                        ]),
                    ],
                    'summary' => [
                        'Information' => [
                            'total_images' => 1,
                            'processed_images' => 1,
                            'failed_images' => 0,
                            'sequence_uuid' => 'kit-sequence-1',
                            'count' => 1,
                            'size' => 12.345,
                            'hash' => 'kit-zip-hash',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function point(array $overrides = []): array
    {
        return array_merge([
            'latitude' => 40.991,
            'longitude' => 29.025,
            'altitude' => 12.5,
            'heading' => 180.0,
            'gyroscope' => ['x' => 0, 'y' => 0, 'z' => 1],
            'accelerometer' => ['x' => 0.1, 'y' => 0.2, 'z' => 0.3],
            'accuracy_level' => 4.5,
            'focalLength' => 4.2,
            'focalLength35' => 26.0,
            'pitch' => 1.1,
            'roll' => 2.2,
            'captureTime' => '2026-07-01 12:00:00',
            'orientation' => 1,
            'deviceMake' => 'Apple',
            'deviceModel' => 'iPhone',
            'imageSize' => '4032x3024',
            'fov' => 67.5,
            'vfov' => 48.1,
            'sequenceUuid' => 'mobile-sequence-1',
            'filename' => 'IMG_0001.jpg',
            'yaw' => 10.5,
            'car_speed' => 12.8,
            'anomaly' => 0,
            'capture_address' => null,
        ], $overrides);
    }

    private function createTables(): void
    {
        Schema::create('default_users_users', function ($table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->string('display_name')->nullable();
            $table->boolean('activated')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_mapilio_imagery', function ($table): void {
            $table->id();
            $table->timestamp('created_at');
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->double('latitude')->nullable();
            $table->double('longitude')->nullable();
            $table->double('heading')->nullable();
            $table->double('altitude')->nullable();
            $table->string('orientation')->nullable();
            $table->timestamp('capture_time')->nullable();
            $table->string('filename')->nullable();
            $table->string('device_make')->nullable();
            $table->string('device_model')->nullable();
            $table->string('sequence_uuid')->nullable();
            $table->string('uploaded_hash')->nullable();
            $table->string('photo_uuid')->nullable();
            $table->string('organization_key')->nullable();
            $table->string('project_key')->nullable();
            $table->text('geom')->nullable();
            $table->string('resolution')->nullable();
            $table->double('fov')->nullable();
            $table->boolean('anomaly')->nullable();
            $table->double('roll')->nullable();
            $table->double('pitch')->nullable();
            $table->double('yaw')->nullable();
            $table->text('gyroscope')->nullable();
            $table->text('acceleration')->nullable();
            $table->text('velocity')->nullable();
            $table->double('car_speed')->nullable();
            $table->double('accuracy_level')->nullable();
            $table->double('gps_score')->nullable();
            $table->double('time_score')->nullable();
            $table->double('distance_score')->nullable();
            $table->unsignedBigInteger('nearest_point_id')->nullable();
            $table->double('nearest_distance_on_sequence')->nullable();
            $table->text('capture_address')->nullable();
            $table->double('vfov')->nullable();
            $table->double('focalLength')->nullable();
            $table->double('focalLength35')->nullable();
            $table->string('source')->nullable();
            $table->string('sourceUser')->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->unique(['latitude', 'longitude', 'capture_time'], 'imagery_capture_unique');
        });

        Schema::create('default_mapilio_sequence_detail', function ($table): void {
            $table->id();
            $table->timestamp('created_at');
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('sequence_uuid');
            $table->integer('count');
            $table->double('size');
            $table->boolean('anomaly')->nullable();
            $table->string('organization_key')->nullable();
            $table->string('project_key')->nullable();
            $table->text('last_status')->nullable();
            $table->string('status')->nullable();
            $table->text('message')->nullable();
            $table->string('start_address')->nullable();
            $table->string('group_key')->nullable();
            $table->string('device_type')->nullable();
            $table->integer('road_line_status')->nullable();
            $table->text('road_line_status_message')->nullable();
        });

        Schema::create('default_mapilio_road', function ($table): void {
            $table->id();
            $table->integer('sort_order')->nullable();
            $table->timestamp('created_at');
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->text('geom');
            $table->string('sequence_uuid');
            $table->boolean('anomaly')->nullable();
            $table->string('organization_key')->nullable();
            $table->string('project_key')->nullable();
            $table->string('capture_time')->nullable();
        });
    }

    private function seedUsers(): void
    {
        Schema::getConnection()->table('default_users_users')->insert([
            [
                'id' => 10,
                'email' => 'alice@example.test',
                'username' => 'alice',
                'password' => Hash::make('correct-password'),
                'display_name' => 'Alice Example',
                'activated' => true,
                'enabled' => true,
                'deleted_at' => null,
            ],
        ]);
    }
}

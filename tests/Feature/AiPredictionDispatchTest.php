<?php

namespace Tests\Feature;

use App\Domain\AiJobsPredictions\Actions\DispatchSequencePrediction;
use App\Domain\AiJobsPredictions\Actions\PredictionDispatchException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiPredictionDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.ai_prediction.enabled', true);
        Config::set('mapilio.ai_prediction.endpoint', 'https://ai.example.test/prediction');
        Config::set('mapilio.ai_prediction.config_url', 'https://config.example.test/default.json');
        Config::set('mapilio.ai_prediction.token', 'ai-test-token');

        $this->createTables();
        $this->seedSequence();
    }

    public function test_prediction_dispatch_preserves_payload_and_records_pending_process(): void
    {
        Http::fake([
            'https://ai.example.test/prediction' => Http::response(['id' => 'prediction-response-1'], 202),
        ]);

        $result = app(DispatchSequencePrediction::class)->dispatch('sequence-ai-1');

        $this->assertSame([
            'dispatched' => true,
            'status' => 'pending',
            'response_id' => 'prediction-response-1',
        ], $result);

        Http::assertSent(function (Request $request): bool {
            $entries = $request['params'][0];

            return $request->url() === 'https://ai.example.test/prediction'
                && $request->hasHeader('Authorization', 'Bearer ai-test-token')
                && $request->hasHeader('Idempotency-Key', 'prediction:sequence-ai-1')
                && $request['sequence_uuid'] === 'sequence-ai-1'
                && $request['config_url'] === 'https://config.example.test/project.json'
                && $request['callback'] === false
                && count($entries) === 2
                && $entries[0]['key'] === 1
                && $entries[0]['hash'] === 'opaque-upload-hash'
                && $entries[0]['coordx'] === 29.025
                && $entries[0]['coordy'] === 40.991
                && $entries[0]['altitude'] === 0.0;
        });

        $this->assertDatabaseHas('default_mapilio_processing', [
            'sequence_uuid' => 'sequence-ai-1',
            'response_id' => 'prediction-response-1',
            'process_status' => 'pending',
            'created_by_id' => 10,
            'organization_key' => 'org-main',
            'project_key' => 'project-main',
        ]);

        $this->assertDatabaseHas('default_mapilio_sequence_detail', [
            'sequence_uuid' => 'sequence-ai-1',
            'last_status' => 'processing',
            'processing_status' => 2,
            'processing_status_message' => null,
        ]);
    }

    public function test_prediction_dispatch_reuses_active_process_without_duplicate_request(): void
    {
        Http::fake([
            'https://ai.example.test/prediction' => Http::response(['id' => 'prediction-response-1'], 202),
        ]);

        $first = app(DispatchSequencePrediction::class)->dispatch('sequence-ai-1');
        $second = app(DispatchSequencePrediction::class)->dispatch('sequence-ai-1');

        $this->assertTrue($first['dispatched']);
        $this->assertFalse($second['dispatched']);
        $this->assertSame('prediction-response-1', $second['response_id']);
        $this->assertSame(1, Schema::getConnection()->table('default_mapilio_processing')->count());
        Http::assertSentCount(1);
    }

    public function test_disabled_prediction_dispatch_has_no_side_effects(): void
    {
        Config::set('mapilio.ai_prediction.enabled', false);
        Http::fake();

        $result = app(DispatchSequencePrediction::class)->dispatch('sequence-ai-1');

        $this->assertSame([
            'dispatched' => false,
            'status' => 'disabled',
            'response_id' => null,
        ], $result);
        $this->assertSame(0, Schema::getConnection()->table('default_mapilio_processing')->count());
        Http::assertNothingSent();
    }

    public function test_stale_dispatch_reservation_is_expired_before_retry(): void
    {
        Config::set('mapilio.ai_prediction.reservation_ttl', 60);
        Schema::getConnection()->table('default_mapilio_processing')->insert([
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
            'created_by_id' => 10,
            'deleted_at' => null,
            'organization_key' => 'org-main',
            'project_key' => 'project-main',
            'sequence_uuid' => 'sequence-ai-1',
            'process_status' => 'dispatching',
            'response_id' => 'dispatch:stale',
        ]);
        Http::fake([
            'https://ai.example.test/prediction' => Http::response(['id' => 'prediction-after-stale'], 202),
        ]);

        $result = app(DispatchSequencePrediction::class)->dispatch('sequence-ai-1');

        $this->assertTrue($result['dispatched']);
        $this->assertSame('prediction-after-stale', $result['response_id']);
        $this->assertDatabaseHas('default_mapilio_processing', [
            'response_id' => 'dispatch:stale',
            'process_status' => 'ERROR',
        ]);
        $this->assertDatabaseHas('default_mapilio_processing', [
            'response_id' => 'prediction-after-stale',
            'process_status' => 'pending',
        ]);
        Http::assertSentCount(1);
    }

    public function test_failed_prediction_dispatch_records_safe_error_state(): void
    {
        Http::fake([
            'https://ai.example.test/prediction' => Http::response(['internal' => 'sensitive-response-body'], 503),
        ]);

        try {
            app(DispatchSequencePrediction::class)->dispatch('sequence-ai-1');
            $this->fail('Prediction dispatch should have failed.');
        } catch (PredictionDispatchException $exception) {
            $this->assertSame('AI prediction request failed with HTTP 503.', $exception->getMessage());
            $this->assertStringNotContainsString('sensitive-response-body', $exception->getMessage());
        }

        $this->assertDatabaseHas('default_mapilio_processing', [
            'sequence_uuid' => 'sequence-ai-1',
            'process_status' => 'ERROR',
        ]);

        $this->assertDatabaseHas('default_mapilio_sequence_detail', [
            'sequence_uuid' => 'sequence-ai-1',
            'last_status' => 'uploaded',
            'processing_status' => 1,
            'processing_status_message' => 'AI prediction request failed with HTTP 503.',
        ]);
    }

    private function createTables(): void
    {
        Schema::create('default_mapilio_sequence_detail', function ($table): void {
            $table->id();
            $table->timestamps();
            $table->integer('created_by_id')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('sequence_uuid');
            $table->string('organization_key')->nullable();
            $table->string('project_key')->nullable();
            $table->string('last_status')->nullable();
            $table->integer('processing_status')->nullable();
            $table->text('processing_status_message')->nullable();
        });

        Schema::create('default_mapilio_imagery', function ($table): void {
            $table->id();
            $table->timestamp('deleted_at')->nullable();
            $table->string('sequence_uuid');
            $table->boolean('anomaly')->default(false);
            $table->double('roll')->nullable();
            $table->double('yaw')->nullable();
            $table->double('pitch')->nullable();
            $table->string('filename');
            $table->string('uploaded_hash');
            $table->double('fov')->nullable();
            $table->double('vfov')->nullable();
            $table->double('heading')->nullable();
            $table->double('longitude')->nullable();
            $table->double('latitude')->nullable();
            $table->double('altitude')->nullable();
        });

        Schema::create('default_mapilio_processing', function ($table): void {
            $table->id();
            $table->timestamps();
            $table->integer('created_by_id')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('organization_key')->nullable();
            $table->string('project_key')->nullable();
            $table->string('sequence_uuid');
            $table->string('process_status');
            $table->string('response_id');
        });

        Schema::create('default_projects_projects', function ($table): void {
            $table->id();
            $table->timestamp('deleted_at')->nullable();
            $table->string('project_key');
            $table->string('config_url')->nullable();
        });
    }

    private function seedSequence(): void
    {
        Schema::getConnection()->table('default_mapilio_sequence_detail')->insert([
            'id' => 1,
            'created_at' => '2026-07-14 12:00:00',
            'updated_at' => '2026-07-14 12:00:00',
            'created_by_id' => 10,
            'deleted_at' => null,
            'sequence_uuid' => 'sequence-ai-1',
            'organization_key' => 'org-main',
            'project_key' => 'project-main',
            'last_status' => 'uploaded',
        ]);

        Schema::getConnection()->table('default_projects_projects')->insert([
            'project_key' => 'project-main',
            'config_url' => 'https://config.example.test/project.json',
            'deleted_at' => null,
        ]);

        Schema::getConnection()->table('default_mapilio_imagery')->insert([
            [
                'id' => 1,
                'deleted_at' => null,
                'sequence_uuid' => 'sequence-ai-1',
                'anomaly' => false,
                'roll' => 1.1,
                'yaw' => 2.2,
                'pitch' => 3.3,
                'filename' => 'image-1.jpg',
                'uploaded_hash' => 'opaque-upload-hash',
                'fov' => 67.5,
                'vfov' => 48.1,
                'heading' => 180,
                'longitude' => 29.025,
                'latitude' => 40.991,
                'altitude' => -3,
            ],
            [
                'id' => 2,
                'deleted_at' => null,
                'sequence_uuid' => 'sequence-ai-1',
                'anomaly' => false,
                'roll' => 1.2,
                'yaw' => 2.3,
                'pitch' => 3.4,
                'filename' => 'image-2.jpg',
                'uploaded_hash' => 'opaque-upload-hash',
                'fov' => 67.5,
                'vfov' => 48.1,
                'heading' => 181,
                'longitude' => 29.026,
                'latitude' => 40.992,
                'altitude' => 12,
            ],
            [
                'id' => 3,
                'deleted_at' => null,
                'sequence_uuid' => 'sequence-ai-1',
                'anomaly' => true,
                'roll' => 1.3,
                'yaw' => 2.4,
                'pitch' => 3.5,
                'filename' => 'anomaly.jpg',
                'uploaded_hash' => 'opaque-upload-hash',
                'fov' => 67.5,
                'vfov' => 48.1,
                'heading' => 182,
                'longitude' => 29.027,
                'latitude' => 40.993,
                'altitude' => 13,
            ],
        ]);
    }
}

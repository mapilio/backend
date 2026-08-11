<?php

namespace Tests\Feature;

use App\Domain\AiJobsPredictions\Actions\PersistPredictionResult;
use App\Domain\AiJobsPredictions\Actions\PredictionStatusProjectionException;
use App\Domain\AiJobsPredictions\Actions\ProjectPredictionProcessingStatus;
use App\Domain\GeoPublishing\Actions\GeoPublicationException;
use App\Domain\GeoPublishing\Actions\RegisterAiDetectionPublication;
use App\Jobs\PersistPredictionResult as PersistPredictionResultJob;
use App\Jobs\ProjectPredictionProcessingStatus as ProjectPredictionProcessingStatusJob;
use App\Jobs\RegisterAiDetectionPublication as RegisterAiDetectionPublicationJob;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiPredictionProjectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.ai_status_projection.enabled', true);
        Config::set('mapilio.geo_publication.registration_enabled', true);
        Config::set('mapilio.geo_publication.target', 'canonical_ai_detections');
        Config::set('mapilio.geo_publication.layer', null);

        $this->createTables();
        $this->seedLegacyOwnership();
    }

    public function test_success_status_is_projected_to_legacy_processing_and_sequence(): void
    {
        $receiptId = $this->seedReceipt('SUCCESS');

        $this->assertTrue(app(ProjectPredictionProcessingStatus::class)->project($receiptId));

        $this->assertDatabaseHas('default_mapilio_processing', [
            'response_id' => 'prediction-result-1',
            'process_status' => 'SUCCESS',
        ]);
        $this->assertDatabaseHas('default_mapilio_sequence_detail', [
            'sequence_uuid' => 'sequence-ai-1',
            'last_status' => 'completed',
            'processing_status' => 3,
            'processing_status_message' => null,
        ]);
        $this->assertDatabaseHas('ai_prediction_status_projections', [
            'callback_receipt_id' => $receiptId,
            'projection_status' => 'projected',
            'attempts' => 1,
            'last_error' => null,
        ]);
    }

    public function test_error_status_uses_safe_legacy_error_state(): void
    {
        $receiptId = $this->seedReceipt('ERROR');

        $this->assertTrue(app(ProjectPredictionProcessingStatus::class)->project($receiptId));

        $this->assertDatabaseHas('default_mapilio_processing', [
            'response_id' => 'prediction-result-1',
            'process_status' => 'ERROR',
        ]);
        $this->assertDatabaseHas('default_mapilio_sequence_detail', [
            'sequence_uuid' => 'sequence-ai-1',
            'last_status' => 'uploaded',
            'processing_status' => 1,
            'processing_status_message' => 'AI prediction processing failed.',
        ]);
    }

    public function test_projected_status_is_idempotent(): void
    {
        $receiptId = $this->seedReceipt('SUCCESS');

        $this->assertTrue(app(ProjectPredictionProcessingStatus::class)->project($receiptId));
        $this->assertFalse(app(ProjectPredictionProcessingStatus::class)->project($receiptId));
        $this->assertSame(1, Schema::getConnection()->table('ai_prediction_status_projections')->count());
        $this->assertSame(1, Schema::getConnection()->table('ai_prediction_status_projections')->value('attempts'));
    }

    public function test_unprocessed_receipt_cannot_change_legacy_status(): void
    {
        $receiptId = $this->seedReceipt('SUCCESS', 'validated');

        $this->expectException(PredictionStatusProjectionException::class);
        $this->expectExceptionMessage("Callback receipt {$receiptId} has not been processed.");

        app(ProjectPredictionProcessingStatus::class)->project($receiptId);
    }

    public function test_disabled_status_projection_has_no_side_effects(): void
    {
        Config::set('mapilio.ai_status_projection.enabled', false);
        $receiptId = $this->seedReceipt('SUCCESS');

        $this->assertFalse(app(ProjectPredictionProcessingStatus::class)->project($receiptId));
        $this->assertSame(0, Schema::getConnection()->table('ai_prediction_status_projections')->count());
        $this->assertDatabaseHas('default_mapilio_processing', [
            'response_id' => 'prediction-result-1',
            'process_status' => 'pending',
        ]);
    }

    public function test_success_result_registers_a_blocked_geo_publication_outbox_entry(): void
    {
        $receiptId = $this->seedReceipt('SUCCESS', featureCount: 2);
        $this->seedFeatures($receiptId, 2);

        $this->assertTrue(app(RegisterAiDetectionPublication::class)->register($receiptId));

        $this->assertDatabaseHas('geospatial_publications', [
            'callback_receipt_id' => $receiptId,
            'sequence_uuid' => 'sequence-ai-1',
            'source_type' => 'ai_prediction_receipt',
            'source_id' => $receiptId,
            'target' => 'canonical_ai_detections',
            'target_layer' => null,
            'feature_count' => 2,
            'publication_status' => 'blocked',
            'status_reason' => 'Database projection has not been reconciled.',
        ]);
    }

    public function test_missing_receipt_cannot_register_geo_publication(): void
    {
        $this->expectException(GeoPublicationException::class);
        $this->expectExceptionMessage('Callback receipt 999 was not found.');

        app(RegisterAiDetectionPublication::class)->register(999);
    }

    public function test_unprocessed_receipt_cannot_register_geo_publication(): void
    {
        $receiptId = $this->seedReceipt('SUCCESS', 'validated');

        $this->expectException(GeoPublicationException::class);
        $this->expectExceptionMessage("Callback receipt {$receiptId} has not been processed.");

        app(RegisterAiDetectionPublication::class)->register($receiptId);
    }

    public function test_geo_publication_registration_is_idempotent(): void
    {
        $receiptId = $this->seedReceipt('SUCCESS', featureCount: 1);
        $this->seedFeatures($receiptId, 1);

        $this->assertTrue(app(RegisterAiDetectionPublication::class)->register($receiptId));
        $this->assertFalse(app(RegisterAiDetectionPublication::class)->register($receiptId));
        $this->assertSame(1, Schema::getConnection()->table('geospatial_publications')->count());
    }

    public function test_error_result_does_not_register_geo_publication(): void
    {
        $receiptId = $this->seedReceipt('ERROR');

        $this->assertFalse(app(RegisterAiDetectionPublication::class)->register($receiptId));
        $this->assertSame(0, Schema::getConnection()->table('geospatial_publications')->count());
    }

    public function test_disabled_geo_registration_has_no_side_effects(): void
    {
        Config::set('mapilio.geo_publication.registration_enabled', false);
        $receiptId = $this->seedReceipt('SUCCESS');

        $this->assertFalse(app(RegisterAiDetectionPublication::class)->register($receiptId));
        $this->assertSame(0, Schema::getConnection()->table('geospatial_publications')->count());
    }

    public function test_missing_canonical_features_fail_closed(): void
    {
        $receiptId = $this->seedReceipt('SUCCESS', featureCount: 1);

        $this->expectException(GeoPublicationException::class);
        $this->expectExceptionMessage('Canonical detection features do not match their processed AI receipt.');

        app(RegisterAiDetectionPublication::class)->register($receiptId);
    }

    public function test_zero_feature_result_uses_unique_legacy_sequence_ownership(): void
    {
        $receiptId = $this->seedReceipt('SUCCESS');

        $this->assertTrue(app(RegisterAiDetectionPublication::class)->register($receiptId));
        $this->assertDatabaseHas('geospatial_publications', [
            'callback_receipt_id' => $receiptId,
            'sequence_uuid' => 'sequence-ai-1',
            'feature_count' => 0,
        ]);
    }

    public function test_ambiguous_legacy_sequence_ownership_fails_closed(): void
    {
        $receiptId = $this->seedReceipt('SUCCESS');
        Schema::getConnection()->table('default_mapilio_processing')->insert([
            'response_id' => 'prediction-result-1',
            'sequence_uuid' => 'sequence-ai-2',
            'process_status' => 'pending',
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(GeoPublicationException::class);
        $this->expectExceptionMessage('AI publication sequence ownership could not be resolved.');

        app(RegisterAiDetectionPublication::class)->register($receiptId);
    }

    public function test_result_job_dispatches_enabled_follow_up_jobs_to_dedicated_queues(): void
    {
        Queue::fake();
        Config::set('mapilio.ai_status_projection.queue', 'status-test');
        Config::set('mapilio.geo_publication.queue', 'geo-test');
        $action = new class(false) extends PersistPredictionResult
        {
            /** @var list<int> */
            public array $receiptIds = [];

            public function __construct(private readonly bool $result) {}

            public function persist(int $receiptId): bool
            {
                $this->receiptIds[] = $receiptId;

                return $this->result;
            }
        };

        (new PersistPredictionResultJob(41))->handle($action);

        $this->assertSame([41], $action->receiptIds);
        Queue::assertPushedOn('status-test', ProjectPredictionProcessingStatusJob::class);
        Queue::assertPushedOn('geo-test', RegisterAiDetectionPublicationJob::class);
    }

    public function test_result_job_does_not_dispatch_disabled_follow_up_jobs(): void
    {
        Queue::fake();
        Config::set('mapilio.ai_status_projection.enabled', false);
        Config::set('mapilio.geo_publication.registration_enabled', false);
        $action = new class(true) extends PersistPredictionResult
        {
            /** @var list<int> */
            public array $receiptIds = [];

            public function __construct(private readonly bool $result) {}

            public function persist(int $receiptId): bool
            {
                $this->receiptIds[] = $receiptId;

                return $this->result;
            }
        };

        (new PersistPredictionResultJob(42))->handle($action);

        $this->assertSame([42], $action->receiptIds);
        Queue::assertNotPushed(ProjectPredictionProcessingStatusJob::class);
        Queue::assertNotPushed(RegisterAiDetectionPublicationJob::class);
    }

    private function seedReceipt(string $status, string $processingStatus = 'processed', int $featureCount = 0): int
    {
        return Schema::getConnection()->table('ai_prediction_callback_receipts')->insertGetId([
            'response_id' => 'prediction-result-1',
            'response_status' => $status,
            'result_feature_count' => $featureCount,
            'processing_status' => $processingStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedFeatures(int $receiptId, int $count): void
    {
        foreach (range(1, $count) as $index) {
            Schema::getConnection()->table('ai_detection_features')->insert([
                'callback_receipt_id' => $receiptId,
                'sequence_uuid' => 'sequence-ai-1',
                'source_index' => $index - 1,
            ]);
        }
    }

    private function seedLegacyOwnership(): void
    {
        Schema::getConnection()->table('default_mapilio_processing')->insert([
            'response_id' => 'prediction-result-1',
            'sequence_uuid' => 'sequence-ai-1',
            'process_status' => 'pending',
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Schema::getConnection()->table('default_mapilio_sequence_detail')->insert([
            'sequence_uuid' => 'sequence-ai-1',
            'last_status' => 'processing',
            'processing_status' => 2,
            'processing_status_message' => null,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTables(): void
    {
        Schema::create('ai_prediction_callback_receipts', function ($table): void {
            $table->id();
            $table->string('response_id');
            $table->string('response_status');
            $table->unsignedInteger('result_feature_count')->default(0);
            $table->string('processing_status');
            $table->timestamps();
        });
        Schema::create('ai_detection_features', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('callback_receipt_id');
            $table->string('sequence_uuid');
            $table->unsignedInteger('source_index');
        });
        Schema::create('ai_prediction_status_projections', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('callback_receipt_id')->unique();
            $table->string('response_id');
            $table->string('sequence_uuid');
            $table->string('response_status');
            $table->string('projection_status');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('projected_at')->nullable();
            $table->timestamps();
        });
        Schema::create('geospatial_publications', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('callback_receipt_id')->unique();
            $table->string('sequence_uuid');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('target');
            $table->string('target_layer')->nullable();
            $table->unsignedInteger('feature_count');
            $table->string('publication_status');
            $table->text('status_reason')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'target']);
        });
        Schema::create('default_mapilio_processing', function ($table): void {
            $table->id();
            $table->string('response_id');
            $table->string('sequence_uuid');
            $table->string('process_status');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('default_mapilio_sequence_detail', function ($table): void {
            $table->id();
            $table->string('sequence_uuid');
            $table->string('last_status')->nullable();
            $table->integer('processing_status')->nullable();
            $table->text('processing_status_message')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }
}

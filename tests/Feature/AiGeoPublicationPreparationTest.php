<?php

namespace Tests\Feature;

use App\Domain\GeoPublishing\Actions\GeoPublicationException;
use App\Domain\GeoPublishing\Actions\PrepareAiDetectionPublication;
use App\Domain\GeoPublishing\Actions\RegisterAiDetectionPublication as RegisterAiDetectionPublicationAction;
use App\Jobs\PrepareAiDetectionPublication as PrepareAiDetectionPublicationJob;
use App\Jobs\RegisterAiDetectionPublication as RegisterAiDetectionPublicationJob;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiGeoPublicationPreparationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.geo_publication.preparation_enabled', true);
        Config::set('mapilio.geo_publication.preparation_queue', 'geo-preparation-test');
        Config::set('mapilio.geo_publication.view', 'mapilio_ai_features_v1');
        Config::set('mapilio.geo_publication.layer', 'mapilio:ai_features_v1');

        $this->createTables();
        $this->createView();
    }

    public function test_canonical_features_are_reconciled_and_marked_database_ready(): void
    {
        [$receiptId, $publicationId] = $this->seedPublication(2);
        $this->seedFeatures($receiptId, 2);

        $this->assertTrue(app(PrepareAiDetectionPublication::class)->prepare($publicationId));

        $this->assertDatabaseHas('geospatial_publications', [
            'id' => $publicationId,
            'target_layer' => 'mapilio:ai_features_v1',
            'publication_status' => 'ready',
            'status_reason' => 'Database projection reconciled; GeoServer layer activation is pending.',
            'attempts' => 1,
        ]);
        $this->assertDatabaseHas('geospatial_publication_checks', [
            'geospatial_publication_id' => $publicationId,
            'check_status' => 'passed',
            'expected_feature_count' => 2,
            'actual_feature_count' => 2,
            'missing_view_feature_count' => 0,
            'invalid_geometry_count' => 0,
            'error' => null,
        ]);
        $this->assertNotNull(DB::table('geospatial_publications')->where('id', $publicationId)->value('prepared_at'));
        $this->assertNotNull(DB::table('geospatial_publications')->where('id', $publicationId)->value('reconciled_at'));
    }

    public function test_ready_publication_is_idempotent(): void
    {
        [$receiptId, $publicationId] = $this->seedPublication(1);
        $this->seedFeatures($receiptId, 1);

        $this->assertTrue(app(PrepareAiDetectionPublication::class)->prepare($publicationId));
        $this->assertFalse(app(PrepareAiDetectionPublication::class)->prepare($publicationId));
        $this->assertSame(1, DB::table('geospatial_publication_checks')->count());
        $this->assertSame(1, DB::table('geospatial_publications')->value('attempts'));
    }

    public function test_feature_count_mismatch_fails_closed_and_records_check(): void
    {
        [$receiptId, $publicationId] = $this->seedPublication(2);
        $this->seedFeatures($receiptId, 1);

        try {
            app(PrepareAiDetectionPublication::class)->prepare($publicationId);
            $this->fail('Geo publication preparation should have failed.');
        } catch (GeoPublicationException $exception) {
            $this->assertSame('Geo publication feature counts do not reconcile.', $exception->getMessage());
        }

        $this->assertDatabaseHas('geospatial_publications', [
            'id' => $publicationId,
            'publication_status' => 'error',
            'status_reason' => 'Geo publication feature counts do not reconcile.',
            'attempts' => 1,
        ]);
        $this->assertDatabaseHas('geospatial_publication_checks', [
            'geospatial_publication_id' => $publicationId,
            'check_status' => 'failed',
            'expected_feature_count' => 2,
            'actual_feature_count' => 1,
        ]);
    }

    public function test_missing_view_geometry_fails_closed(): void
    {
        [$receiptId, $publicationId] = $this->seedPublication(2);
        $this->seedFeatures($receiptId, 2);
        DB::statement('DROP VIEW mapilio_ai_features_v1');
        DB::statement(<<<'SQL'
            CREATE VIEW mapilio_ai_features_v1 AS
            SELECT id, geometry AS geom
            FROM ai_detection_features
            WHERE source_index = 0
            SQL);

        try {
            app(PrepareAiDetectionPublication::class)->prepare($publicationId);
            $this->fail('Geo publication preparation should have failed.');
        } catch (GeoPublicationException $exception) {
            $this->assertSame(
                'Geo publication view is missing canonical features or geometry.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('geospatial_publication_checks', [
            'geospatial_publication_id' => $publicationId,
            'check_status' => 'failed',
            'missing_view_feature_count' => 1,
        ]);
    }

    public function test_invalid_layer_configuration_is_recorded_without_dynamic_sql(): void
    {
        [$receiptId, $publicationId] = $this->seedPublication(1);
        $this->seedFeatures($receiptId, 1);
        Config::set('mapilio.geo_publication.layer', 'mapilio:features;drop table users');

        $this->expectException(GeoPublicationException::class);
        $this->expectExceptionMessage('Geo publication layer configuration is invalid.');

        try {
            app(PrepareAiDetectionPublication::class)->prepare($publicationId);
        } finally {
            $this->assertDatabaseHas('geospatial_publications', [
                'id' => $publicationId,
                'publication_status' => 'error',
                'status_reason' => 'Geo publication layer configuration is invalid.',
            ]);
        }
    }

    public function test_invalid_view_configuration_is_rejected_before_dynamic_sql(): void
    {
        [, $publicationId] = $this->seedPublication(0);
        Config::set('mapilio.geo_publication.view', 'mapilio_ai_features_v1;drop_table');

        try {
            app(PrepareAiDetectionPublication::class)->prepare($publicationId);
            $this->fail('Geo publication preparation should have failed.');
        } catch (GeoPublicationException $exception) {
            $this->assertSame('Geo publication view configuration is invalid.', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('ai_detection_features'));
        $this->assertDatabaseHas('geospatial_publications', [
            'id' => $publicationId,
            'publication_status' => 'error',
            'status_reason' => 'Geo publication view configuration is invalid.',
        ]);
    }

    public function test_sequence_ownership_mismatch_fails_closed(): void
    {
        [$receiptId, $publicationId] = $this->seedPublication(1);
        $this->seedFeatures($receiptId, 1);
        DB::table('ai_detection_features')->update(['sequence_uuid' => 'sequence-other']);

        try {
            app(PrepareAiDetectionPublication::class)->prepare($publicationId);
            $this->fail('Geo publication preparation should have failed.');
        } catch (GeoPublicationException $exception) {
            $this->assertSame(
                'Geo publication contains invalid sequence ownership or coordinates.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('geospatial_publications', [
            'id' => $publicationId,
            'publication_status' => 'error',
        ]);
        $this->assertDatabaseHas('geospatial_publication_checks', [
            'geospatial_publication_id' => $publicationId,
            'check_status' => 'failed',
            'expected_feature_count' => 1,
            'actual_feature_count' => 1,
        ]);
    }

    public function test_disabled_preparation_has_no_side_effects(): void
    {
        Config::set('mapilio.geo_publication.preparation_enabled', false);
        [, $publicationId] = $this->seedPublication(0);

        $this->assertFalse(app(PrepareAiDetectionPublication::class)->prepare($publicationId));
        $this->assertSame(0, DB::table('geospatial_publication_checks')->count());
        $this->assertDatabaseHas('geospatial_publications', [
            'id' => $publicationId,
            'publication_status' => 'blocked',
            'attempts' => 0,
        ]);
    }

    public function test_registration_job_queues_preparation_for_existing_outbox(): void
    {
        Queue::fake();
        [$receiptId, $publicationId] = $this->seedPublication(0);
        $registration = $this->mock(RegisterAiDetectionPublicationAction::class);
        $registration->expects('register')->once()->with($receiptId)->andReturn(false);

        (new RegisterAiDetectionPublicationJob($receiptId))->handle($registration);

        Queue::assertPushedOn('geo-preparation-test', PrepareAiDetectionPublicationJob::class);
        Queue::assertPushed(PrepareAiDetectionPublicationJob::class, function ($job) use ($publicationId): bool {
            return $job->publicationId === $publicationId;
        });
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function seedPublication(int $featureCount): array
    {
        $receiptId = DB::table('ai_prediction_callback_receipts')->insertGetId([
            'response_id' => 'prediction-geo-1',
            'response_status' => 'SUCCESS',
            'result_feature_count' => $featureCount,
            'processing_status' => 'processed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $publicationId = DB::table('geospatial_publications')->insertGetId([
            'callback_receipt_id' => $receiptId,
            'sequence_uuid' => 'sequence-geo-1',
            'source_type' => 'ai_prediction_receipt',
            'source_id' => $receiptId,
            'target' => 'canonical_ai_detections',
            'target_layer' => null,
            'feature_count' => $featureCount,
            'publication_status' => 'blocked',
            'status_reason' => 'GeoServer catalog and canonical PostGIS projection are not configured.',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$receiptId, $publicationId];
    }

    private function seedFeatures(int $receiptId, int $count): void
    {
        foreach (range(1, $count) as $index) {
            DB::table('ai_detection_features')->insert([
                'callback_receipt_id' => $receiptId,
                'sequence_uuid' => 'sequence-geo-1',
                'source_index' => $index - 1,
                'longitude' => 29.025 + ($index / 1000),
                'latitude' => 40.991 + ($index / 1000),
                'geometry' => json_encode([
                    'type' => 'Point',
                    'coordinates' => [29.025 + ($index / 1000), 40.991 + ($index / 1000)],
                ], JSON_THROW_ON_ERROR),
                'class_code' => 'stop-sign',
                'confidence' => 0.9,
                'width' => 1,
                'height' => 1,
                'area' => 1,
                'verified' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function createView(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIEW mapilio_ai_features_v1 AS
            SELECT
                id,
                geometry AS geom,
                class_code,
                sequence_uuid,
                confidence,
                width,
                height,
                area,
                verified,
                created_at,
                updated_at
            FROM ai_detection_features
            SQL);
    }

    private function createTables(): void
    {
        Schema::create('ai_prediction_callback_receipts', function ($table): void {
            $table->id();
            $table->string('response_id');
            $table->string('response_status');
            $table->unsignedInteger('result_feature_count');
            $table->string('processing_status');
            $table->timestamps();
        });
        Schema::create('ai_detection_features', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('callback_receipt_id');
            $table->string('sequence_uuid');
            $table->unsignedInteger('source_index');
            $table->double('longitude');
            $table->double('latitude');
            $table->text('geometry')->nullable();
            $table->string('class_code');
            $table->double('confidence');
            $table->double('width');
            $table->double('height');
            $table->double('area');
            $table->boolean('verified');
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
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('geospatial_publication_checks', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('geospatial_publication_id');
            $table->string('check_status');
            $table->unsignedInteger('expected_feature_count');
            $table->unsignedInteger('actual_feature_count');
            $table->unsignedInteger('missing_view_feature_count');
            $table->unsignedInteger('invalid_geometry_count');
            $table->text('error')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
        });
    }
}

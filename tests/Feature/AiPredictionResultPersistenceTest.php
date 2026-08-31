<?php

namespace Tests\Feature;

use App\Domain\AiJobsPredictions\Actions\PersistPredictionResult;
use App\Domain\AiJobsPredictions\Actions\PredictionResultPersistenceException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AiPredictionResultPersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
        Config::set('mapilio.ai_result_persistence.enabled', true);
        Config::set('mapilio.ai_result_persistence.max_matches_per_feature', 1000);
        Config::set('mapilio.ai_result_persistence.max_attributes_bytes', 131072);
        Config::set('mapilio.ai_result_persistence.max_segmentation_bytes', 1048576);

        $this->createTables();
        $this->seedOwnershipData();
    }

    public function test_validated_receipt_is_persisted_into_canonical_detection_graph(): void
    {
        $receiptId = $this->seedReceipt($this->payload());

        $persisted = app(PersistPredictionResult::class)->persist($receiptId);

        $this->assertTrue($persisted);
        $this->assertDatabaseHas('ai_detection_features', [
            'callback_receipt_id' => $receiptId,
            'response_id' => 'prediction-result-1',
            'sequence_uuid' => 'sequence-ai-1',
            'created_by_id' => 10,
            'organization_key' => 'org-main',
            'project_key' => 'project-main',
            'source_index' => 0,
            'class_code' => 'stop-sign',
            'confidence' => 0.91,
            'longitude' => 29.0255,
            'latitude' => 40.9915,
            'width' => 0.8,
            'height' => 0.9,
            'area' => 0.72,
            'verified' => true,
        ]);
        $this->assertDatabaseHas('ai_detection_observations', [
            'response_id' => 'prediction-result-1',
            'sequence_uuid' => 'sequence-ai-1',
            'object_key' => 'object-left',
            'imagery_id' => 100,
            'x_min' => 10,
            'y_min' => 20,
            'x_max' => 110,
            'y_max' => 220,
            'score' => 0.92,
        ]);
        $this->assertDatabaseHas('ai_detection_observations', [
            'object_key' => 'object-right',
            'imagery_id' => 101,
            'score' => 0.88,
        ]);
        $this->assertSame(1, Schema::getConnection()->table('ai_detection_features')->count());
        $this->assertSame(2, Schema::getConnection()->table('ai_detection_observations')->count());
        $this->assertSame(1, Schema::getConnection()->table('ai_detection_matches')->count());
        $this->assertDatabaseHas('ai_prediction_callback_receipts', [
            'id' => $receiptId,
            'processing_status' => 'processed',
            'processing_error' => null,
        ]);
    }

    public function test_processed_receipt_is_idempotent(): void
    {
        $receiptId = $this->seedReceipt($this->payload());

        $this->assertTrue(app(PersistPredictionResult::class)->persist($receiptId));
        $this->assertFalse(app(PersistPredictionResult::class)->persist($receiptId));

        $this->assertSame(1, Schema::getConnection()->table('ai_detection_features')->count());
        $this->assertSame(2, Schema::getConnection()->table('ai_detection_observations')->count());
        $this->assertSame(1, Schema::getConnection()->table('ai_detection_matches')->count());
    }

    public function test_disabled_result_persistence_has_no_side_effects(): void
    {
        Config::set('mapilio.ai_result_persistence.enabled', false);
        $receiptId = $this->seedReceipt($this->payload());

        $this->assertFalse(app(PersistPredictionResult::class)->persist($receiptId));
        $this->assertCanonicalTablesAreEmpty();
        $this->assertDatabaseHas('ai_prediction_callback_receipts', [
            'id' => $receiptId,
            'processing_status' => 'validated',
            'processed_at' => null,
        ]);
    }

    public function test_unknown_class_code_rejects_entire_result_before_writes(): void
    {
        $payload = $this->payload();
        data_set($payload, 'result.features.0.properties.class_code', 'unknown-class');
        $receiptId = $this->seedReceipt($payload);

        $this->assertPersistenceFails($receiptId, 'Invalid AI result: unknown class codes: unknown-class.');
        $this->assertCanonicalTablesAreEmpty();
    }

    public function test_imagery_from_another_sequence_is_rejected_before_writes(): void
    {
        $payload = $this->payload();
        data_set($payload, 'result.features.0.properties.matchedPoints.0.properties.panoId_2', 999);
        $receiptId = $this->seedReceipt($payload);

        $this->assertPersistenceFails($receiptId, 'Invalid AI result: imagery does not belong to the processing sequence.');
        $this->assertCanonicalTablesAreEmpty();
    }

    public function test_invalid_second_feature_rolls_back_without_partial_graph(): void
    {
        $payload = $this->payload();
        $second = $payload['result']['features'][0];
        $second['geometry']['coordinates'] = [400, 95];
        $payload['result']['features'][] = $second;
        $receiptId = $this->seedReceipt($payload);

        $this->assertPersistenceFails($receiptId, 'Invalid AI result: feature 1 longitude is out of range.');
        $this->assertCanonicalTablesAreEmpty();
    }

    public function test_response_id_must_belong_to_exactly_one_processing_request(): void
    {
        Schema::getConnection()->table('default_mapilio_processing')->insert([
            'response_id' => 'prediction-result-1',
            'sequence_uuid' => 'sequence-ai-2',
            'created_by_id' => 20,
            'organization_key' => null,
            'project_key' => null,
            'process_status' => 'pending',
            'deleted_at' => null,
        ]);
        $receiptId = $this->seedReceipt($this->payload());

        $this->assertPersistenceFails(
            $receiptId,
            'AI response id does not belong to exactly one processing request.',
        );
        $this->assertCanonicalTablesAreEmpty();
    }

    public function test_missing_receipt_fails_closed(): void
    {
        try {
            app(PersistPredictionResult::class)->persist(999);
            $this->fail('Prediction result persistence should have failed.');
        } catch (PredictionResultPersistenceException $exception) {
            $this->assertSame('Callback receipt 999 was not found.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_nonvalidated_receipt_fails_closed(): void
    {
        $receiptId = $this->seedReceipt($this->payload(), 'pending');

        $this->assertPersistenceFails($receiptId, "Callback receipt {$receiptId} is not validated.");
    }

    public function test_malformed_receipt_fails_closed(): void
    {
        $receiptId = $this->seedReceipt($this->payload());
        Schema::getConnection()->table('ai_prediction_callback_receipts')
            ->where('id', $receiptId)
            ->update(['response_id' => ' ']);

        $this->assertPersistenceFails(
            $receiptId,
            'Callback receipt has an invalid database representation.',
        );
    }

    public function test_success_feature_count_mismatch_has_no_canonical_writes(): void
    {
        $receiptId = $this->seedReceipt($this->payload());
        Schema::getConnection()->table('ai_prediction_callback_receipts')
            ->where('id', $receiptId)
            ->update(['result_feature_count' => 2]);

        $this->assertPersistenceFails(
            $receiptId,
            'AI result feature count does not match its receipt.',
        );
        $this->assertCanonicalTablesAreEmpty();
    }

    public function test_error_result_becomes_processed_without_a_canonical_graph(): void
    {
        $payload = $this->payload();
        $payload['status'] = 'ERROR';
        unset($payload['result']);
        $receiptId = $this->seedReceipt($payload);

        $this->assertTrue(app(PersistPredictionResult::class)->persist($receiptId));
        $this->assertCanonicalTablesAreEmpty();
        $this->assertDatabaseHas('ai_prediction_callback_receipts', [
            'id' => $receiptId,
            'processing_status' => 'processed',
            'processing_error' => null,
        ]);
    }

    public function test_database_write_failure_rolls_back_and_records_only_a_generic_error(): void
    {
        $receiptId = $this->seedReceipt($this->payload());
        $connection = Schema::getConnection();
        $originalDispatcher = $connection->getEventDispatcher();
        $dispatcher = $originalDispatcher === null
            ? new Dispatcher(app())
            : clone $originalDispatcher;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $query): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'insert') && str_contains($sql, 'ai_detection_matches')) {
                throw new RuntimeException('database failure details must not escape');
            }
        });
        $connection->setEventDispatcher($dispatcher);

        try {
            app(PersistPredictionResult::class)->persist($receiptId);
            $this->fail('Prediction result persistence should have failed.');
        } catch (PredictionResultPersistenceException $exception) {
            $this->assertSame('AI result persistence could not be completed.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }

        $this->assertCanonicalTablesAreEmpty();
        $this->assertDatabaseHas('ai_prediction_callback_receipts', [
            'id' => $receiptId,
            'processing_status' => 'error',
            'processing_error' => 'AI result persistence could not be completed.',
        ]);
    }

    private function assertPersistenceFails(int $receiptId, string $message): void
    {
        try {
            app(PersistPredictionResult::class)->persist($receiptId);
            $this->fail('Prediction result persistence should have failed.');
        } catch (PredictionResultPersistenceException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $this->assertDatabaseHas('ai_prediction_callback_receipts', [
            'id' => $receiptId,
            'processing_status' => 'error',
            'processing_error' => $message,
        ]);
    }

    private function assertCanonicalTablesAreEmpty(): void
    {
        $this->assertSame(0, Schema::getConnection()->table('ai_detection_features')->count());
        $this->assertSame(0, Schema::getConnection()->table('ai_detection_observations')->count());
        $this->assertSame(0, Schema::getConnection()->table('ai_detection_matches')->count());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function seedReceipt(array $payload, string $processingStatus = 'validated'): int
    {
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);

        return Schema::getConnection()->table('ai_prediction_callback_receipts')->insertGetId([
            'response_id' => $payload['id'],
            'response_status' => $payload['status'],
            'payload_hash' => hash('sha256', $rawBody),
            'fingerprint' => hash('sha256', $rawBody.'receipt'),
            'encrypted_payload' => Crypt::encryptString($rawBody),
            'result_feature_count' => count($payload['result']['features'] ?? []),
            'processing_status' => $processingStatus,
            'processing_error' => null,
            'received_at' => now(),
            'validated_at' => now(),
            'processed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'id' => 'prediction-result-1',
            'status' => 'SUCCESS',
            'result' => [
                'type' => 'FeatureCollection',
                'features' => [[
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [29.0255, 40.9915],
                    ],
                    'properties' => [
                        'class_code' => 'stop-sign',
                        'confidence' => 0.91,
                        'width' => 0.8,
                        'height' => 0.9,
                        'area' => 0.72,
                        'feature' => ['color' => 'red'],
                        'matchedPoints' => [[
                            'type' => 'Feature',
                            'geometry' => [
                                'type' => 'Point',
                                'coordinates' => [29.0256, 40.9916],
                            ],
                            'properties' => [
                                'objId_1' => 'object-left',
                                'bbox_1' => [10, 20, 110, 220],
                                'score_1' => 0.92,
                                'panoId_1' => 100,
                                'segmentation_1' => [[10, 20], [11, 21]],
                                'objId_2' => 'object-right',
                                'bbox_2' => [12, 22, 112, 222],
                                'score_2' => 0.88,
                                'panoId_2' => 101,
                                'segmentation_2' => [[12, 22], [13, 23]],
                            ],
                        ]],
                    ],
                ]],
            ],
        ];
    }

    private function createTables(): void
    {
        Schema::create('ai_prediction_callback_receipts', function ($table): void {
            $table->id();
            $table->string('response_id');
            $table->string('response_status', 32);
            $table->char('payload_hash', 64);
            $table->char('fingerprint', 64)->unique();
            $table->longText('encrypted_payload');
            $table->unsignedInteger('result_feature_count')->default(0);
            $table->string('processing_status', 48);
            $table->text('processing_error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

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
            $table->unique(['callback_receipt_id', 'source_index']);
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
            $table->unique(['response_id', 'object_key']);
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
            $table->unique(['detection_feature_id', 'source_index']);
        });

        Schema::create('default_mapilio_processing', function ($table): void {
            $table->id();
            $table->string('response_id');
            $table->string('sequence_uuid');
            $table->integer('created_by_id')->nullable();
            $table->string('organization_key')->nullable();
            $table->string('project_key')->nullable();
            $table->string('process_status');
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_types_type', function ($table): void {
            $table->id();
            $table->string('code');
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_mapilio_imagery', function ($table): void {
            $table->id();
            $table->string('sequence_uuid');
            $table->boolean('anomaly')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });
    }

    private function seedOwnershipData(): void
    {
        Schema::getConnection()->table('default_mapilio_processing')->insert([
            'response_id' => 'prediction-result-1',
            'sequence_uuid' => 'sequence-ai-1',
            'created_by_id' => 10,
            'organization_key' => 'org-main',
            'project_key' => 'project-main',
            'process_status' => 'pending',
            'deleted_at' => null,
        ]);

        Schema::getConnection()->table('default_types_type')->insert([
            'code' => 'stop-sign',
            'deleted_at' => null,
        ]);

        Schema::getConnection()->table('default_mapilio_imagery')->insert([
            ['id' => 100, 'sequence_uuid' => 'sequence-ai-1', 'anomaly' => false, 'deleted_at' => null],
            ['id' => 101, 'sequence_uuid' => 'sequence-ai-1', 'anomaly' => false, 'deleted_at' => null],
            ['id' => 999, 'sequence_uuid' => 'sequence-other', 'anomaly' => false, 'deleted_at' => null],
        ]);
    }
}

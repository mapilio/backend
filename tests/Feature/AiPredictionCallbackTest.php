<?php

namespace Tests\Feature;

use App\Domain\AiJobsPredictions\Actions\PredictionCallbackException;
use App\Domain\AiJobsPredictions\Actions\ValidatePredictionCallbackReceipt;
use App\Jobs\PersistPredictionResult as PersistPredictionResultJob;
use App\Jobs\ValidatePredictionCallbackReceipt as ValidatePredictionCallbackReceiptJob;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class AiPredictionCallbackTest extends TestCase
{
    private const SECRET = 'callback-signing-secret-at-least-32-bytes';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        Config::set('mapilio.ai_callback.enabled', true);
        Config::set('mapilio.ai_callback.signing_secret', self::SECRET);
        Config::set('mapilio.ai_callback.timestamp_tolerance', 300);
        Config::set('mapilio.ai_callback.nonce_retention', 86400);
        Config::set('mapilio.ai_callback.max_payload_bytes', 5242880);
        Config::set('mapilio.ai_callback.max_features', 100000);
        Config::set('mapilio.ai_callback.queue', 'ai-callbacks-test');
        Queue::fake();

        $this->createTables();
    }

    public function test_versioned_callback_stores_encrypted_receipt_and_queues_validation(): void
    {
        $payload = $this->successPayload();
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = $this->signedPost(
            '/api/v1/ai/predictions/callback',
            $rawBody,
            'nonce-valid-request-0001',
        );

        $response->assertStatus(202)
            ->assertJsonPath('status', true)
            ->assertJsonPath('duplicate', false);

        $receiptId = (int) $response->json('receipt_id');
        $receipt = Schema::getConnection()->table('ai_prediction_callback_receipts')->find($receiptId);

        $this->assertIsObject($receipt);
        $this->assertSame('prediction-response-1', $receipt->response_id);
        $this->assertSame('SUCCESS', $receipt->response_status);
        $this->assertSame(2, $receipt->result_feature_count);
        $this->assertSame('received', $receipt->processing_status);
        $this->assertNotSame($rawBody, $receipt->encrypted_payload);
        $this->assertSame($rawBody, Crypt::decryptString($receipt->encrypted_payload));

        $this->assertDatabaseHas('ai_prediction_callback_nonces', [
            'nonce' => 'nonce-valid-request-0001',
            'callback_receipt_id' => $receiptId,
        ]);

        Queue::assertPushedOn('ai-callbacks-test', ValidatePredictionCallbackReceiptJob::class);
        Queue::assertPushed(ValidatePredictionCallbackReceiptJob::class, function ($job) use ($receiptId): bool {
            return $job->receiptId === $receiptId;
        });

        app(ValidatePredictionCallbackReceipt::class)->validate($receiptId);

        $this->assertDatabaseHas('ai_prediction_callback_receipts', [
            'id' => $receiptId,
            'processing_status' => 'validated',
            'processing_error' => null,
        ]);
    }

    public function test_legacy_callback_preserves_success_response_shape(): void
    {
        $response = $this->signedPost(
            '/webhook/response-prediction',
            json_encode($this->successPayload(), JSON_THROW_ON_ERROR),
            'nonce-legacy-request-0001',
        );

        $response->assertOk()->assertExactJson(['status' => true]);
    }

    public function test_disabled_callback_remains_closed(): void
    {
        Config::set('mapilio.ai_callback.enabled', false);

        $this->postJson('/api/v1/ai/predictions/callback', $this->successPayload())
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not Found']);

        Queue::assertNothingPushed();
    }

    public function test_callback_rejects_missing_invalid_and_stale_signatures(): void
    {
        $rawBody = json_encode($this->successPayload(), JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v1/ai/predictions/callback', content: $rawBody)
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthorized']);

        $this->signedPost(
            '/api/v1/ai/predictions/callback',
            $rawBody,
            'nonce-invalid-signature-01',
            signature: str_repeat('0', 64),
        )->assertUnauthorized();

        $this->signedPost(
            '/api/v1/ai/predictions/callback',
            $rawBody,
            'nonce-stale-request-0001',
            timestamp: now()->subMinutes(10)->timestamp,
        )->assertUnauthorized();

        $this->assertSame(0, Schema::getConnection()->table('ai_prediction_callback_receipts')->count());
        $this->assertSame(0, Schema::getConnection()->table('ai_prediction_callback_nonces')->count());
        Queue::assertNothingPushed();
    }

    public function test_reused_nonce_is_rejected_as_replay(): void
    {
        $rawBody = json_encode($this->successPayload(), JSON_THROW_ON_ERROR);

        $this->signedPost(
            '/api/v1/ai/predictions/callback',
            $rawBody,
            'nonce-replay-request-0001',
        )->assertStatus(202);

        $this->signedPost(
            '/api/v1/ai/predictions/callback',
            $rawBody,
            'nonce-replay-request-0001',
        )->assertStatus(409)->assertExactJson([
            'message' => 'Callback replay rejected.',
        ]);

        $this->assertSame(1, Schema::getConnection()->table('ai_prediction_callback_receipts')->count());
        $this->assertSame(1, Schema::getConnection()->table('ai_prediction_callback_nonces')->count());
    }

    public function test_same_payload_with_new_nonce_is_idempotent(): void
    {
        $rawBody = json_encode($this->successPayload(), JSON_THROW_ON_ERROR);

        $first = $this->signedPost(
            '/api/v1/ai/predictions/callback',
            $rawBody,
            'nonce-idempotent-request-01',
        )->assertStatus(202);

        $second = $this->signedPost(
            '/api/v1/ai/predictions/callback',
            $rawBody,
            'nonce-idempotent-request-02',
        )->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame($first->json('receipt_id'), $second->json('receipt_id'));
        $this->assertSame(1, Schema::getConnection()->table('ai_prediction_callback_receipts')->count());
        $this->assertSame(2, Schema::getConnection()->table('ai_prediction_callback_nonces')->count());
        $this->assertSame(2, Schema::getConnection()->table('ai_prediction_callback_nonces')
            ->where('callback_receipt_id', (int) $first->json('receipt_id'))
            ->count());
        Queue::assertPushed(ValidatePredictionCallbackReceiptJob::class, 1);
    }

    public function test_distinct_payload_for_existing_response_stream_creates_a_newer_receipt(): void
    {
        $first = $this->signedPost(
            '/api/v1/ai/predictions/callback',
            json_encode($this->successPayload(), JSON_THROW_ON_ERROR),
            'nonce-response-stream-0001',
        )->assertStatus(202)->assertJsonPath('duplicate', false);
        $second = $this->signedPost(
            '/api/v1/ai/predictions/callback',
            json_encode([
                'id' => 'prediction-response-1',
                'status' => 'ERROR',
            ], JSON_THROW_ON_ERROR),
            'nonce-response-stream-0002',
        )->assertStatus(202)->assertJsonPath('duplicate', false);

        $firstReceiptId = (int) $first->json('receipt_id');
        $secondReceiptId = (int) $second->json('receipt_id');

        $this->assertGreaterThan($firstReceiptId, $secondReceiptId);
        $this->assertSame(2, Schema::getConnection()->table('ai_prediction_callback_receipts')
            ->where('response_id', 'prediction-response-1')
            ->count());
        $this->assertDatabaseHas('ai_prediction_callback_nonces', [
            'nonce' => 'nonce-response-stream-0001',
            'callback_receipt_id' => $firstReceiptId,
        ]);
        $this->assertDatabaseHas('ai_prediction_callback_nonces', [
            'nonce' => 'nonce-response-stream-0002',
            'callback_receipt_id' => $secondReceiptId,
        ]);
        Queue::assertPushed(ValidatePredictionCallbackReceiptJob::class, 2);
    }

    public function test_signed_callback_rejects_invalid_or_oversized_payload(): void
    {
        $this->signedPost(
            '/api/v1/ai/predictions/callback',
            json_encode(['id' => 'prediction-response-1', 'status' => 'SUCCESS'], JSON_THROW_ON_ERROR),
            'nonce-invalid-payload-001',
        )->assertStatus(422)->assertExactJson([
            'message' => 'Invalid callback payload.',
        ]);

        $invalidFeatures = $this->successPayload();
        data_set($invalidFeatures, 'result.features', 'not-an-array');

        $this->signedPost(
            '/api/v1/ai/predictions/callback',
            json_encode($invalidFeatures, JSON_THROW_ON_ERROR),
            'nonce-invalid-features-001',
        )->assertStatus(422)->assertExactJson([
            'message' => 'Invalid callback payload.',
        ]);

        Config::set('mapilio.ai_callback.max_payload_bytes', 64);

        $this->signedPost(
            '/api/v1/ai/predictions/callback',
            json_encode($this->successPayload(), JSON_THROW_ON_ERROR),
            'nonce-oversized-payload-01',
        )->assertStatus(413)->assertExactJson([
            'message' => 'Payload Too Large',
        ]);

        $this->assertSame(0, Schema::getConnection()->table('ai_prediction_callback_receipts')->count());
        Queue::assertNothingPushed();
    }

    public function test_receipt_validation_marks_tampered_encrypted_payload_as_error(): void
    {
        $response = $this->signedPost(
            '/api/v1/ai/predictions/callback',
            json_encode($this->successPayload(), JSON_THROW_ON_ERROR),
            'nonce-tampered-receipt-001',
        )->assertStatus(202);
        $receiptId = (int) $response->json('receipt_id');

        Schema::getConnection()->table('ai_prediction_callback_receipts')
            ->where('id', $receiptId)
            ->update([
                'encrypted_payload' => Crypt::encryptString(json_encode([
                    'id' => 'different-response-id',
                    'status' => 'SUCCESS',
                    'result' => ['features' => []],
                ], JSON_THROW_ON_ERROR)),
            ]);

        try {
            app(ValidatePredictionCallbackReceipt::class)->validate($receiptId);
            $this->fail('Tampered callback receipt should not validate.');
        } catch (\Throwable) {
            $this->assertDatabaseHas('ai_prediction_callback_receipts', [
                'id' => $receiptId,
                'processing_status' => 'error',
                'processing_error' => 'Callback receipt integrity validation failed.',
            ]);
        }
    }

    public function test_receipt_validation_persists_malformed_received_row_as_error(): void
    {
        $receiptId = Schema::getConnection()->table('ai_prediction_callback_receipts')->insertGetId([
            'response_id' => 'prediction-response-1',
            'response_status' => 'SUCCESS',
            'payload_hash' => ' ',
            'fingerprint' => str_repeat('r', 64),
            'encrypted_payload' => 'encrypted-callback-payload',
            'result_feature_count' => 0,
            'processing_status' => 'received',
            'processing_error' => null,
            'received_at' => now(),
            'validated_at' => null,
            'processed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(ValidatePredictionCallbackReceipt::class)->validate((int) $receiptId);
            $this->fail('Malformed received callback receipt should not validate.');
        } catch (PredictionCallbackException $exception) {
            $this->assertSame('Callback receipt has an invalid database representation.', $exception->getMessage());
        }

        $this->assertDatabaseHas('ai_prediction_callback_receipts', [
            'id' => $receiptId,
            'processing_status' => 'error',
            'processing_error' => 'Callback receipt has an invalid database representation.',
        ]);
    }

    public function test_receipt_validation_ignores_malformed_terminal_row(): void
    {
        $receiptId = Schema::getConnection()->table('ai_prediction_callback_receipts')->insertGetId([
            'response_id' => ' ',
            'response_status' => ' ',
            'payload_hash' => ' ',
            'fingerprint' => str_repeat('t', 64),
            'encrypted_payload' => ' ',
            'result_feature_count' => 0,
            'processing_status' => 'processed',
            'processing_error' => 'already processed',
            'received_at' => now(),
            'validated_at' => now(),
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse(app(ValidatePredictionCallbackReceipt::class)->validate((int) $receiptId));

        $this->assertDatabaseHas('ai_prediction_callback_receipts', [
            'id' => $receiptId,
            'response_id' => ' ',
            'response_status' => ' ',
            'payload_hash' => ' ',
            'encrypted_payload' => ' ',
            'processing_status' => 'processed',
            'processing_error' => 'already processed',
        ]);
    }

    public function test_receipt_validation_does_not_overwrite_a_concurrent_terminal_transition(): void
    {
        $receiptId = Schema::getConnection()->table('ai_prediction_callback_receipts')->insertGetId([
            'response_id' => 'prediction-response-1',
            'response_status' => 'SUCCESS',
            'payload_hash' => ' ',
            'fingerprint' => str_repeat('c', 64),
            'encrypted_payload' => 'encrypted-callback-payload',
            'result_feature_count' => 0,
            'processing_status' => 'received',
            'processing_error' => null,
            'received_at' => now(),
            'validated_at' => null,
            'processed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $connection = DB::connection();
        $originalDispatcher = $connection->getEventDispatcher();
        $isolatedDispatcher = $originalDispatcher === null
            ? new Dispatcher(app())
            : clone $originalDispatcher;
        $transitioned = false;
        $isolatedDispatcher->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$transitioned, $connection, $receiptId): void {
            if ($transitioned || ! str_contains(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'ai_prediction_callback_receipts')) {
                return;
            }

            $transitioned = true;
            $connection->table('ai_prediction_callback_receipts')
                ->where('id', $receiptId)
                ->update([
                    'processing_status' => 'validated',
                    'processing_error' => null,
                    'validated_at' => now(),
                    'updated_at' => now(),
                ]);
        });
        $connection->setEventDispatcher($isolatedDispatcher);

        $exception = null;
        try {
            app(ValidatePredictionCallbackReceipt::class)->validate((int) $receiptId);
            $this->fail('Malformed received callback receipt should not validate.');
        } catch (PredictionCallbackException $caught) {
            $exception = $caught;
        } finally {
            if ($originalDispatcher === null) {
                $connection->unsetEventDispatcher();
            } else {
                $connection->setEventDispatcher($originalDispatcher);
            }
        }

        $this->assertSame('Callback receipt has an invalid database representation.', $exception->getMessage());

        $this->assertDatabaseHas('ai_prediction_callback_receipts', [
            'id' => $receiptId,
            'processing_status' => 'validated',
            'processing_error' => null,
        ]);
    }

    public function test_validation_job_queues_result_persistence_only_when_enabled(): void
    {
        Config::set('mapilio.ai_result_persistence.enabled', true);
        Config::set('mapilio.ai_result_persistence.queue', 'ai-results-test');
        $response = $this->signedPost(
            '/api/v1/ai/predictions/callback',
            json_encode($this->successPayload(), JSON_THROW_ON_ERROR),
            'nonce-result-handoff-0001',
        )->assertStatus(202);
        $receiptId = (int) $response->json('receipt_id');

        $job = new ValidatePredictionCallbackReceiptJob($receiptId);
        $job->handle(app(ValidatePredictionCallbackReceipt::class));

        Queue::assertPushedOn('ai-results-test', PersistPredictionResultJob::class);
        Queue::assertPushed(PersistPredictionResultJob::class, function ($queued) use ($receiptId): bool {
            return $queued->receiptId === $receiptId;
        });
    }

    /**
     * @return TestResponse<Response>
     */
    private function signedPost(
        string $path,
        string $rawBody,
        string $nonce,
        ?int $timestamp = null,
        ?string $signature = null,
    ): TestResponse {
        $timestamp ??= now()->timestamp;
        $signature ??= hash_hmac(
            'sha256',
            "{$timestamp}.{$nonce}.{$rawBody}",
            self::SECRET,
        );

        return $this->call('POST', $path, server: [
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => strlen($rawBody),
            'HTTP_X_MAPILIO_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_MAPILIO_NONCE' => $nonce,
            'HTTP_X_MAPILIO_SIGNATURE' => 'sha256='.$signature,
        ], content: $rawBody);
    }

    /**
     * @return array<string, mixed>
     */
    private function successPayload(): array
    {
        return [
            'id' => 'prediction-response-1',
            'status' => 'SUCCESS',
            'result' => [
                'type' => 'FeatureCollection',
                'features' => [
                    ['type' => 'Feature', 'properties' => ['class_code' => 'stop-sign']],
                    ['type' => 'Feature', 'properties' => ['class_code' => 'speed-limit']],
                ],
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
            $table->string('processing_status', 48)->default('received');
            $table->text('processing_error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_prediction_callback_nonces', function ($table): void {
            $table->id();
            $table->string('nonce', 128)->unique();
            $table->timestamp('signed_at');
            $table->timestamp('expires_at');
            $table->unsignedBigInteger('callback_receipt_id')->nullable();
            $table->timestamps();
        });
    }
}

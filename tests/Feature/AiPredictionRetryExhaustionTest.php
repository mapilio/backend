<?php

namespace Tests\Feature;

use App\Domain\AiJobsPredictions\Actions\MarkSequenceProcessingFailed;
use App\Jobs\CalculateSequenceUkmScores;
use App\Jobs\DispatchSequencePrediction;
use App\Jobs\PersistPredictionResult;
use App\Jobs\PrepareAiDetectionPublication;
use App\Jobs\ProjectPredictionProcessingStatus;
use App\Jobs\RegisterAiDetectionPublication;
use App\Jobs\ResolveSequenceAddress;
use App\Jobs\ValidatePredictionCallbackReceipt;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class AiPredictionRetryExhaustionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.ai_status_projection.enabled', true);
        $this->createTables();
    }

    public function test_all_four_ai_job_failure_handlers_mark_the_sequence_with_a_generic_terminal_state(): void
    {
        $this->seedSequence('sequence-dispatch');
        $this->seedProcessing('sequence-dispatch', 'dispatch-error', 'ERROR');

        $validateReceipt = $this->seedReceipt('validate-response');
        $this->seedSequence('sequence-validate');
        $this->seedProcessing('sequence-validate', 'validate-response');

        $persistReceipt = $this->seedReceipt('persist-response');
        $this->seedSequence('sequence-persist');
        $this->seedProcessing('sequence-persist', 'persist-response');

        $projectReceipt = $this->seedReceipt('project-response', 'processed');
        $this->seedProjection($projectReceipt, 'error');
        $this->seedSequence('sequence-project');
        $this->seedProcessing('sequence-project', 'project-response');

        $exception = new RuntimeException('sensitive provider response and stack trace');
        (new DispatchSequencePrediction('sequence-dispatch'))->failed($exception);
        (new ValidatePredictionCallbackReceipt($validateReceipt))->failed($exception);
        (new PersistPredictionResult($persistReceipt))->failed($exception);
        (new ProjectPredictionProcessingStatus($projectReceipt))->failed($exception);

        foreach (['dispatch', 'validate', 'persist', 'project'] as $suffix) {
            $this->assertDatabaseHas('default_mapilio_sequence_detail', [
                'sequence_uuid' => "sequence-{$suffix}",
                'last_status' => 'fail',
                'processing_status' => 1,
                'processing_status_message' => 'AI prediction processing failed.',
            ]);
            $this->assertDatabaseMissing('default_mapilio_sequence_detail', [
                'sequence_uuid' => "sequence-{$suffix}",
                'processing_status_message' => 'sensitive provider response and stack trace',
            ]);
        }
    }

    public function test_retry_failure_actions_have_no_sequence_side_effects_when_projection_is_disabled(): void
    {
        $this->seedSequence('sequence-disabled-dispatch-action');
        $this->seedProcessing('sequence-disabled-dispatch-action', 'disabled-dispatch-action', 'ERROR');
        $receiptId = $this->seedReceipt('disabled-receipt-action');
        $this->seedSequence('sequence-disabled-receipt-action');
        $this->seedProcessing('sequence-disabled-receipt-action', 'disabled-receipt-action');
        Config::set('mapilio.ai_status_projection.enabled', false);
        $action = app(MarkSequenceProcessingFailed::class);

        $this->assertFalse($action->markBySequenceUuid('sequence-disabled-dispatch-action'));
        $this->assertFalse($action->markByReceiptId($receiptId));

        $this->assertSequenceIsProcessing('sequence-disabled-dispatch-action');
        $this->assertSequenceIsProcessing('sequence-disabled-receipt-action');
    }

    public function test_all_four_job_failure_handlers_have_no_sequence_side_effects_when_projection_is_disabled(): void
    {
        $this->seedSequence('sequence-disabled-dispatch');
        $this->seedProcessing('sequence-disabled-dispatch', 'disabled-dispatch', 'ERROR');
        $validateReceipt = $this->seedReceipt('disabled-validate');
        $this->seedSequence('sequence-disabled-validate');
        $this->seedProcessing('sequence-disabled-validate', 'disabled-validate');
        $persistReceipt = $this->seedReceipt('disabled-persist');
        $this->seedSequence('sequence-disabled-persist');
        $this->seedProcessing('sequence-disabled-persist', 'disabled-persist');
        $projectReceipt = $this->seedReceipt('disabled-project', 'processed');
        $this->seedProjection($projectReceipt, 'error');
        $this->seedSequence('sequence-disabled-project');
        $this->seedProcessing('sequence-disabled-project', 'disabled-project');
        Config::set('mapilio.ai_status_projection.enabled', false);
        $exception = new RuntimeException('sensitive disabled failure');

        (new DispatchSequencePrediction('sequence-disabled-dispatch'))->failed($exception);
        (new ValidatePredictionCallbackReceipt($validateReceipt))->failed($exception);
        (new PersistPredictionResult($persistReceipt))->failed($exception);
        (new ProjectPredictionProcessingStatus($projectReceipt))->failed($exception);

        foreach (['dispatch', 'validate', 'persist', 'project'] as $suffix) {
            $this->assertSequenceIsProcessing("sequence-disabled-{$suffix}");
        }
    }

    public function test_marking_is_idempotent_and_does_not_overwrite_completed_or_failed_sequences(): void
    {
        $action = app(MarkSequenceProcessingFailed::class);
        $this->seedSequence('sequence-idempotent');
        $this->seedProcessing('sequence-idempotent', 'idempotent-response', 'ERROR');

        $this->assertTrue($action->markBySequenceUuid('sequence-idempotent'));
        $this->assertFalse($action->markBySequenceUuid('sequence-idempotent'));

        foreach (['completed', 'fail'] as $status) {
            $sequence = "sequence-{$status}";
            $this->seedSequence($sequence, $status);
            $this->seedProcessing($sequence, "{$status}-response", 'ERROR');

            $this->assertFalse($action->markBySequenceUuid($sequence));
            $this->assertDatabaseHas('default_mapilio_sequence_detail', [
                'sequence_uuid' => $sequence,
                'last_status' => $status,
                'processing_status' => $status === 'completed' ? 3 : 1,
                'processing_status_message' => null,
            ]);
        }
    }

    public function test_processed_receipt_without_error_projection_is_not_failed_by_result_fanout_failure(): void
    {
        $receiptId = $this->seedReceipt('processed-fanout-response', 'processed');
        $this->seedSequence('sequence-processed-fanout');
        $this->seedProcessing('sequence-processed-fanout', 'processed-fanout-response');

        (new PersistPredictionResult($receiptId))->failed(new RuntimeException('geo queue dispatch failed'));

        $this->assertSequenceIsProcessing('sequence-processed-fanout');
    }

    public function test_processed_receipt_with_terminal_projection_error_is_marked_failed(): void
    {
        $receiptId = $this->seedReceipt('projection-error-response', 'processed');
        $this->seedProjection($receiptId, 'error');
        $this->seedSequence('sequence-projection-error');
        $this->seedProcessing('sequence-projection-error', 'projection-error-response');

        (new ProjectPredictionProcessingStatus($receiptId))->failed(new RuntimeException('projection retry exhausted'));

        $this->assertDatabaseHas('default_mapilio_sequence_detail', [
            'sequence_uuid' => 'sequence-projection-error',
            'last_status' => 'fail',
            'processing_status' => 1,
            'processing_status_message' => 'AI prediction processing failed.',
        ]);
    }

    public function test_receipt_failure_does_not_overwrite_when_a_newer_processing_attempt_owns_the_sequence(): void
    {
        $receiptId = $this->seedReceipt('older-response');
        $this->seedSequence('sequence-newer-processing');
        $this->seedProcessing('sequence-newer-processing', 'older-response');
        $this->seedProcessing('sequence-newer-processing', 'newer-response', 'pending');

        $this->assertFalse(app(MarkSequenceProcessingFailed::class)->markByReceiptId($receiptId));
        $this->assertDatabaseHas('default_mapilio_sequence_detail', [
            'sequence_uuid' => 'sequence-newer-processing',
            'last_status' => 'processing',
            'processing_status' => 2,
            'processing_status_message' => null,
        ]);
    }

    public function test_receipt_failure_does_not_overwrite_when_a_newer_callback_receipt_exists(): void
    {
        $this->seedSequence('sequence-newer-receipt');
        $this->seedProcessing('sequence-newer-receipt', 'same-response');
        $olderReceiptId = $this->seedReceipt('same-response');
        $this->seedReceipt('same-response');

        $this->assertFalse(app(MarkSequenceProcessingFailed::class)->markByReceiptId($olderReceiptId));
        $this->assertDatabaseHas('default_mapilio_sequence_detail', [
            'sequence_uuid' => 'sequence-newer-receipt',
            'last_status' => 'processing',
            'processing_status' => 2,
        ]);
    }

    public function test_dispatch_failure_requires_the_latest_processing_attempt_to_be_error(): void
    {
        $this->seedSequence('sequence-dispatch-prerequisite');
        $processingId = $this->seedProcessing('sequence-dispatch-prerequisite', 'dispatch-response', 'pending');
        $action = app(MarkSequenceProcessingFailed::class);

        $this->assertFalse($action->markBySequenceUuid('sequence-dispatch-prerequisite'));
        $this->assertDatabaseHas('default_mapilio_sequence_detail', [
            'sequence_uuid' => 'sequence-dispatch-prerequisite',
            'last_status' => 'processing',
        ]);

        Schema::getConnection()->table('default_mapilio_processing')
            ->where('id', $processingId)
            ->update(['process_status' => 'ERROR']);

        $this->assertTrue($action->markBySequenceUuid('sequence-dispatch-prerequisite'));
        $this->assertDatabaseHas('default_mapilio_sequence_detail', [
            'sequence_uuid' => 'sequence-dispatch-prerequisite',
            'last_status' => 'fail',
            'processing_status' => 1,
            'processing_status_message' => 'AI prediction processing failed.',
        ]);
    }

    public function test_missing_or_ambiguous_ownership_is_a_no_op(): void
    {
        $action = app(MarkSequenceProcessingFailed::class);

        $this->assertFalse($action->markByReceiptId(999));
        $this->assertFalse($action->markBySequenceUuid('missing-sequence'));

        $ambiguousReceiptId = $this->seedReceipt('ambiguous-response');
        $this->seedSequence('sequence-ambiguous-response');
        $this->seedProcessing('sequence-ambiguous-response', 'ambiguous-response');
        $this->seedProcessing('sequence-ambiguous-response', 'ambiguous-response');
        $this->assertFalse($action->markByReceiptId($ambiguousReceiptId));

        $this->seedSequence('sequence-ambiguous-detail');
        $this->seedSequence('sequence-ambiguous-detail');
        $this->seedProcessing('sequence-ambiguous-detail', 'detail-response', 'ERROR');
        $this->assertFalse($action->markBySequenceUuid('sequence-ambiguous-detail'));
    }

    public function test_retry_exhaustion_handlers_are_absent_from_non_ai_sequence_processing_jobs(): void
    {
        foreach ([
            ResolveSequenceAddress::class,
            CalculateSequenceUkmScores::class,
            RegisterAiDetectionPublication::class,
            PrepareAiDetectionPublication::class,
        ] as $job) {
            $this->assertFalse((new ReflectionClass($job))->hasMethod('failed'), $job);
        }
    }

    private function createTables(): void
    {
        Schema::create('ai_prediction_callback_receipts', function ($table): void {
            $table->id();
            $table->string('response_id');
            $table->string('processing_status');
            $table->timestamps();
        });

        Schema::create('ai_prediction_status_projections', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('callback_receipt_id')->unique();
            $table->string('projection_status');
            $table->timestamps();
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

    private function seedReceipt(string $responseId, string $processingStatus = 'error'): int
    {
        return Schema::getConnection()->table('ai_prediction_callback_receipts')->insertGetId([
            'response_id' => $responseId,
            'processing_status' => $processingStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedProjection(int $receiptId, string $status): int
    {
        return Schema::getConnection()->table('ai_prediction_status_projections')->insertGetId([
            'callback_receipt_id' => $receiptId,
            'projection_status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedSequence(string $sequenceUuid, string $status = 'processing'): int
    {
        return Schema::getConnection()->table('default_mapilio_sequence_detail')->insertGetId([
            'sequence_uuid' => $sequenceUuid,
            'last_status' => $status,
            'processing_status' => match ($status) {
                'completed' => 3,
                'fail' => 1,
                default => 2,
            },
            'processing_status_message' => null,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedProcessing(string $sequenceUuid, string $responseId, string $status = 'pending'): int
    {
        return Schema::getConnection()->table('default_mapilio_processing')->insertGetId([
            'response_id' => $responseId,
            'sequence_uuid' => $sequenceUuid,
            'process_status' => $status,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertSequenceIsProcessing(string $sequenceUuid): void
    {
        $this->assertDatabaseHas('default_mapilio_sequence_detail', [
            'sequence_uuid' => $sequenceUuid,
            'last_status' => 'processing',
            'processing_status' => 2,
            'processing_status_message' => null,
        ]);
    }
}

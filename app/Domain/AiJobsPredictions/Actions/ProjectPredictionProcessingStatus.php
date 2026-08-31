<?php

namespace App\Domain\AiJobsPredictions\Actions;

use App\Support\Database\LegacyDatabase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProjectPredictionProcessingStatus
{
    public function project(int $receiptId): bool
    {
        if (! config('mapilio.ai_status_projection.enabled')) {
            return false;
        }

        $receipt = DB::table('ai_prediction_callback_receipts')
            ->where('id', $receiptId)
            ->first(['id', 'processing_status', 'response_id', 'response_status']);

        if ($receipt === null) {
            throw new PredictionStatusProjectionException("Callback receipt {$receiptId} was not found.");
        }

        $receipt = AiPredictionStatusReceiptRow::fromDatabaseRow($receipt);

        if ($receipt->processingStatus !== 'processed') {
            throw new PredictionStatusProjectionException("Callback receipt {$receiptId} has not been processed.");
        }

        $processing = $this->processingContext($receipt->responseId);
        $sequence = $this->sequenceContext($processing->sequenceUuid);
        $this->ensureProjection($receipt, $processing);

        try {
            return DB::transaction(function () use ($receipt, $processing, $sequence): bool {
                $projection = DB::table('ai_prediction_status_projections')
                    ->where('callback_receipt_id', $receipt->id)
                    ->lockForUpdate()
                    ->first(['id', 'projection_status']);

                if (! is_object($projection)) {
                    throw new PredictionStatusProjectionException('Prediction projection could not be loaded.');
                }

                $projection = AiPredictionStatusProjectionRow::fromDatabaseRow($projection);

                if ($projection->projectionStatus === 'projected') {
                    return false;
                }

                DB::table('ai_prediction_status_projections')
                    ->where('id', $projection->id)
                    ->update([
                        'projection_status' => 'projecting',
                        'attempts' => DB::raw('attempts + 1'),
                        'last_error' => null,
                        'updated_at' => now(),
                    ]);

                $this->legacyConnection()->transaction(function () use ($receipt, $processing, $sequence): void {
                    $lockedSequence = $this->legacyConnection()->table('default_mapilio_sequence_detail')
                        ->where('id', $sequence->id)
                        ->whereNull('deleted_at')
                        ->lockForUpdate()
                        ->first(['id', 'sequence_uuid']);

                    if (! is_object($lockedSequence)) {
                        throw new PredictionStatusProjectionException('Sequence detail has an invalid database representation.');
                    }

                    $lockedSequence = AiPredictionStatusSequenceRow::fromDatabaseRow($lockedSequence);
                    $hasNewerReceipt = DB::table('ai_prediction_callback_receipts')
                        ->where('response_id', $receipt->responseId)
                        ->where('id', '>', $receipt->id)
                        ->whereExists(function ($query): void {
                            $query->select(DB::raw(1))
                                ->from('ai_prediction_status_projections')
                                ->whereColumn('ai_prediction_status_projections.callback_receipt_id', 'ai_prediction_callback_receipts.id');
                        })
                        ->exists();

                    if ($hasNewerReceipt) {
                        return;
                    }

                    $hasNewerProcessing = $this->legacyConnection()->table('default_mapilio_processing')
                        ->where('sequence_uuid', $lockedSequence->sequenceUuid)
                        ->whereNull('deleted_at')
                        ->where('id', '>', $processing->id)
                        ->exists();

                    $processingValues = $this->onlyExistingColumns('default_mapilio_processing', [
                        'process_status' => $receipt->responseStatus,
                        'updated_at' => now(),
                    ]);
                    $sequenceValues = $receipt->responseStatus === 'SUCCESS'
                        ? [
                            'last_status' => 'completed',
                            'processing_status' => 3,
                            'processing_status_message' => null,
                            'updated_at' => now(),
                        ]
                        : [
                            'last_status' => 'fail',
                            'processing_status' => 1,
                            'processing_status_message' => 'AI prediction processing failed.',
                            'updated_at' => now(),
                        ];

                    $this->legacyConnection()->table('default_mapilio_processing')
                        ->where('id', $processing->id)
                        ->update($processingValues);

                    if (! $hasNewerProcessing) {
                        $this->legacyConnection()->table('default_mapilio_sequence_detail')
                            ->where('id', $lockedSequence->id)
                            ->update($this->onlyExistingColumns('default_mapilio_sequence_detail', $sequenceValues));
                    }
                });

                DB::table('ai_prediction_status_projections')
                    ->where('id', $projection->id)
                    ->update([
                        'projection_status' => 'projected',
                        'last_error' => null,
                        'projected_at' => now(),
                        'updated_at' => now(),
                    ]);

                return true;
            });
        } catch (Throwable $exception) {
            $message = 'Prediction status projection could not be completed.';

            try {
                DB::transaction(function () use ($receipt, $message): void {
                    $projection = DB::table('ai_prediction_status_projections')
                        ->where('callback_receipt_id', $receipt->id)
                        ->lockForUpdate()
                        ->first(['id', 'projection_status']);

                    if (! is_object($projection)) {
                        throw new PredictionStatusProjectionException('Prediction projection could not be loaded.');
                    }

                    $projection = AiPredictionStatusProjectionRow::fromDatabaseRow($projection);
                    $values = [
                        'attempts' => DB::raw('attempts + 1'),
                        'updated_at' => now(),
                    ];

                    if ($projection->projectionStatus !== 'projected') {
                        $values['projection_status'] = 'error';
                        $values['last_error'] = $message;
                    }

                    DB::table('ai_prediction_status_projections')
                        ->where('id', $projection->id)
                        ->update($values);
                });
            } catch (Throwable) {
                // The public failure remains generic even if recovery cannot be persisted.
            }

            throw new PredictionStatusProjectionException($message);
        }
    }

    private function processingContext(string $responseId): AiPredictionStatusProcessingRow
    {
        $rows = $this->legacyConnection()->table('default_mapilio_processing')
            ->where('response_id', $responseId)
            ->whereNull('deleted_at')
            ->limit(2)
            ->get(['id', 'response_id', 'sequence_uuid']);

        if ($rows->count() !== 1) {
            throw new PredictionStatusProjectionException('AI response id does not belong to exactly one processing request.');
        }

        $processing = $rows->first();

        if (! is_object($processing)) {
            throw new PredictionStatusProjectionException('Processing request has an invalid database representation.');
        }

        return AiPredictionStatusProcessingRow::fromDatabaseRow($processing);
    }

    private function sequenceContext(string $sequenceUuid): AiPredictionStatusSequenceRow
    {
        $rows = $this->legacyConnection()->table('default_mapilio_sequence_detail')
            ->where('sequence_uuid', $sequenceUuid)
            ->whereNull('deleted_at')
            ->limit(2)
            ->get(['id', 'sequence_uuid']);

        if ($rows->count() !== 1) {
            throw new PredictionStatusProjectionException('Processing request does not belong to exactly one sequence detail.');
        }

        $sequence = $rows->first();

        if (! is_object($sequence)) {
            throw new PredictionStatusProjectionException('Sequence detail has an invalid database representation.');
        }

        return AiPredictionStatusSequenceRow::fromDatabaseRow($sequence);
    }

    private function ensureProjection(AiPredictionStatusReceiptRow $receipt, AiPredictionStatusProcessingRow $processing): void
    {
        DB::table('ai_prediction_status_projections')->insertOrIgnore([
            'callback_receipt_id' => $receipt->id,
            'response_id' => $receipt->responseId,
            'sequence_uuid' => $processing->sequenceUuid,
            'response_status' => $receipt->responseStatus,
            'projection_status' => 'pending',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function onlyExistingColumns(string $table, array $values): array
    {
        $columns = Schema::connection(config('mapilio.legacy_database_connection'))
            ->getColumnListing($table);

        return array_intersect_key($values, array_flip($columns));
    }

    private function legacyConnection(): Connection
    {
        return LegacyDatabase::connection();
    }
}

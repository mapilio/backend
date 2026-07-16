<?php

namespace App\Domain\AiJobsPredictions\Actions;

use App\Support\Database\LegacyDatabase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class ProjectPredictionProcessingStatus
{
    public function project(int $receiptId): bool
    {
        if (! config('mapilio.ai_status_projection.enabled')) {
            return false;
        }

        $receipt = DB::table('ai_prediction_callback_receipts')->find($receiptId);

        if ($receipt === null) {
            throw new PredictionStatusProjectionException("Callback receipt {$receiptId} was not found.");
        }

        if (! is_object($receipt)) {
            throw new PredictionStatusProjectionException('Callback receipt has an invalid database representation.');
        }

        if ($receipt->processing_status !== 'processed') {
            throw new PredictionStatusProjectionException("Callback receipt {$receiptId} has not been processed.");
        }

        $processing = $this->processingContext($receipt->response_id);
        $sequence = $this->sequenceContext($processing->sequence_uuid);
        $projection = $this->projection($receipt, $processing);

        if ($projection->projection_status === 'projected') {
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

        try {
            $this->legacyConnection()->transaction(function () use ($receipt, $processing, $sequence): void {
                $processingValues = $this->onlyExistingColumns('default_mapilio_processing', [
                    'process_status' => $receipt->response_status,
                    'updated_at' => now(),
                ]);
                $sequenceValues = $receipt->response_status === 'SUCCESS'
                    ? [
                        'last_status' => 'completed',
                        'processing_status' => 3,
                        'processing_status_message' => null,
                        'updated_at' => now(),
                    ]
                    : [
                        'last_status' => 'uploaded',
                        'processing_status' => 1,
                        'processing_status_message' => 'AI prediction processing failed.',
                        'updated_at' => now(),
                    ];

                $this->legacyConnection()->table('default_mapilio_processing')
                    ->where('id', $processing->id)
                    ->update($processingValues);
                $this->legacyConnection()->table('default_mapilio_sequence_detail')
                    ->where('id', $sequence->id)
                    ->update($this->onlyExistingColumns('default_mapilio_sequence_detail', $sequenceValues));
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
        } catch (Throwable $exception) {
            $message = $exception instanceof PredictionStatusProjectionException
                ? Str::limit($exception->getMessage(), 1000, '')
                : 'Prediction status projection could not be completed.';

            DB::table('ai_prediction_status_projections')
                ->where('id', $projection->id)
                ->update([
                    'projection_status' => 'error',
                    'last_error' => $message,
                    'updated_at' => now(),
                ]);

            if ($exception instanceof PredictionStatusProjectionException) {
                throw $exception;
            }

            throw new PredictionStatusProjectionException($message, previous: $exception);
        }
    }

    private function processingContext(string $responseId): object
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

        return $processing;
    }

    private function sequenceContext(string $sequenceUuid): object
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

        return $sequence;
    }

    private function projection(object $receipt, object $processing): object
    {
        DB::table('ai_prediction_status_projections')->insertOrIgnore([
            'callback_receipt_id' => $receipt->id,
            'response_id' => $receipt->response_id,
            'sequence_uuid' => $processing->sequence_uuid,
            'response_status' => $receipt->response_status,
            'projection_status' => 'pending',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $projection = DB::table('ai_prediction_status_projections')
            ->where('callback_receipt_id', $receipt->id)
            ->first();

        if (! is_object($projection)) {
            throw new PredictionStatusProjectionException('Prediction projection could not be loaded.');
        }

        return $projection;
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

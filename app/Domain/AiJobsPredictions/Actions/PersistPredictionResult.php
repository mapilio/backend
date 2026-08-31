<?php

namespace App\Domain\AiJobsPredictions\Actions;

use App\Support\Database\LegacyDatabase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

class PersistPredictionResult
{
    public function __construct(private readonly NormalizePredictionResult $normalizer) {}

    public function persist(int $receiptId): bool
    {
        if (! config('mapilio.ai_result_persistence.enabled')) {
            return false;
        }

        try {
            $databaseReceipt = DB::table('ai_prediction_callback_receipts')
                ->select([
                    'id',
                    'processing_status',
                    'encrypted_payload',
                    'response_id',
                    'response_status',
                    'result_feature_count',
                ])
                ->where('id', $receiptId)
                ->first();

            if ($databaseReceipt === null) {
                throw new PredictionResultPersistenceException("Callback receipt {$receiptId} was not found.");
            }

            $receipt = AiPredictionResultReceiptRow::fromDatabaseRow($databaseReceipt);

            if ($receipt->processingStatus === 'processed') {
                return false;
            }

            if ($receipt->processingStatus !== 'validated') {
                throw new PredictionResultPersistenceException("Callback receipt {$receiptId} is not validated.");
            }

            $payload = $this->payload($receipt->encryptedPayload);
            $this->assertReceiptMatchesPayload($receipt, $payload);
            $processing = $this->processingContext($receipt->responseId);
            $features = $this->normalizer->normalize($payload, $processing);

            if ($receipt->responseStatus === 'SUCCESS' && count($features) !== $receipt->resultFeatureCount) {
                throw new PredictionResultPersistenceException('AI result feature count does not match its receipt.');
            }

            return DB::transaction(function () use ($receiptId, $receipt, $processing, $features): bool {
                $databaseLocked = DB::table('ai_prediction_callback_receipts')
                    ->select(['processing_status'])
                    ->where('id', $receiptId)
                    ->lockForUpdate()
                    ->first();

                if ($databaseLocked === null) {
                    return false;
                }

                $locked = AiPredictionResultLockedReceiptRow::fromDatabaseRow($databaseLocked);

                if ($locked->processingStatus === 'processed') {
                    return false;
                }

                if ($locked->processingStatus !== 'validated') {
                    throw new PredictionResultPersistenceException("Callback receipt {$receiptId} is not validated.");
                }

                foreach ($features as $feature) {
                    $this->persistFeature($receipt, $processing, $feature);
                }

                DB::table('ai_prediction_callback_receipts')
                    ->where('id', $receiptId)
                    ->update([
                        'processing_status' => 'processed',
                        'processing_error' => null,
                        'processed_at' => now(),
                        'updated_at' => now(),
                    ]);

                return true;
            });
        } catch (Throwable $exception) {
            $message = $exception instanceof PredictionResultPersistenceException
                ? Str::limit($exception->getMessage(), 1000, '')
                : 'AI result persistence could not be completed.';

            $this->recordError($receiptId, $message);

            if ($exception instanceof PredictionResultPersistenceException) {
                throw $exception;
            }

            throw new PredictionResultPersistenceException($message);
        }
    }

    /**
     * @param  array<string, mixed>  $feature
     */
    private function persistFeature(
        AiPredictionResultReceiptRow $receipt,
        AiPredictionResultProcessingRow $processing,
        array $feature,
    ): void {
        $identity = [
            'callback_receipt_id' => $receipt->id,
            'source_index' => $feature['source_index'],
        ];

        DB::table('ai_detection_features')->updateOrInsert($identity, [
            'response_id' => $receipt->responseId,
            'sequence_uuid' => $processing->sequenceUuid,
            'created_by_id' => $processing->createdById,
            'organization_key' => $processing->organizationKey,
            'project_key' => $processing->projectKey,
            'class_code' => $feature['class_code'],
            'confidence' => $feature['confidence'],
            'longitude' => $feature['longitude'],
            'latitude' => $feature['latitude'],
            'geometry' => $this->json($feature['geometry']),
            'width' => $feature['width'],
            'height' => $feature['height'],
            'area' => $feature['area'],
            'verified' => $feature['verified'],
            'attributes' => $feature['attributes'] === null ? null : $this->json($feature['attributes']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $featureId = $this->positiveDatabaseInteger(DB::table('ai_detection_features')
            ->where($identity)
            ->select('id')
            ->value('id'));

        foreach ($feature['matches'] as $match) {
            $observation1 = $this->persistObservation($receipt, $processing, $match['observation_1']);
            $observation2 = $this->persistObservation($receipt, $processing, $match['observation_2']);

            DB::table('ai_detection_matches')->updateOrInsert([
                'detection_feature_id' => $featureId,
                'source_index' => $match['source_index'],
            ], [
                'observation_1_id' => $observation1,
                'observation_2_id' => $observation2,
                'longitude' => $match['longitude'],
                'latitude' => $match['latitude'],
                'geometry' => $this->json($match['geometry']),
                'score' => $match['score'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $observation
     */
    private function persistObservation(
        AiPredictionResultReceiptRow $receipt,
        AiPredictionResultProcessingRow $processing,
        array $observation,
    ): int {
        $identity = [
            'response_id' => $receipt->responseId,
            'object_key' => $observation['object_key'],
        ];

        DB::table('ai_detection_observations')->updateOrInsert($identity, [
            'sequence_uuid' => $processing->sequenceUuid,
            'imagery_id' => $observation['imagery_id'],
            'x_min' => $observation['x_min'],
            'y_min' => $observation['y_min'],
            'x_max' => $observation['x_max'],
            'y_max' => $observation['y_max'],
            'score' => $observation['score'],
            'segmentation' => $observation['segmentation'] === null ? null : $this->json($observation['segmentation']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->positiveDatabaseInteger(DB::table('ai_detection_observations')
            ->where($identity)
            ->select('id')
            ->value('id'));
    }

    private function processingContext(string $responseId): AiPredictionResultProcessingRow
    {
        $entries = $this->legacyConnection()
            ->table('default_mapilio_processing')
            ->where('response_id', $responseId)
            ->whereNull('deleted_at')
            ->limit(2)
            ->get([
                'response_id',
                'sequence_uuid',
                'created_by_id',
                'organization_key',
                'project_key',
            ]);

        if ($entries->count() !== 1) {
            throw new PredictionResultPersistenceException('AI response id does not belong to exactly one processing request.');
        }

        $entry = $entries->first();

        if (! is_object($entry)) {
            throw new PredictionResultPersistenceException('AI processing request has an invalid database representation.');
        }

        return AiPredictionResultProcessingRow::fromDatabaseRow($entry);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $encryptedPayload): array
    {
        try {
            $payload = json_decode(
                Crypt::decryptString($encryptedPayload),
                true,
                depth: 64,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (Throwable) {
            throw new PredictionResultPersistenceException('Callback receipt payload could not be decoded.');
        }

        if (! is_array($payload)) {
            throw new PredictionResultPersistenceException('Callback receipt payload is invalid.');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertReceiptMatchesPayload(AiPredictionResultReceiptRow $receipt, array $payload): void
    {
        if (
            (string) ($payload['id'] ?? '') !== $receipt->responseId
            || strtoupper((string) ($payload['status'] ?? '')) !== $receipt->responseStatus
        ) {
            throw new PredictionResultPersistenceException('Callback receipt ownership fields do not match its payload.');
        }
    }

    private function legacyConnection(): Connection
    {
        return LegacyDatabase::connection();
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PredictionResultPersistenceException('AI result contains invalid JSON values.');
        }
    }

    private function recordError(int $receiptId, string $message): void
    {
        try {
            DB::table('ai_prediction_callback_receipts')
                ->where('id', $receiptId)
                ->where('processing_status', '!=', 'processed')
                ->update([
                    'processing_status' => 'error',
                    'processing_error' => $message,
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            // Preserve the bounded public error if durable error recording is unavailable.
        }
    }

    private function positiveDatabaseInteger(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/D', $value)) {
            throw new PredictionResultPersistenceException('Canonical AI result has an invalid database representation.');
        }

        $normalized = filter_var($value, FILTER_VALIDATE_INT);

        if ($normalized === false || $normalized < 1) {
            throw new PredictionResultPersistenceException('Canonical AI result has an invalid database representation.');
        }

        return $normalized;
    }
}

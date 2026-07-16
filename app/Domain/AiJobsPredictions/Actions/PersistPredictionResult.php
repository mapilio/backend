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

        $receipt = DB::table('ai_prediction_callback_receipts')->find($receiptId);

        if ($receipt === null) {
            throw new PredictionResultPersistenceException("Callback receipt {$receiptId} was not found.");
        }

        if (! is_object($receipt)) {
            throw new PredictionResultPersistenceException('Callback receipt has an invalid database representation.');
        }

        if ($receipt->processing_status === 'processed') {
            return false;
        }

        if ($receipt->processing_status !== 'validated') {
            throw new PredictionResultPersistenceException("Callback receipt {$receiptId} is not validated.");
        }

        try {
            $payload = $this->payload($receipt->encrypted_payload);
            $this->assertReceiptMatchesPayload($receipt, $payload);
            $processing = $this->processingContext($receipt->response_id);
            $features = $this->normalizer->normalize($payload, $processing);

            if ($receipt->response_status === 'SUCCESS' && count($features) !== (int) $receipt->result_feature_count) {
                throw new PredictionResultPersistenceException('AI result feature count does not match its receipt.');
            }

            return DB::transaction(function () use ($receiptId, $receipt, $processing, $features): bool {
                $locked = DB::table('ai_prediction_callback_receipts')
                    ->where('id', $receiptId)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null || $locked->processing_status === 'processed') {
                    return false;
                }

                if ($locked->processing_status !== 'validated') {
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

            DB::table('ai_prediction_callback_receipts')
                ->where('id', $receiptId)
                ->where('processing_status', '!=', 'processed')
                ->update([
                    'processing_status' => 'error',
                    'processing_error' => $message,
                    'updated_at' => now(),
                ]);

            if ($exception instanceof PredictionResultPersistenceException) {
                throw $exception;
            }

            throw new PredictionResultPersistenceException($message, previous: $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $feature
     */
    private function persistFeature(object $receipt, object $processing, array $feature): void
    {
        $identity = [
            'callback_receipt_id' => $receipt->id,
            'source_index' => $feature['source_index'],
        ];

        DB::table('ai_detection_features')->updateOrInsert($identity, [
            'response_id' => $receipt->response_id,
            'sequence_uuid' => $processing->sequence_uuid,
            'created_by_id' => $processing->created_by_id,
            'organization_key' => $processing->organization_key,
            'project_key' => $processing->project_key,
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

        $featureId = (int) DB::table('ai_detection_features')
            ->where($identity)
            ->value('id');

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
    private function persistObservation(object $receipt, object $processing, array $observation): int
    {
        $identity = [
            'response_id' => $receipt->response_id,
            'object_key' => $observation['object_key'],
        ];

        DB::table('ai_detection_observations')->updateOrInsert($identity, [
            'sequence_uuid' => $processing->sequence_uuid,
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

        return (int) DB::table('ai_detection_observations')
            ->where($identity)
            ->value('id');
    }

    private function processingContext(string $responseId): object
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

        return $entry;
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
        } catch (Throwable $exception) {
            throw new PredictionResultPersistenceException('Callback receipt payload could not be decoded.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new PredictionResultPersistenceException('Callback receipt payload is invalid.');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertReceiptMatchesPayload(object $receipt, array $payload): void
    {
        if (
            (string) ($payload['id'] ?? '') !== $receipt->response_id
            || strtoupper((string) ($payload['status'] ?? '')) !== $receipt->response_status
        ) {
            throw new PredictionResultPersistenceException('Callback receipt ownership fields do not match its payload.');
        }
    }

    private function legacyConnection(): Connection
    {
        return LegacyDatabase::connection();
    }

    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PredictionResultPersistenceException('AI result contains invalid JSON values.', previous: $exception);
        }
    }
}

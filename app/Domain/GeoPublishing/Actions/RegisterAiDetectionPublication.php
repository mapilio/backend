<?php

namespace App\Domain\GeoPublishing\Actions;

use App\Support\Database\LegacyDatabase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class RegisterAiDetectionPublication
{
    public function register(int $receiptId): bool
    {
        if (! config('mapilio.geo_publication.registration_enabled')) {
            return false;
        }

        $receiptRow = DB::table('ai_prediction_callback_receipts')
            ->where('id', $receiptId)
            ->first([
                'processing_status',
                'response_status',
                'result_feature_count',
                'response_id',
            ]);

        if ($receiptRow === null) {
            throw new GeoPublicationException("Callback receipt {$receiptId} was not found.");
        }

        $receipt = AiPublicationReceiptRow::fromDatabaseRow($receiptRow);

        if ($receipt->processingStatus !== 'processed') {
            throw new GeoPublicationException("Callback receipt {$receiptId} has not been processed.");
        }

        if ($receipt->responseStatus !== 'SUCCESS') {
            return false;
        }

        $features = DB::table('ai_detection_features')
            ->where('callback_receipt_id', $receiptId)
            ->selectRaw('sequence_uuid, count(*) as feature_count')
            ->groupBy('sequence_uuid')
            ->limit(2)
            ->get()
            ->map(
                fn (object $row): AiPublicationFeatureSummaryRow => AiPublicationFeatureSummaryRow::fromDatabaseRow($row),
            );

        $actualFeatureCount = $features->reduce(
            fn (int $total, AiPublicationFeatureSummaryRow $feature): int => $total + $feature->featureCount,
            0,
        );

        if ($features->count() > 1 || $actualFeatureCount !== $receipt->resultFeatureCount) {
            throw new GeoPublicationException('Canonical detection features do not match their processed AI receipt.');
        }

        $feature = $features->first();
        $sequenceUuid = $this->sequenceUuid($receipt, $feature);

        $inserted = DB::table('geospatial_publications')->insertOrIgnore([
            'callback_receipt_id' => $receiptId,
            'sequence_uuid' => $sequenceUuid,
            'source_type' => 'ai_prediction_receipt',
            'source_id' => $receiptId,
            'target' => (string) config('mapilio.geo_publication.target', 'canonical_ai_detections'),
            'target_layer' => config('mapilio.geo_publication.layer'),
            'feature_count' => $actualFeatureCount,
            'publication_status' => 'blocked',
            'status_reason' => 'Database projection has not been reconciled.',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $inserted === 1;
    }

    private function sequenceUuid(
        AiPublicationReceiptRow $receipt,
        ?AiPublicationFeatureSummaryRow $feature,
    ): string {
        if ($feature !== null) {
            return $feature->sequenceUuid;
        }

        $sequenceUuids = $this->legacyConnection()->table('default_mapilio_processing')
            ->where('response_id', $receipt->responseId)
            ->whereNull('deleted_at')
            ->limit(2)
            ->pluck('sequence_uuid');

        $sequenceUuid = $sequenceUuids->first();

        if ($sequenceUuids->count() !== 1 || ! is_string($sequenceUuid) || trim($sequenceUuid) === '') {
            throw new GeoPublicationException('AI publication sequence ownership could not be resolved.');
        }

        return $sequenceUuid;
    }

    private function legacyConnection(): Connection
    {
        return LegacyDatabase::connection();
    }
}

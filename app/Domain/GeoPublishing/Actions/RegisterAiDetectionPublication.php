<?php

namespace App\Domain\GeoPublishing\Actions;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

class RegisterAiDetectionPublication
{
    public function register(int $receiptId): bool
    {
        if (! config('mapilio.geo_publication.registration_enabled')) {
            return false;
        }

        $receipt = DB::table('ai_prediction_callback_receipts')->find($receiptId);

        if ($receipt === null) {
            throw new GeoPublicationException("Callback receipt {$receiptId} was not found.");
        }

        if ($receipt->processing_status !== 'processed') {
            throw new GeoPublicationException("Callback receipt {$receiptId} has not been processed.");
        }

        if ($receipt->response_status !== 'SUCCESS') {
            return false;
        }

        $features = DB::table('ai_detection_features')
            ->where('callback_receipt_id', $receiptId)
            ->selectRaw('sequence_uuid, count(*) as feature_count')
            ->groupBy('sequence_uuid')
            ->limit(2)
            ->get();

        $actualFeatureCount = (int) $features->sum('feature_count');

        if ($features->count() > 1 || $actualFeatureCount !== (int) $receipt->result_feature_count) {
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

    private function sequenceUuid(object $receipt, ?object $feature): string
    {
        if (is_string($feature?->sequence_uuid) && $feature->sequence_uuid !== '') {
            return $feature->sequence_uuid;
        }

        $processing = $this->legacyConnection()->table('default_mapilio_processing')
            ->where('response_id', $receipt->response_id)
            ->whereNull('deleted_at')
            ->limit(2)
            ->get(['sequence_uuid']);

        if ($processing->count() !== 1 || ! is_string($processing->first()->sequence_uuid)) {
            throw new GeoPublicationException('AI publication sequence ownership could not be resolved.');
        }

        return $processing->first()->sequence_uuid;
    }

    private function legacyConnection(): ConnectionInterface
    {
        return DB::connection(config('mapilio.legacy_database_connection'));
    }
}

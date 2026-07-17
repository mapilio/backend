<?php

namespace Database\Seeders;

use App\Support\Database\LocalDemoSeedGuard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;

final class LocalDemoSeeder extends Seeder
{
    public const FEATURE_ID = 900_000_001;

    public const RECEIPT_ID = 900_000_001;

    /**
     * @throws JsonException
     */
    public function run(): void
    {
        $connection = DB::connection();

        LocalDemoSeedGuard::assertAllowed(
            (string) config('app.env'),
            config('mapilio.local_demo_seeding.enabled'),
            $connection->getDriverName(),
        );

        $timestamp = '2026-01-01 00:00:00';
        $responseId = 'demo-response-0001';
        $sequenceUuid = 'demo-sequence-0001';
        $geometry = [
            'type' => 'Point',
            'coordinates' => [29.0255, 40.9911],
        ];
        $payload = json_encode([
            'demo' => true,
            'response_id' => $responseId,
            'status' => 'SUCCESS',
            'sequence_uuid' => $sequenceUuid,
            'features' => [[
                'class_code' => 'demo-stop-sign',
                'geometry' => $geometry,
            ]],
        ], JSON_THROW_ON_ERROR);

        $connection->transaction(function () use ($connection, $geometry, $payload, $responseId, $sequenceUuid, $timestamp): void {
            $connection->table('ai_prediction_callback_receipts')->updateOrInsert(
                ['id' => self::RECEIPT_ID],
                [
                    'response_id' => $responseId,
                    'response_status' => 'SUCCESS',
                    'payload_hash' => hash('sha256', $payload),
                    'fingerprint' => hash('sha256', 'mapilio-local-demo-receipt-v1'),
                    'encrypted_payload' => Crypt::encryptString($payload),
                    'result_feature_count' => 1,
                    'processing_status' => 'processed',
                    'processing_error' => null,
                    'received_at' => $timestamp,
                    'validated_at' => $timestamp,
                    'processed_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
            );

            $connection->table('ai_detection_features')->updateOrInsert(
                ['id' => self::FEATURE_ID],
                [
                    'callback_receipt_id' => self::RECEIPT_ID,
                    'response_id' => $responseId,
                    'sequence_uuid' => $sequenceUuid,
                    'created_by_id' => null,
                    'organization_key' => 'demo-organization',
                    'project_key' => 'demo-project',
                    'source_index' => 0,
                    'class_code' => 'demo-stop-sign',
                    'confidence' => 0.98,
                    'longitude' => 29.0255,
                    'latitude' => 40.9911,
                    'geometry' => json_encode($geometry, JSON_THROW_ON_ERROR),
                    'width' => 0.75,
                    'height' => 0.75,
                    'area' => 0.5625,
                    'verified' => true,
                    'attributes' => json_encode([
                        'demo' => true,
                        'color' => 'red',
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
            );
        });
    }
}

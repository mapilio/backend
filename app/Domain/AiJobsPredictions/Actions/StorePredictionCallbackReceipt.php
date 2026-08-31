<?php

namespace App\Domain\AiJobsPredictions\Actions;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;

class StorePredictionCallbackReceipt
{
    /**
     * @return array{id: int, duplicate: bool}
     */
    public function store(string $rawBody, string $nonce, int $signedAt, string $payloadHash): array
    {
        $payload = $this->payload($rawBody);
        $responseId = trim((string) ($payload['id'] ?? ''));
        $status = strtoupper(trim((string) ($payload['status'] ?? '')));
        $acceptedStatuses = config('mapilio.ai_callback.accepted_statuses', ['SUCCESS', 'ERROR']);

        if ($responseId === '' || strlen($responseId) > 255) {
            throw new PredictionCallbackException('Invalid callback payload.', 422);
        }

        if (! is_array($acceptedStatuses) || ! in_array($status, $acceptedStatuses, true)) {
            throw new PredictionCallbackException('Invalid callback payload.', 422);
        }

        if ($status === 'SUCCESS' && ! is_array($payload['result'] ?? null)) {
            throw new PredictionCallbackException('Invalid callback payload.', 422);
        }

        $features = data_get($payload, 'result.features', []);

        if (
            ! is_array($features)
            || count($features) > (int) config('mapilio.ai_callback.max_features', 100_000)
        ) {
            throw new PredictionCallbackException('Invalid callback payload.', 422);
        }

        $fingerprint = hash('sha256', "{$responseId}\0{$status}\0{$payloadHash}");
        $connection = DB::connection();

        return $connection->transaction(function () use (
            $connection,
            $features,
            $rawBody,
            $nonce,
            $signedAt,
            $payloadHash,
            $fingerprint,
            $responseId,
            $status,
        ): array {
            $this->lockResponseStream($connection, $responseId);

            $nonceInserted = $connection->table('ai_prediction_callback_nonces')->insertOrIgnore([
                'nonce' => $nonce,
                'signed_at' => date('Y-m-d H:i:s', $signedAt),
                'expires_at' => now()->addSeconds((int) config('mapilio.ai_callback.nonce_retention', 86400)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($nonceInserted === 0) {
                throw new PredictionCallbackException('Callback replay rejected.', 409);
            }

            $receiptInserted = $connection->table('ai_prediction_callback_receipts')->insertOrIgnore([
                'response_id' => $responseId,
                'response_status' => $status,
                'payload_hash' => $payloadHash,
                'fingerprint' => $fingerprint,
                'encrypted_payload' => Crypt::encryptString($rawBody),
                'result_feature_count' => count($features),
                'processing_status' => 'received',
                'processing_error' => null,
                'received_at' => now(),
                'validated_at' => null,
                'processed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $receiptId = $connection->table('ai_prediction_callback_receipts')
                ->where('fingerprint', $fingerprint)
                ->value('id');

            if ($receiptId === null) {
                throw new PredictionCallbackException('Callback receipt could not be stored.', 500);
            }

            $this->attachNonce($connection, $nonce, (int) $receiptId);

            return [
                'id' => (int) $receiptId,
                'duplicate' => $receiptInserted === 0,
            ];
        });
    }

    private function lockResponseStream(ConnectionInterface $connection, string $responseId): void
    {
        $connection->table('ai_prediction_callback_receipts')
            ->where('response_id', $responseId)
            ->orderBy('id')
            ->lockForUpdate()
            ->first(['id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $rawBody): array
    {
        try {
            $payload = json_decode($rawBody, true, depth: 64, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PredictionCallbackException('Invalid callback payload.', 422, $exception);
        }

        if (! is_array($payload)) {
            throw new PredictionCallbackException('Invalid callback payload.', 422);
        }

        return $payload;
    }

    private function attachNonce(ConnectionInterface $connection, string $nonce, int $receiptId): void
    {
        $connection->table('ai_prediction_callback_nonces')
            ->where('nonce', $nonce)
            ->update([
                'callback_receipt_id' => $receiptId,
                'updated_at' => now(),
            ]);
    }
}

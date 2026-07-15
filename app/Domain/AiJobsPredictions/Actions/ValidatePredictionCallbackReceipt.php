<?php

namespace App\Domain\AiJobsPredictions\Actions;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ValidatePredictionCallbackReceipt
{
    public function validate(int $receiptId): bool
    {
        $receipt = DB::table('ai_prediction_callback_receipts')->find($receiptId);

        if ($receipt === null || $receipt->processing_status !== 'received') {
            return false;
        }

        try {
            $rawBody = Crypt::decryptString($receipt->encrypted_payload);
            $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);

            if (
                hash('sha256', $rawBody) !== $receipt->payload_hash
                || ! is_array($payload)
                || (string) ($payload['id'] ?? '') !== $receipt->response_id
                || strtoupper((string) ($payload['status'] ?? '')) !== $receipt->response_status
            ) {
                throw new PredictionCallbackException('Callback receipt integrity validation failed.');
            }

            $updated = DB::table('ai_prediction_callback_receipts')
                ->where('id', $receiptId)
                ->where('processing_status', 'received')
                ->update([
                    'processing_status' => 'validated',
                    'processing_error' => null,
                    'validated_at' => now(),
                    'updated_at' => now(),
                ]);

            return $updated === 1;
        } catch (Throwable $exception) {
            DB::table('ai_prediction_callback_receipts')
                ->where('id', $receiptId)
                ->update([
                    'processing_status' => 'error',
                    'processing_error' => Str::limit($exception->getMessage(), 1000, ''),
                    'updated_at' => now(),
                ]);

            throw $exception;
        }
    }
}

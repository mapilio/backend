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

        if (! is_object($receipt)) {
            return false;
        }

        try {
            $processingStatus = AiPredictionCallbackReceiptRow::processingStatusFromDatabaseRow($receipt);

            if ($processingStatus === 'validated') {
                return true;
            }

            if ($processingStatus !== 'received') {
                return false;
            }

            $receipt = AiPredictionCallbackReceiptRow::fromDatabaseRow($receipt);

            $rawBody = Crypt::decryptString($receipt->encryptedPayload);
            $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);

            if (
                ! hash_equals($receipt->payloadHash, hash('sha256', $rawBody))
                || ! is_array($payload)
                || (string) ($payload['id'] ?? '') !== $receipt->responseId
                || strtoupper((string) ($payload['status'] ?? '')) !== $receipt->responseStatus
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
                ->where('processing_status', 'received')
                ->update([
                    'processing_status' => 'error',
                    'processing_error' => Str::limit($exception->getMessage(), 1000, ''),
                    'updated_at' => now(),
                ]);

            throw $exception;
        }
    }
}

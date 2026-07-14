<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Domain\AiJobsPredictions\Actions\PredictionCallbackException;
use App\Domain\AiJobsPredictions\Actions\StorePredictionCallbackReceipt;
use App\Http\Controllers\Controller;
use App\Jobs\ValidatePredictionCallbackReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PredictionCallbackController extends Controller
{
    public function __invoke(Request $request, StorePredictionCallbackReceipt $receipts): JsonResponse
    {
        try {
            $receipt = $receipts->store(
                rawBody: $request->getContent(),
                nonce: (string) $request->attributes->get('ai_callback_nonce'),
                signedAt: (int) $request->attributes->get('ai_callback_timestamp'),
                payloadHash: (string) $request->attributes->get('ai_callback_payload_hash'),
            );
        } catch (PredictionCallbackException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->getCode() ?: 400);
        }

        if (! $receipt['duplicate']) {
            ValidatePredictionCallbackReceipt::dispatch($receipt['id'])
                ->onQueue((string) config('mapilio.ai_callback.queue', 'ai-callbacks'));
        }

        if ($request->routeIs('webhook.legacy.prediction-response')) {
            return response()->json(['status' => true]);
        }

        return response()->json([
            'status' => true,
            'receipt_id' => $receipt['id'],
            'duplicate' => $receipt['duplicate'],
        ], $receipt['duplicate'] ? 200 : 202);
    }
}

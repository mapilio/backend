<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyAiPredictionCallback
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('mapilio.ai_callback.enabled')) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        $body = $request->getContent();
        $maxBytes = (int) config('mapilio.ai_callback.max_payload_bytes', 5_242_880);

        if ((int) $request->header('Content-Length', 0) > $maxBytes || strlen($body) > $maxBytes) {
            return response()->json(['message' => 'Payload Too Large'], 413);
        }

        $secret = (string) config('mapilio.ai_callback.signing_secret');
        $timestamp = (string) $request->header('X-Mapilio-Timestamp', '');
        $nonce = (string) $request->header('X-Mapilio-Nonce', '');
        $signature = (string) $request->header('X-Mapilio-Signature', '');

        if (
            strlen($secret) < 32
            || ! ctype_digit($timestamp)
            || ! preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $nonce)
            || ! $this->freshTimestamp((int) $timestamp)
            || ! $this->validSignature($secret, $timestamp, $nonce, $body, $signature)
        ) {
            return $this->unauthorized();
        }

        $request->attributes->set('ai_callback_timestamp', (int) $timestamp);
        $request->attributes->set('ai_callback_nonce', $nonce);
        $request->attributes->set('ai_callback_payload_hash', hash('sha256', $body));

        return $next($request);
    }

    private function freshTimestamp(int $timestamp): bool
    {
        return abs(now()->timestamp - $timestamp)
            <= (int) config('mapilio.ai_callback.timestamp_tolerance', 300);
    }

    private function validSignature(
        string $secret,
        string $timestamp,
        string $nonce,
        string $body,
        string $signature,
    ): bool {
        $signature = str_starts_with($signature, 'sha256=')
            ? substr($signature, 7)
            : $signature;

        if (! preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$nonce}.{$body}", $secret);

        return hash_equals($expected, strtolower($signature));
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['message' => 'Unauthorized'], 401);
    }
}

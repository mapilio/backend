<?php

namespace App\Http\Controllers\Api\V1\Web;

use App\Domain\IdentityAccess\LegacyMobileAuth;
use App\Domain\IdentityAccess\LegacyMobileAuthException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class WebTokenController extends Controller
{
    public function __invoke(Request $request, LegacyMobileAuth $auth): JsonResponse
    {
        $validated = $request->validate([
            'grant_type' => ['required', 'string', 'in:password,refresh_token'],
            'email' => ['nullable', 'required_if:grant_type,password', 'string', 'max:254'],
            'password' => ['nullable', 'required_if:grant_type,password', 'string', 'max:1024'],
            'refresh_token' => ['nullable', 'required_if:grant_type,refresh_token', 'string', 'max:4096'],
        ]);

        try {
            return response()->json($auth->issueFirstPartyToken($validated));
        } catch (LegacyMobileAuthException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->legacyMessage(),
            ], $exception->getCode() ?: 400);
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication service is temporarily unavailable.',
            ], 503);
        }
    }
}

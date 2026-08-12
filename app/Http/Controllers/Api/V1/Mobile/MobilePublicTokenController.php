<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Domain\IdentityAccess\LegacyMobileAuth;
use App\Domain\IdentityAccess\LegacyMobileAuthException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Token endpoint for the mobile app as a public client.
 *
 * A shipped mobile bundle cannot keep a client secret: anything compiled into
 * the app is readable by anyone who installs it, so a secret sent from the app
 * authenticates nothing. This endpoint therefore issues first-party tokens from
 * the user's own credentials alone.
 *
 * It is additive. The legacy client-credential routes are unchanged, so already
 * shipped builds keep working while new builds migrate at their own pace.
 */
class MobilePublicTokenController extends Controller
{
    public function __invoke(Request $request, LegacyMobileAuth $auth): JsonResponse
    {
        $validated = $request->validate([
            'grant_type' => ['required', 'string', 'in:password,refresh_token'],
            'email' => ['nullable', 'string', 'max:254'],
            'username' => ['nullable', 'string', 'max:254'],
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

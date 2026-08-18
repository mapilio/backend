<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Domain\IdentityAccess\LegacyMobileAuth;
use App\Domain\IdentityAccess\LegacyMobileAuthException;
use App\Domain\IdentityAccess\SocialTokenBridge;
use App\Domain\IdentityAccess\SocialTokenBridgeException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileSocialTokenController extends Controller
{
    public function __invoke(Request $request, SocialTokenBridge $bridge, LegacyMobileAuth $auth): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:google,facebook,apple,openstreetmap'],
            'token' => ['required', 'string', 'max:8192'],
        ]);

        try {
            $userId = $bridge->authenticate($validated['provider'], $validated['token']);

            return response()->json($auth->issueTokenForLegacyUser($userId));
        } catch (SocialTokenBridgeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->status() === 401
                    ? 'Invalid social login credentials.'
                    : 'Social login is temporarily unavailable.',
            ], $exception->status());
        } catch (LegacyMobileAuthException) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid social login credentials.',
            ], 401);
        } catch (\Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'Social login is temporarily unavailable.',
            ], 503);
        }
    }
}

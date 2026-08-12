<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Domain\IdentityAccess\LegacyMobileAuth;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Server-side logout.
 *
 * Until now a client could only forget its tokens locally, which left a stolen
 * or leaked credential valid for the rest of its lifetime. This records both
 * tokens on the revocation denylist so they stop being accepted.
 *
 * The refresh token is revoked alongside the access token when supplied.
 * Revoking the access token alone achieves very little, because the refresh
 * token would immediately mint a replacement.
 */
class MobileLogoutController extends Controller
{
    public function __invoke(Request $request, LegacyMobileAuth $auth): JsonResponse
    {
        $validated = $request->validate([
            'refresh_token' => ['nullable', 'string', 'max:4096'],
        ]);

        $header = (string) $request->header('Authorization', '');
        $accessToken = Str::startsWith($header, 'Bearer ')
            ? trim(Str::after($header, 'Bearer '))
            : '';

        if ($accessToken === '' || $auth->userFromBearer($header) === null) {
            return response()->json([
                'success' => false,
                'message' => ['Unauthorized'],
            ], 401);
        }

        $auth->revokeToken($accessToken, 'access', 'logout');

        $refreshToken = (string) ($validated['refresh_token'] ?? '');

        if ($refreshToken !== '') {
            $auth->revokeToken($refreshToken, 'refresh', 'logout');
        }

        return response()->json([
            'success' => true,
            'message' => ['Signed out.'],
        ]);
    }
}

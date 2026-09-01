<?php

namespace App\Http\Controllers\Legacy\Identity;

use App\Domain\IdentityAccess\LegacyMobileAuth;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OneSignalIdentityVerificationController extends Controller
{
    public function __invoke(Request $request, LegacyMobileAuth $auth): JsonResponse
    {
        $user = $auth->userFromBearer($request->header('Authorization'));
        $email = (string) data_get($request->all(), 'options.parameters.email', '');

        if ($user === null || $email === '' || ! hash_equals((string) $user->email, $email)) {
            return response()->json([
                'success' => false,
                'message' => ['Verification failed.'],
            ], (int) $request->route('identity_verification_failure_status', 500));
        }

        $key = (string) (config('mapilio.mobile_auth.onesignal_rest_api_key')
            ?: config('mapilio.mobile_auth.signing_key'));

        return response()->json([
            'status' => true,
            'response' => [
                'hash' => hash_hmac('sha256', $email, $key),
            ],
        ]);
    }
}

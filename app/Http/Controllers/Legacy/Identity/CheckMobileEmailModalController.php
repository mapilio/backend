<?php

namespace App\Http\Controllers\Legacy\Identity;

use App\Domain\IdentityAccess\Actions\CheckMobileEmailModal;
use App\Domain\IdentityAccess\LegacyMobileAuth;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckMobileEmailModalController extends Controller
{
    public function __invoke(
        Request $request,
        LegacyMobileAuth $auth,
        CheckMobileEmailModal $modal,
    ): JsonResponse {
        $user = $auth->userFromBearer($request->header('Authorization'));

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return response()->json($modal->check($user));
    }
}

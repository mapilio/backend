<?php

namespace App\Http\Controllers\Legacy\Identity;

use App\Domain\IdentityAccess\LegacyMobileAuth;
use App\Domain\IdentityAccess\Queries\MobileProfileQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileProfileController extends Controller
{
    public function __invoke(
        Request $request,
        LegacyMobileAuth $auth,
        MobileProfileQuery $query,
    ): JsonResponse {
        $user = $auth->userFromBearer($request->header('Authorization'));

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $profile = $query->get((int) $user->id);

        return response()->json([
            'data' => $profile === null ? null : [$profile],
        ]);
    }
}

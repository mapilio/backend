<?php

namespace App\Http\Controllers\Legacy\Projects;

use App\Domain\IdentityAccess\LegacyMobileAuth;
use App\Domain\Projects\Queries\MobileUserJobsQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileUserJobsController extends Controller
{
    public function __invoke(
        Request $request,
        LegacyMobileAuth $auth,
        MobileUserJobsQuery $query,
    ): JsonResponse {
        $user = $auth->userFromBearer($request->header('Authorization'));

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return response()->json($query->get($user));
    }
}

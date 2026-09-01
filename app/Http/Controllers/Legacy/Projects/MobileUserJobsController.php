<?php

namespace App\Http\Controllers\Legacy\Projects;

use App\Domain\Projects\Queries\MobileUserJobsQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileUserJobsController extends Controller
{
    public function __invoke(
        Request $request,
        MobileUserJobsQuery $query,
    ): JsonResponse {
        $user = $request->attributes->get('mapilio_mobile_user');

        if (! is_object($user) || ! isset($user->id)) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $userId = filter_var($user->id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($userId === false) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return response()->json($query->get($user));
    }
}

<?php

namespace App\Http\Controllers\Legacy\Identity;

use App\Domain\IdentityAccess\Queries\PublicUserProfileQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicUserProfileController extends Controller
{
    public function __invoke(Request $request, PublicUserProfileQuery $query): JsonResponse
    {
        $userId = data_get($request->query(), 'options.parameters.id');

        if (! is_numeric($userId) || (int) $userId <= 0) {
            return response()->json([
                'message' => 'Not Found',
            ], 404);
        }

        return response()->json($query->byId((int) $userId, $request));
    }
}

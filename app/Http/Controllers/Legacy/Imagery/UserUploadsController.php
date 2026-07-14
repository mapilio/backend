<?php

namespace App\Http\Controllers\Legacy\Imagery;

use App\Domain\ImagerySequences\Queries\UserUploadsQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserUploadsController extends Controller
{
    public function __invoke(Request $request, UserUploadsQuery $query): JsonResponse
    {
        $userId = data_get($request->query(), 'options.parameters.user_id');

        if (! is_numeric($userId) || (int) $userId <= 0) {
            return response()->json([
                'success' => false,
                'message' => [
                    'user_id' => ['The user_id field is required.'],
                ],
            ], 400);
        }

        return response()->json($query->get((int) $userId, $request));
    }
}

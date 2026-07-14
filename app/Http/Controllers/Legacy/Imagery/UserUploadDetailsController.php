<?php

namespace App\Http\Controllers\Legacy\Imagery;

use App\Domain\ImagerySequences\Queries\UserUploadDetailsQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserUploadDetailsController extends Controller
{
    public function __invoke(Request $request, UserUploadDetailsQuery $query): JsonResponse
    {
        $userId = data_get($request->query(), 'options.parameters.user_id');
        $groupKey = data_get($request->query(), 'options.parameters.group_key');

        if (! is_numeric($userId) || (int) $userId <= 0) {
            return $this->missingParameter('user_id');
        }

        if ($groupKey === null || $groupKey === '') {
            return $this->missingParameter('group_key');
        }

        return response()->json($query->get((int) $userId, (string) $groupKey, $request));
    }

    private function missingParameter(string $parameter): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => ["'{$parameter}' is required!"],
            'error_code' => 400,
        ], 400);
    }
}

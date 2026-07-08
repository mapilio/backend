<?php

namespace App\Http\Controllers\Legacy\Geo;

use App\Domain\GeoPublishing\Queries\UploadedRoadsByGroupQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UploadedRoadsByGroupController extends Controller
{
    public function __invoke(Request $request, UploadedRoadsByGroupQuery $query): JsonResponse
    {
        $groupKey = $request->query('group_key');

        if ($groupKey === null || $groupKey === '') {
            return response()->json([
                'success' => false,
                'message' => ["'group_key' is required!"],
                'error_code' => 400,
            ], 400);
        }

        $rows = $query->get((string) $groupKey);

        return response()->json([
            'data' => $rows === [] ? null : $rows,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Legacy\Imagery;

use App\Domain\ImagerySequences\Queries\SequenceDetailQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SequenceDetailController extends Controller
{
    public function __invoke(Request $request, SequenceDetailQuery $query): JsonResponse
    {
        $sequenceUuid = $request->query('sequence_uuid');

        if ($sequenceUuid === null || $sequenceUuid === '') {
            return response()->json([
                'success' => false,
                'message' => ["'sequence_uuid' is required!"],
                'error_code' => 400,
            ], 400);
        }

        $rows = $query->get((string) $sequenceUuid);

        return response()->json([
            'data' => $rows === [] ? null : $rows,
        ]);
    }
}

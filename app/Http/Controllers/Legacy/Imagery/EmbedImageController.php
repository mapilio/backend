<?php

namespace App\Http\Controllers\Legacy\Imagery;

use App\Domain\ImagerySequences\Queries\EmbedImageQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class EmbedImageController extends Controller
{
    public function __invoke(string $sequenceUuid, EmbedImageQuery $query): JsonResponse
    {
        $payload = $query->get($sequenceUuid);

        if ($payload === null) {
            return response()->json([
                'success' => false,
                'message' => ['Not Found'],
                'error_code' => 404,
            ], 404);
        }

        return response()->json([
            'data' => $payload,
        ]);
    }
}

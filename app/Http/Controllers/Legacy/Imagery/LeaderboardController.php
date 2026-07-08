<?php

namespace App\Http\Controllers\Legacy\Imagery;

use App\Domain\ImagerySequences\Queries\LeaderboardQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class LeaderboardController extends Controller
{
    public function __invoke(Request $request, LeaderboardQuery $query): JsonResponse
    {
        try {
            $leaderboard = $query->get(
                $request->query(),
                null,
                (int) $request->route('score_version', LeaderboardQuery::SCORE_VERSION_SEQUENCE),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->legacyValidationError($exception->getMessage());
        }

        return response()->json([
            'data' => [
                'leaderboard' => $leaderboard,
            ],
        ]);
    }

    private function legacyValidationError(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => [$message],
            'error_code' => 400,
        ], 400);
    }
}

<?php

namespace App\Http\Controllers\Legacy\Gamification;

use App\Domain\Gamification\Queries\GamificationBadgesQuery;
use App\Domain\ImagerySequences\Queries\LeaderboardQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GamificationBadgesController extends Controller
{
    public function __invoke(
        Request $request,
        int $userId,
        GamificationBadgesQuery $query,
        LeaderboardQuery $leaderboardQuery,
    ): JsonResponse {
        return response()->json($query->get($request, $userId, $leaderboardQuery));
    }
}

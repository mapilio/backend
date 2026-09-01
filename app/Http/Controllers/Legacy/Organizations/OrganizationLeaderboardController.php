<?php

namespace App\Http\Controllers\Legacy\Organizations;

use App\Domain\Organizations\Queries\OrganizationLeaderboardQuery;
use App\Http\Controllers\Controller;
use App\Support\Cache\PublicAggregateCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class OrganizationLeaderboardController extends Controller
{
    public function __invoke(
        Request $request,
        OrganizationLeaderboardQuery $query,
        PublicAggregateCache $cache,
    ): JsonResponse {
        $scoreVersion = (int) $request->route('score_version', OrganizationLeaderboardQuery::SCORE_VERSION_SEQUENCE);

        try {
            $leaderboard = $cache->organizationLeaderboard(
                $scoreVersion,
                fn (): array => $query->get($scoreVersion),
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

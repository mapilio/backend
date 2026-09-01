<?php

namespace App\Http\Controllers\Legacy\Imagery;

use App\Domain\ImagerySequences\Queries\LeaderboardQuery;
use App\Http\Controllers\Controller;
use App\Support\Cache\PublicAggregateCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class LeaderboardController extends Controller
{
    public function __invoke(Request $request, LeaderboardQuery $query, PublicAggregateCache $cache): JsonResponse
    {
        $filters = $request->query();
        $scoreVersion = (int) $request->route('score_version', LeaderboardQuery::SCORE_VERSION_SEQUENCE);

        try {
            $leaderboard = $this->cacheable($filters, $scoreVersion)
                ? $cache->leaderboard($scoreVersion, fn (): array => $query->get($filters, null, $scoreVersion))
                : $query->get($filters, null, $scoreVersion);
        } catch (InvalidArgumentException $exception) {
            return $this->legacyValidationError($exception->getMessage());
        }

        return response()->json([
            'data' => [
                'leaderboard' => $leaderboard,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function cacheable(array $filters, int $scoreVersion): bool
    {
        return in_array($scoreVersion, [
            LeaderboardQuery::SCORE_VERSION_SEQUENCE,
            LeaderboardQuery::SCORE_VERSION_IMAGE,
        ], true)
            && ! array_key_exists('user_id', $filters)
            && ! array_key_exists('start_at', $filters)
            && ! array_key_exists('finish_at', $filters);
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

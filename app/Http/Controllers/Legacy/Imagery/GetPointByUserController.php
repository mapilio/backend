<?php

namespace App\Http\Controllers\Legacy\Imagery;

use App\Domain\ImagerySequences\Queries\LeaderboardQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetPointByUserController extends Controller
{
    public function __invoke(Request $request, LeaderboardQuery $query): JsonResponse
    {
        $userId = $request->query('user_id');

        if ($userId === null || $userId === '') {
            return $this->legacyValidationError("'user_id' is required!");
        }

        if (filter_var($userId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return $this->legacyValidationError("'user_id' must be an integer!");
        }

        return response()->json([
            'data' => [
                [
                    'leaderboard' => $query->forUser((int) $userId),
                ],
            ],
            'pagination' => $this->pagination($request),
        ]);
    }

    /**
     * @return array<string, int|string|array<int, array{url: string|null, label: string, active: bool}>|null>
     */
    private function pagination(Request $request): array
    {
        $path = '/'.$request->path();

        return [
            'current_page' => 1,
            'first_page_url' => $this->pageUrl($path, $request, 1),
            'from' => 1,
            'last_page' => 1,
            'last_page_url' => $this->pageUrl($path, $request, 1),
            'links' => [
                [
                    'url' => null,
                    'label' => '&laquo; Previous',
                    'active' => false,
                ],
                [
                    'url' => $this->pageUrl($path, $request, 1),
                    'label' => '1',
                    'active' => true,
                ],
                [
                    'url' => null,
                    'label' => 'Next &raquo;',
                    'active' => false,
                ],
            ],
            'next_page_url' => null,
            'path' => $path,
            'per_page' => 15,
            'prev_page_url' => null,
            'to' => 1,
            'total' => 0,
        ];
    }

    private function pageUrl(string $path, Request $request, int $page): string
    {
        $query = $request->query();
        $query['page'] = $page;

        return $path.'?'.http_build_query($query);
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

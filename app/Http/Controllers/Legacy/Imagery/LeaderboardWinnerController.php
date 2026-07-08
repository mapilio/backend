<?php

namespace App\Http\Controllers\Legacy\Imagery;

use App\Domain\ImagerySequences\Queries\LeaderboardWinnerQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaderboardWinnerController extends Controller
{
    public function __invoke(Request $request, LeaderboardWinnerQuery $query): JsonResponse
    {
        return response()->json([
            'data' => $query->get($request->query()),
        ]);
    }
}

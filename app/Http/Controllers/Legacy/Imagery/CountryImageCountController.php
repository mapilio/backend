<?php

namespace App\Http\Controllers\Legacy\Imagery;

use App\Domain\ImagerySequences\Queries\CountryImageCountQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class CountryImageCountController extends Controller
{
    public function __invoke(CountryImageCountQuery $query): JsonResponse
    {
        return response()->json([
            'data' => $query->get()->values(),
        ]);
    }
}

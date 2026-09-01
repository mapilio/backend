<?php

namespace App\Http\Controllers\Legacy\Imagery;

use App\Domain\ImagerySequences\Queries\CountryImageCountQuery;
use App\Http\Controllers\Controller;
use App\Support\Cache\PublicAggregateCache;
use Illuminate\Http\JsonResponse;

class CountryImageCountController extends Controller
{
    public function __invoke(CountryImageCountQuery $query, PublicAggregateCache $cache): JsonResponse
    {
        return response()->json([
            'data' => $cache->countryImageCounts(fn (): array => $query->get()->values()->all()),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Legacy\PublicContent;

use App\Domain\PublicContent\Queries\CatalogQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function __invoke(Request $request, CatalogQuery $query): JsonResponse
    {
        return response()->json($query->get($request));
    }
}

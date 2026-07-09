<?php

namespace App\Http\Controllers\Legacy\Inventory;

use App\Domain\InventoryFeatures\Queries\TypeMetadataQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TypeMetadataController extends Controller
{
    public function types(Request $request, TypeMetadataQuery $query): JsonResponse
    {
        return response()->json($query->types($request));
    }

    public function groups(Request $request, TypeMetadataQuery $query): JsonResponse
    {
        return response()->json($query->groups($request));
    }
}

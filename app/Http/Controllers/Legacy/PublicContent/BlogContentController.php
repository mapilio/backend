<?php

namespace App\Http\Controllers\Legacy\PublicContent;

use App\Domain\PublicContent\Queries\BlogContentQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlogContentController extends Controller
{
    public function categories(Request $request, BlogContentQuery $query): JsonResponse
    {
        return response()->json($query->categories($request));
    }

    public function blogs(Request $request, BlogContentQuery $query): JsonResponse
    {
        return response()->json($query->blogs($request));
    }

    public function detail(Request $request, BlogContentQuery $query, string $slug): JsonResponse
    {
        return response()->json($query->detail($request, $slug));
    }
}

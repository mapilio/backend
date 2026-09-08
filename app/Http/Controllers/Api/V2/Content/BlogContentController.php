<?php

namespace App\Http\Controllers\Api\V2\Content;

use App\Domain\PublicContent\Queries\BlogContentQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlogContentController extends Controller
{
    private const PAGINATION_SIZE = 100;

    public function blogs(Request $request, BlogContentQuery $query): JsonResponse
    {
        return response()->json($this->publicResponse(
            $query->blogs($request, '/api/v2/content/blogs', self::PAGINATION_SIZE),
        ));
    }

    public function detail(Request $request, BlogContentQuery $query, string $slug): JsonResponse
    {
        return response()->json($this->publicResponse(
            $query->detail($request, $slug, '/api/v2/content/blogs/'.rawurlencode($slug)),
        ));
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function publicResponse(array $response): array
    {
        if (! is_array($response['data'] ?? null)) {
            return $response;
        }

        $response['data'] = array_map(function (array $row): array {
            if (! is_array($row['author_detail'] ?? null)) {
                return $row;
            }

            $row['author_detail'] = array_map(
                static fn (array $author): array => [
                    'username' => $author['username'] ?? null,
                    'user_profile_photo' => $author['user_profile_photo'] ?? null,
                ],
                $row['author_detail'],
            );

            return $row;
        }, $response['data']);

        return $response;
    }
}

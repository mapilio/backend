<?php

namespace App\Http\Controllers\Legacy\Geo;

use App\Domain\GeoPublishing\Queries\UploadedRoadsByGroupQuery;
use App\Http\Controllers\Controller;
use App\Support\Http\BoundedRead\PayloadTooLargeException;
use App\Support\Http\BoundedRead\PublicReadBounds;
use App\Support\Http\Pagination\InvalidPaginationParametersException;
use App\Support\Http\Pagination\PaginationParameters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UploadedRoadsByGroupController extends Controller
{
    public function __invoke(Request $request, UploadedRoadsByGroupQuery $query): JsonResponse
    {
        $groupKey = $request->query('group_key');

        if ($groupKey === null || $groupKey === '') {
            return response()->json([
                'success' => false,
                'message' => ["'group_key' is required!"],
                'error_code' => 400,
            ], 400);
        }

        try {
            if ((bool) $request->route('pagination_enabled', false)) {
                $pagination = PaginationParameters::fromRequest(
                    $request,
                    PublicReadBounds::maxRows(PublicReadBounds::ROADS),
                );

                if ($pagination !== null) {
                    $page = $query->getPage((string) $groupKey, $pagination);

                    return response()->json([
                        'data' => $page['rows'] === [] ? null : $page['rows'],
                        'pagination' => [
                            'current_page' => $pagination->page,
                            'per_page' => $pagination->perPage,
                            'has_more' => $page['has_more'],
                        ],
                    ]);
                }
            }

            $rows = $query->get((string) $groupKey);
        } catch (InvalidPaginationParametersException) {
            return $this->invalidPaginationResponse();
        } catch (PayloadTooLargeException) {
            return $this->payloadTooLargeResponse();
        }

        return response()->json([
            'data' => $rows === [] ? null : $rows,
        ]);
    }

    private function invalidPaginationResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => ["'page' and 'per_page' must be positive integers within the supported range."],
            'error_code' => 422,
        ], 422);
    }

    private function payloadTooLargeResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => ['Payload Too Large'],
            'error_code' => 413,
        ], 413);
    }
}

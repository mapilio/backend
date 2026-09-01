<?php

namespace App\Http\Controllers\Legacy\Imagery;

use App\Domain\ImagerySequences\Queries\EmbedImageQuery;
use App\Http\Controllers\Controller;
use App\Support\Http\BoundedRead\PayloadTooLargeException;
use App\Support\Http\BoundedRead\PublicReadBounds;
use App\Support\Http\Pagination\InvalidPaginationParametersException;
use App\Support\Http\Pagination\PaginationParameters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmbedImageController extends Controller
{
    public function __invoke(Request $request, string $sequenceUuid, EmbedImageQuery $query): JsonResponse
    {
        try {
            if ((bool) $request->route('pagination_enabled', false)) {
                $pagination = PaginationParameters::fromRequest(
                    $request,
                    PublicReadBounds::maxRows(PublicReadBounds::EMBED),
                );

                if ($pagination !== null) {
                    $page = $query->getPage($sequenceUuid, $pagination);

                    if ($page['payload'] === null) {
                        return $this->notFoundResponse();
                    }

                    return response()->json([
                        'data' => $page['payload'],
                        'pagination' => [
                            'current_page' => $pagination->page,
                            'per_page' => $pagination->perPage,
                            'has_more' => $page['has_more'],
                        ],
                    ]);
                }
            }

            $payload = $query->get($sequenceUuid);
        } catch (InvalidPaginationParametersException) {
            return $this->invalidPaginationResponse();
        } catch (PayloadTooLargeException) {
            return $this->payloadTooLargeResponse();
        }

        if ($payload === null) {
            return $this->notFoundResponse();
        }

        return response()->json([
            'data' => $payload,
        ]);
    }

    private function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => ['Not Found'],
            'error_code' => 404,
        ], 404);
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

<?php

namespace App\Http\Controllers\Legacy\Imagery;

use App\Domain\ImagerySequences\Queries\SequenceDetailQuery;
use App\Http\Controllers\Controller;
use App\Support\Http\BoundedRead\PayloadTooLargeException;
use App\Support\Http\BoundedRead\PublicReadBounds;
use App\Support\Http\Pagination\InvalidPaginationParametersException;
use App\Support\Http\Pagination\PaginationParameters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SequenceDetailController extends Controller
{
    public function __invoke(Request $request, SequenceDetailQuery $query): JsonResponse
    {
        $sequenceUuid = $request->query('sequence_uuid');

        if ($sequenceUuid === null || $sequenceUuid === '') {
            return response()->json([
                'success' => false,
                'message' => ["'sequence_uuid' is required!"],
                'error_code' => 400,
            ], 400);
        }

        try {
            if ((bool) $request->route('pagination_enabled', false)) {
                $pagination = PaginationParameters::fromRequest(
                    $request,
                    PublicReadBounds::maxRows(PublicReadBounds::SEQUENCE),
                );

                if ($pagination !== null) {
                    $page = $query->getPage((string) $sequenceUuid, $pagination);

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

            $rows = $query->get((string) $sequenceUuid);
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

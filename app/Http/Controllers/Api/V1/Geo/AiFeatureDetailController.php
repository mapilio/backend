<?php

namespace App\Http\Controllers\Api\V1\Geo;

use App\Domain\InventoryFeatures\Queries\AiFeatureDetailException;
use App\Domain\InventoryFeatures\Queries\AiFeatureDetailQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiFeatureDetailController extends Controller
{
    public function __invoke(Request $request, int $featureId, AiFeatureDetailQuery $features): JsonResponse
    {
        try {
            $feature = $features->find($featureId);
        } catch (AiFeatureDetailException) {
            return response()->json([
                'message' => 'AI feature detail is unavailable.',
            ], 503);
        }

        if ($feature === null) {
            return response()->json([
                'message' => 'Not Found',
            ], 404);
        }

        $response = response()->json(['data' => $feature]);
        $response->setPublic();
        $response->setMaxAge(max(0, (int) config('mapilio.ai_feature_api.cache_ttl', 60)));
        $response->headers->addCacheControlDirective(
            'stale-while-revalidate',
            max(0, (int) config('mapilio.ai_feature_api.stale_while_revalidate', 300)),
        );
        $response->setEtag(hash('sha256', (string) $response->getContent()));
        $response->isNotModified($request);

        return $response;
    }
}

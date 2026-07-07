<?php

namespace App\Http\Controllers\Api\V1\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => config('mapilio.service_name'),
            'api_version' => 'v1',
            'compatibility' => config('mapilio.legacy_api_contract'),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}

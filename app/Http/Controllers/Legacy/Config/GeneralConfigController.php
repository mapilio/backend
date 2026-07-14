<?php

namespace App\Http\Controllers\Legacy\Config;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeneralConfigController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $expectedToken = config('mapilio.mobile_config.token');

        if (is_string($expectedToken) && $expectedToken !== '') {
            $providedToken = (string) $request->query('token', '');

            if (! hash_equals($expectedToken, $providedToken)) {
                return response()->json([
                    'success' => false,
                    'message' => ['Forbidden'],
                ], 403);
            }
        }

        return response()->json([
            'config' => config('mapilio.mobile_config.general'),
        ]);
    }
}

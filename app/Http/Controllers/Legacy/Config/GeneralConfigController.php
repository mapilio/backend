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
            $providedToken = $this->providedToken($request);

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

    /**
     * Accepts the config token from a header as well as the query string.
     *
     * A token in the query string is copied into access logs, proxy logs, and
     * browser history. The header is the preferred transport; the query
     * parameter stays supported so already shipped clients keep working.
     */
    private function providedToken(Request $request): string
    {
        $header = $request->header('X-Mapilio-Config-Token');

        if (is_string($header) && $header !== '') {
            return $header;
        }

        return (string) $request->query('token', '');
    }
}

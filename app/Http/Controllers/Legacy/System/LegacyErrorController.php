<?php

namespace App\Http\Controllers\Legacy\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class LegacyErrorController extends Controller
{
    private const ERROR_NAMES = [
        '400' => 'Bad Request',
        '401' => 'Unauthorized',
        '402' => 'Payment Required',
        '403' => 'Forbidden',
        '404' => 'Not Found',
        '405' => 'Method Not Allowed',
        '406' => 'Not Acceptable',
        '408' => 'Request Timeout',
        '409' => 'Conflict',
        '410' => 'Gone',
        '411' => 'Length Required',
        '412' => 'Precondition Failed',
        '413' => 'Payload Too Large',
        '414' => 'URI Too Long',
        '415' => 'Unsupported Media Type',
        '417' => 'Expectation Failed',
        '422' => 'Unprocessable Entity',
        '426' => 'Upgrade Required',
        '428' => 'Precondition Required',
        '429' => 'Too Many Requests',
        '500' => 'Internal Server Error',
        '501' => 'Not Implemented',
        '502' => 'Bad Gateway',
        '503' => 'Service Unavailable',
        '504' => 'Gateway Timeout',
        '505' => 'HTTP Version Not Supported',
    ];

    public function __invoke(string $code): JsonResponse
    {
        if (! ctype_digit($code) || (int) $code < 100 || (int) $code > 599) {
            return response()->json([
                'success' => false,
                'message' => ['Internal Server Error'],
                'error_code' => 500,
            ], 500);
        }

        return response()->json([
            'success' => false,
            'message' => [self::ERROR_NAMES[$code] ?? "streams::error.{$code}.name"],
            'error_code' => $code,
        ], (int) $code);
    }
}

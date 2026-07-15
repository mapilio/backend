<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public const ATTRIBUTE = 'mapilio_request_id';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid7();
        $request->attributes->set(self::ATTRIBUTE, $requestId);
        Context::add('request_id', $requestId);

        try {
            $response = $next($request);
            $response->headers->set('X-Request-ID', $requestId);

            return $response;
        } finally {
            Context::forget('request_id');
        }
    }
}

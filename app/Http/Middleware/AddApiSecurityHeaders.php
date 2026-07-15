<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddApiSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($next($request), $request);
    }

    public static function apply(Response $response, Request $request): Response
    {
        if (! self::covers($request)) {
            return $response;
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        );

        if (app()->environment('production') && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $requestId = $request->attributes->get(AssignRequestId::ATTRIBUTE);

        if (is_string($requestId) && $requestId !== '') {
            $response->headers->set('X-Request-ID', $requestId);
        }

        return $response;
    }

    private static function covers(Request $request): bool
    {
        return $request->is('api/*') || $request->is('webhook/*');
    }
}

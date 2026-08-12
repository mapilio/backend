<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Default rate limit for the API surface.
 *
 * The limiter ships in observe mode: it counts requests and logs the callers it
 * *would* have rejected without rejecting anything. That makes it safe to enable
 * in production against live consumers, and it produces the traffic evidence
 * needed to choose a ceiling before enforcement is switched on.
 *
 * Routes that already declare their own throttle are skipped, so the observed
 * data describes exactly the surface that is currently unprotected.
 */
class ThrottleApiRequests
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('mapilio.rate_limiting.enabled', false)
            || (! $request->is('api/*') && ! $request->is('webhook/*'))
            || $this->routeDeclaresOwnThrottle($request)) {
            return $next($request);
        }

        $maxAttempts = max(1, (int) config('mapilio.rate_limiting.max_attempts', 300));
        $decaySeconds = max(1, (int) config('mapilio.rate_limiting.decay_seconds', 60));
        $enforce = (bool) config('mapilio.rate_limiting.enforce', false);
        $key = $this->resolveKey($request);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $this->logger->warning('api.rate_limit', [
                'request_id' => $request->attributes->get(AssignRequestId::ATTRIBUTE),
                'method' => $request->getMethod(),
                'route' => $request->route()?->getName(),
                'path' => $this->safePath($request),
                'client' => $this->clientFingerprint($request),
                'limit' => $maxAttempts,
                'decay_seconds' => $decaySeconds,
                'retry_after' => RateLimiter::availableIn($key),
                'enforced' => $enforce,
            ]);

            if ($enforce) {
                return $this->tooManyRequests($key, $maxAttempts);
            }
        }

        RateLimiter::hit($key, $decaySeconds);

        $response = $next($request);

        if ($enforce) {
            $response->headers->add([
                'X-RateLimit-Limit' => (string) $maxAttempts,
                'X-RateLimit-Remaining' => (string) RateLimiter::remaining($key, $maxAttempts),
            ]);
        }

        return $response;
    }

    /**
     * Preserves the legacy error envelope so existing client error handling is
     * unchanged when enforcement is switched on.
     */
    private function tooManyRequests(string $key, int $maxAttempts): Response
    {
        $retryAfter = RateLimiter::availableIn($key);

        return response()->json([
            'success' => false,
            'message' => ['Too many requests.'],
            'error_code' => Response::HTTP_TOO_MANY_REQUESTS,
        ], Response::HTTP_TOO_MANY_REQUESTS, [
            'Retry-After' => (string) $retryAfter,
            'X-RateLimit-Limit' => (string) $maxAttempts,
            'X-RateLimit-Remaining' => '0',
        ]);
    }

    private function routeDeclaresOwnThrottle(Request $request): bool
    {
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'throttle')) {
                return true;
            }
        }

        return false;
    }

    private function resolveKey(Request $request): string
    {
        return 'mapilio-api|'.sha1((string) $request->ip());
    }

    /**
     * Stable, non-reversible client identifier. Operators can count distinct
     * callers and spot a heavy one without the log holding an address, matching
     * the metadata-only posture of the request log.
     */
    private function clientFingerprint(Request $request): string
    {
        return substr(sha1((string) $request->ip()), 0, 12);
    }

    private function safePath(Request $request): string
    {
        $route = $request->route();

        if ($route !== null) {
            return '/'.$route->uri();
        }

        return $request->is('webhook/*') ? '/webhook/(unmatched)' : '/api/(unmatched)';
    }
}

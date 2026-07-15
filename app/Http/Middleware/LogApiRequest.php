<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class LogApiRequest
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('mapilio.observability.api_request_logging_enabled', false)
            || (! $request->is('api/*') && ! $request->is('webhook/*'))) {
            return $next($request);
        }

        $startedAt = hrtime(true);
        $status = 500;

        try {
            $response = $next($request);
            $status = $response->getStatusCode();

            return $response;
        } catch (Throwable $exception) {
            $status = $this->exceptionStatus($exception);

            throw $exception;
        } finally {
            $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            $this->logger->log(
                $this->level($status, $durationMs),
                'api.request',
                [
                    'request_id' => $request->attributes->get(AssignRequestId::ATTRIBUTE),
                    'method' => $request->getMethod(),
                    'path' => $this->safePath($request),
                    'route' => $request->route()?->getName(),
                    'status' => $status,
                    'duration_ms' => $durationMs,
                ],
            );
        }
    }

    private function exceptionStatus(Throwable $exception): int
    {
        return match (true) {
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof HttpResponseException => $exception->getResponse()->getStatusCode(),
            $exception instanceof ValidationException => $exception->status,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException => 403,
            $exception instanceof ModelNotFoundException => 404,
            $exception instanceof TokenMismatchException => 419,
            default => 500,
        };
    }

    private function safePath(Request $request): string
    {
        $route = $request->route();

        if ($route !== null) {
            return '/'.$route->uri();
        }

        return $request->is('webhook/*') ? '/webhook/(unmatched)' : '/api/(unmatched)';
    }

    private function level(int $status, int $durationMs): string
    {
        if ($status >= 500) {
            return 'error';
        }

        if ($status === 429
            || $durationMs >= max(1, (int) config('mapilio.observability.slow_request_ms', 1000))) {
            return 'warning';
        }

        return 'info';
    }
}

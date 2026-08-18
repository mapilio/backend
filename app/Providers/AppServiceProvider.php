<?php

namespace App\Providers;

use App\Domain\DataMigration\JsonPublisher;
use App\Domain\DataMigration\PrivateJsonPublisher;
use App\Support\Queue\QueueRuntimeConfiguration;
use App\Support\Queue\QueueWorkerPoolConfiguration;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(JsonPublisher::class, PrivateJsonPublisher::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        QueueRuntimeConfiguration::assertSafe(
            config('queue.default'),
            config('queue.connections'),
        );
        QueueWorkerPoolConfiguration::plan(
            config('queue-workers'),
            static fn (string $key): mixed => config($key),
        );

        RateLimiter::for('mobile-auth', function (Request $request): Limit {
            $grantType = $request->input('grant_type') === 'refresh_token'
                ? 'refresh'
                : 'password';
            $maxAttempts = $grantType === 'refresh'
                ? $this->boundedMobileAuthLimit(config('mapilio.mobile_auth.rate_limits.refresh'), 30)
                : $this->boundedMobileAuthLimit(config('mapilio.mobile_auth.rate_limits.password'), 10);

            return Limit::perMinute($maxAttempts)
                ->by('mobile-auth|'.$grantType.'|'.$request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'success' => false,
                        'message' => ['Too many authentication attempts. Please try again later.'],
                    ], 429, $headers);
                });
        });

        RateLimiter::for('mobile-social-auth', function (Request $request): Limit {
            $limit = $this->boundedMobileAuthLimit(
                config('mapilio.mobile_social_auth.rate_limit', 10),
                10,
            );

            return Limit::perMinute($limit)
                ->by('mobile-social-auth|'.$request->ip())
                ->response(fn (Request $request, array $headers) => response()->json([
                    'success' => false,
                    'message' => 'Too many authentication attempts. Please try again later.',
                ], 429, $headers));
        });

        /*
         * Image reports are accepted anonymously, so this is the only thing
         * standing between the moderation queue and an unauthenticated caller
         * in a loop. People report at human speed, so the ceiling is well
         * above any plausible legitimate rate.
         */
        RateLimiter::for('imagery-reports', function (Request $request): Limit {
            return Limit::perMinute($this->boundedMobileAuthLimit(
                config('mapilio.imagery_reports.rate_limit'),
                10,
            ))
                ->by('imagery-reports|'.$request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'success' => false,
                        'message' => ['Too many reports. Please try again later.'],
                        'error_code' => 429,
                    ], 429, $headers);
                });
        });
    }

    private function boundedMobileAuthLimit(mixed $configuredLimit, int $fallback): int
    {
        $limit = match (true) {
            is_int($configuredLimit) => $configuredLimit,
            is_string($configuredLimit) && preg_match('/\A[+-]?\d+\z/D', $configuredLimit) === 1 => (int) $configuredLimit,
            default => $fallback,
        };

        return min(1000, max(1, $limit));
    }
}

<?php

namespace App\Providers;

use App\Domain\DataMigration\JsonPublisher;
use App\Domain\DataMigration\PrivateJsonPublisher;
use App\Support\Queue\QueueRuntimeConfiguration;
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

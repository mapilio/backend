<?php

namespace App\Providers;

use App\Domain\DataMigration\JsonPublisher;
use App\Domain\DataMigration\PrivateJsonPublisher;
use App\Support\Queue\QueueRuntimeConfiguration;
use App\Support\Queue\QueueWorkerPoolConfiguration;
use App\Support\Security\MobileAuthPasswordTimingConfiguration;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\JsonResponse;
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
        MobileAuthPasswordTimingConfiguration::assertSafe(
            config('mapilio.mobile_auth.dummy_password_hash'),
            $this->app->make(Hasher::class),
        );
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

        RateLimiter::for('mobile-registration', function (Request $request): Limit {
            return Limit::perHour($this->boundedMobileAuthLimit(
                config('mapilio.mobile_accounts.rate_limits.registration'),
                10,
            ))
                ->by('mobile-registration|'.$request->ip())
                ->response(fn (Request $request, array $headers) => $this->mobileAccountRateLimitResponse($headers));
        });

        RateLimiter::for('mobile-password-reset', function (Request $request): array {
            $email = strtolower(trim((string) $request->input('email', '')));
            $perAddress = $this->boundedMobileAuthLimit(
                config('mapilio.mobile_accounts.rate_limits.password_reset_per_email'),
                5,
            );
            $perIp = $this->boundedMobileAuthLimit(
                config('mapilio.mobile_accounts.rate_limits.password_reset_per_ip'),
                20,
            );

            return [
                Limit::perHour($perAddress)
                    ->by('mobile-password-reset|email|'.hash('sha256', $email))
                    ->response(fn (Request $request, array $headers) => $this->mobileAccountRateLimitResponse($headers)),
                Limit::perHour($perIp)
                    ->by('mobile-password-reset|ip|'.$request->ip())
                    ->response(fn (Request $request, array $headers) => $this->mobileAccountRateLimitResponse($headers)),
            ];
        });

        RateLimiter::for('mobile-password-renew', function (Request $request): Limit {
            return Limit::perMinute($this->boundedMobileAuthLimit(
                config('mapilio.mobile_accounts.rate_limits.password_renew'),
                10,
            ))
                ->by('mobile-password-renew|'.$request->ip())
                ->response(fn (Request $request, array $headers) => $this->mobileAccountRateLimitResponse($headers));
        });

        RateLimiter::for('mobile-account-write', function (Request $request): Limit {
            return Limit::perMinute($this->boundedMobileAuthLimit(
                config('mapilio.mobile_accounts.rate_limits.account_write'),
                20,
            ))
                ->by('mobile-account-write|'.$request->ip())
                ->response(fn (Request $request, array $headers) => $this->mobileAccountRateLimitResponse($headers));
        });

        RateLimiter::for('mobile-account-delete', function (Request $request): Limit {
            return Limit::perHour($this->boundedMobileAuthLimit(
                config('mapilio.mobile_accounts.rate_limits.account_delete'),
                3,
            ))
                ->by('mobile-account-delete|'.$request->ip())
                ->response(fn (Request $request, array $headers) => $this->mobileAccountRateLimitResponse($headers));
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

    /**
     * @param  array<string, mixed>  $headers
     */
    private function mobileAccountRateLimitResponse(array $headers): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => ['Too many requests. Please try again later.'],
        ], 429, $headers);
    }
}

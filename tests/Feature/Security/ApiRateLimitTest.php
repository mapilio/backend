<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ApiRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('mapilio-api|'.sha1('127.0.0.1'));
    }

    public function test_limiter_is_inert_until_it_is_enabled(): void
    {
        Config::set('mapilio.rate_limiting.enabled', false);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $response = $this->getJson('/api/v1/system/health')->assertOk();

            $this->assertFalse($response->headers->has('X-RateLimit-Limit'));
        }
    }

    public function test_observe_mode_never_rejects_and_adds_no_headers(): void
    {
        Config::set('mapilio.rate_limiting.enabled', true);
        Config::set('mapilio.rate_limiting.enforce', false);
        Config::set('mapilio.rate_limiting.max_attempts', 2);

        // Well past the ceiling: every request must still succeed.
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $response = $this->getJson('/api/v1/system/health')->assertOk();

            $this->assertFalse(
                $response->headers->has('X-RateLimit-Limit'),
                'Observe mode must not advertise a limit it does not enforce.',
            );
        }
    }

    public function test_observe_mode_logs_the_callers_it_would_have_rejected(): void
    {
        Config::set('mapilio.rate_limiting.enabled', true);
        Config::set('mapilio.rate_limiting.enforce', false);
        Config::set('mapilio.rate_limiting.max_attempts', 2);

        $records = [];

        Log::listen(function ($message) use (&$records): void {
            if ($message->message === 'api.rate_limit') {
                $records[] = $message->context;
            }
        });

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->getJson('/api/v1/system/health')->assertOk();
        }

        $this->assertNotEmpty($records, 'Observe mode must log would-be rejections.');
        $this->assertFalse($records[0]['enforced']);
        $this->assertSame(2, $records[0]['limit']);
        $this->assertSame('/api/v1/system/health', $records[0]['path']);
        $this->assertArrayNotHasKey('ip', $records[0]);
        $this->assertSame(12, strlen($records[0]['client']));
    }

    public function test_enforced_mode_returns_the_legacy_error_envelope(): void
    {
        Config::set('mapilio.rate_limiting.enabled', true);
        Config::set('mapilio.rate_limiting.enforce', true);
        Config::set('mapilio.rate_limiting.max_attempts', 2);

        $this->getJson('/api/v1/system/health')->assertOk();
        $this->getJson('/api/v1/system/health')->assertOk();

        $response = $this->getJson('/api/v1/system/health')
            ->assertStatus(429)
            ->assertExactJson([
                'success' => false,
                'message' => ['Too many requests.'],
                'error_code' => 429,
            ]);

        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertSame('2', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_web_group_routes_are_covered_too(): void
    {
        // /config/general and the legacy callback are registered on the web
        // group, so registering the limiter only on the api group left them
        // unprotected. The config endpoint serves map provider tokens.
        Config::set('mapilio.rate_limiting.enabled', true);
        Config::set('mapilio.rate_limiting.enforce', true);
        Config::set('mapilio.rate_limiting.max_attempts', 2);

        RateLimiter::clear('mapilio-api|'.sha1('127.0.0.1'));

        $this->getJson('/config/general')->assertOk();
        $this->getJson('/config/general')->assertOk();

        $this->getJson('/config/general')
            ->assertStatus(429)
            ->assertJsonPath('error_code', 429);
    }

    public function test_unrelated_web_routes_are_not_limited(): void
    {
        // The limiter is on the whole web group now, so the service root must
        // stay outside the rate-limited surface.
        Config::set('mapilio.rate_limiting.enabled', true);
        Config::set('mapilio.rate_limiting.enforce', true);
        Config::set('mapilio.rate_limiting.max_attempts', 1);

        RateLimiter::clear('mapilio-api|'.sha1('127.0.0.1'));

        $this->getJson('/')->assertOk();
        $this->getJson('/')->assertOk();
        $this->getJson('/')->assertOk();
    }

    public function test_routes_with_their_own_throttle_are_left_untouched(): void
    {
        Config::set('mapilio.rate_limiting.enabled', true);
        Config::set('mapilio.rate_limiting.enforce', true);
        Config::set('mapilio.rate_limiting.max_attempts', 1);

        // The AI feature route declares throttle:120,1 of its own. The global
        // limiter must not shadow it, so its own headers survive unchanged.
        $response = $this->getJson('/api/v1/geo/ai-features/1');

        $this->assertSame('120', $response->headers->get('X-RateLimit-Limit'));

        $this->getJson('/api/v1/geo/ai-features/1')
            ->assertStatus($response->getStatusCode());
    }
}

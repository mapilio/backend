<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MobileSocialAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.legacy_database_connection', config('database.default'));
        Config::set('mapilio.mobile_auth.signing_key', 'test-signing-key');
        Config::set('mapilio.mobile_social_auth.base_url', 'https://legacy.example.test');
        Config::set('mapilio.mobile_social_auth.client_id', 'server-client-id');
        Config::set('mapilio.mobile_social_auth.client_secret', 'server-client-secret');
        Schema::create('default_users_users', function ($table): void {
            $table->increments('id');
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->boolean('activated')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::getConnection()->table('default_users_users')->insert([
            'id' => 10,
            'email' => 'alice@example.test',
            'username' => 'alice',
            'password' => Hash::make('unused'),
            'activated' => true,
            'enabled' => true,
        ]);
    }

    public function test_successful_bridge_returns_only_a_modern_token_pair(): void
    {
        Http::fake([
            'https://legacy.example.test/oauth-api/google/authenticate' => Http::response([
                'success' => true,
                'id' => 999,
                'access_token' => 'legacy-bearer-never-returned',
            ]),
            'https://legacy.example.test/api/function/user_profile/profile/getProfile' => Http::response([
                'data' => [['id' => 10, 'username' => 'alice']],
            ]),
        ]);

        $response = $this->postJson('/api/v1/mobile/auth/social-token', [
            'provider' => 'google',
            'token' => 'provider-token',
            'client_id' => 'mobile-attacker-id',
            'client_secret' => 'mobile-attacker-secret',
        ])->assertOk();

        $response->assertJsonPath('success', true)->assertJsonPath('id', 10);
        $this->assertIsString($response->json('access_token'));
        $this->assertIsString($response->json('refresh_token'));
        $this->assertArrayNotHasKey('token', $response->json());
        Http::assertSent(fn ($request): bool => $request->url() === 'https://legacy.example.test/oauth-api/google/authenticate'
            && $request->data() === [
                'client_id' => 'server-client-id',
                'client_secret' => 'server-client-secret',
                'token' => 'provider-token',
                'is_mobile' => true,
            ]
            && ($request->headers()['Authorization'] ?? $request->headers()['authorization'] ?? []) === []
            && ! str_contains($request->url(), '?'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://legacy.example.test/api/function/user_profile/profile/getProfile'
            && ($request->headers()['Authorization'] ?? $request->headers()['authorization'] ?? [])
                === ['Bearer legacy-bearer-never-returned']);
        $this->assertSame(2, Http::recorded()->count());
    }

    public function test_missing_server_credentials_fails_closed_without_an_upstream_request(): void
    {
        Config::set('mapilio.mobile_social_auth.client_id', '');
        Config::set('mapilio.mobile_social_auth.client_secret', '');
        Http::fake();

        $this->postJson('/api/v1/mobile/auth/social-token', [
            'provider' => 'google',
            'token' => 'provider-token',
        ])->assertStatus(503)
            ->assertExactJson(['success' => false, 'message' => 'Social login is temporarily unavailable.']);

        Http::assertNothingSent();
    }

    public function test_validation_rejects_unknown_provider_and_oversized_token_without_upstream_call(): void
    {
        Http::fake();

        $this->postJson('/api/v1/mobile/auth/social-token', ['provider' => 'x', 'token' => 'token'])
            ->assertStatus(422);
        $this->postJson('/api/v1/mobile/auth/social-token', [
            'provider' => 'google',
            'token' => str_repeat('x', 8193),
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_upstream_four_hundred_is_a_stable_invalid_credentials_response(): void
    {
        Http::fake([
            'https://legacy.example.test/oauth-api/*' => Http::response(['success' => false], 401),
        ]);

        $this->postJson('/api/v1/mobile/auth/social-token', ['provider' => 'apple', 'token' => 'bad'])
            ->assertStatus(401)
            ->assertExactJson(['success' => false, 'message' => 'Invalid social login credentials.']);
    }

    public function test_profile_four_hundred_is_a_stable_invalid_credentials_response(): void
    {
        Http::fake([
            'https://legacy.example.test/oauth-api/*' => Http::response(['access_token' => 'legacy-bearer']),
            'https://legacy.example.test/api/function/*' => Http::response(['error' => 'invalid'], 403),
        ]);

        $this->postJson('/api/v1/mobile/auth/social-token', ['provider' => 'apple', 'token' => 'bad'])
            ->assertStatus(401)
            ->assertExactJson(['success' => false, 'message' => 'Invalid social login credentials.']);
    }

    public function test_malformed_and_upstream_failure_responses_are_sanitized_as_unavailable(): void
    {
        Http::fake([
            'https://legacy.example.test/oauth-api/*' => Http::response(['success' => true, 'access_token' => 'legacy-bearer']),
            'https://legacy.example.test/api/function/*' => Http::response(['data' => [['id' => '10']]], 200),
        ]);
        $this->postJson('/api/v1/mobile/auth/social-token', ['provider' => 'facebook', 'token' => 'token'])
            ->assertStatus(503)
            ->assertExactJson(['success' => false, 'message' => 'Social login is temporarily unavailable.']);

        Http::fake(['*' => Http::response(['internal' => 'secret'], 500)]);
        $this->postJson('/api/v1/mobile/auth/social-token', ['provider' => 'facebook', 'token' => 'token'])
            ->assertStatus(503)
            ->assertExactJson(['success' => false, 'message' => 'Social login is temporarily unavailable.']);
    }

    public function test_unsafe_production_url_is_rejected_without_a_request(): void
    {
        Config::set('app.env', 'production');
        Config::set('mapilio.mobile_social_auth.base_url', 'http://legacy.example.test?client_secret=bad');
        Http::fake();

        $this->postJson('/api/v1/mobile/auth/social-token', ['provider' => 'google', 'token' => 'token'])
            ->assertStatus(503);

        Http::assertNothingSent();
    }

    public function test_non_http_scheme_is_rejected_in_all_environments(): void
    {
        Config::set('mapilio.mobile_social_auth.base_url', 'ftp://legacy.example.test');
        Http::fake();

        $this->postJson('/api/v1/mobile/auth/social-token', ['provider' => 'google', 'token' => 'token'])
            ->assertStatus(503);

        Http::assertNothingSent();
    }

    public function test_social_limiter_is_shared_across_provider_rotation_for_one_ip(): void
    {
        Config::set('mapilio.mobile_social_auth.rate_limit', 1);
        RateLimiter::clear('mobile-social-auth|127.0.0.1');
        Http::fake(['*' => Http::response(['success' => false], 401)]);

        $this->postJson('/api/v1/mobile/auth/social-token', ['provider' => 'google', 'token' => 'bad'])->assertStatus(401);
        $this->postJson('/api/v1/mobile/auth/social-token', ['provider' => 'facebook', 'token' => 'bad'])
            ->assertStatus(429);
    }
}

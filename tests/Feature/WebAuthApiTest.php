<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WebAuthApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.mobile_auth.client_id', 'mobile-client');
        Config::set('mapilio.mobile_auth.client_secret', 'mobile-secret');
        Config::set('mapilio.mobile_auth.signing_key', 'test-signing-key');
        Config::set('mapilio.mobile_auth.access_token_ttl', 3600);
        Config::set('mapilio.mobile_auth.refresh_token_ttl', 36000);

        Schema::create('default_users_users', function ($table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->boolean('activated')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::getConnection()->table('default_users_users')->insert([
            [
                'id' => 10,
                'email' => 'alice@example.test',
                'username' => 'alice',
                'password' => Hash::make('correct-password'),
                'activated' => true,
                'enabled' => true,
                'deleted_at' => null,
            ],
            [
                'id' => 20,
                'email' => 'disabled@example.test',
                'username' => 'disabled',
                'password' => Hash::make('correct-password'),
                'activated' => false,
                'enabled' => true,
                'deleted_at' => null,
            ],
        ]);
    }

    public function test_password_grant_issues_first_party_tokens_without_client_credentials(): void
    {
        $this->postJson('/api/v1/web/auth/token', [
            'grant_type' => 'password',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ])
            ->assertOk()
            ->assertJsonPath('id', 10)
            ->assertJsonPath('success', true)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('expires_in', 3600)
            ->assertJsonStructure(['access_token', 'refresh_token']);
    }

    public function test_refresh_grant_rotates_the_token_pair_without_client_credentials(): void
    {
        $login = $this->postJson('/api/v1/web/auth/token', [
            'grant_type' => 'password',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ])->assertOk();

        $this->postJson('/api/v1/web/auth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $login->json('refresh_token'),
        ])
            ->assertOk()
            ->assertJsonPath('id', 10)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['access_token', 'refresh_token']);
    }

    public function test_invalid_credentials_and_inactive_accounts_keep_safe_contracts(): void
    {
        $this->postJson('/api/v1/web/auth/token', [
            'grant_type' => 'password',
            'email' => 'alice@example.test',
            'password' => 'wrong-password',
        ])
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ['Email or password is invalid.'],
            ]);

        $this->postJson('/api/v1/web/auth/token', [
            'grant_type' => 'password',
            'email' => 'disabled@example.test',
            'password' => 'correct-password',
        ])
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => 'This account is inactive.',
            ]);
    }

    public function test_unknown_web_accounts_use_the_dummy_hash_once(): void
    {
        $dummyHash = (string) config('mapilio.mobile_auth.dummy_password_hash');
        Hash::shouldReceive('check')
            ->once()
            ->with('wrong-password', $dummyHash)
            ->andReturn(false);

        $this->postJson('/api/v1/web/auth/token', [
            'grant_type' => 'password',
            'email' => 'unknown@example.test',
            'password' => 'wrong-password',
        ])
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ['Email or password is invalid.'],
            ]);
    }

    public function test_web_auth_validates_and_bounds_the_public_request(): void
    {
        $this->postJson('/api/v1/web/auth/token', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grant_type']);

        $this->postJson('/api/v1/web/auth/token', [
            'grant_type' => 'password',
            'email' => str_repeat('a', 255),
            'password' => str_repeat('x', 1025),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_legacy_mobile_endpoint_still_requires_its_client_credentials(): void
    {
        $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ])
            ->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message.client_id.0', 'The client_id field is required.')
            ->assertJsonPath('message.client_secret.0', 'The client_secret field is required.');
    }

    public function test_web_auth_is_rate_limited_per_ip(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
                ->postJson('/api/v1/web/auth/token', [
                    'grant_type' => 'password',
                    'email' => 'alice@example.test',
                    'password' => 'wrong-password',
                ])
                ->assertStatus(400);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
            ->postJson('/api/v1/web/auth/token', [
                'grant_type' => 'password',
                'email' => 'alice@example.test',
                'password' => 'wrong-password',
            ])
            ->assertTooManyRequests();
    }
}

<?php

namespace Tests\Feature\Security;

use App\Domain\IdentityAccess\LegacyMobileAuth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class TokenRevocationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.mobile_auth.client_id', 'mobile-client');
        Config::set('mapilio.mobile_auth.client_secret', 'mobile-secret');
        Config::set('mapilio.mobile_auth.signing_key', 'current-signing-key');
        Config::set('mapilio.mobile_auth.previous_signing_key', null);
        Config::set('mapilio.mobile_auth.revocation.enabled', true);

        $this->createUsersTable();
    }

    public function test_logout_revokes_both_tokens_so_neither_is_accepted(): void
    {
        $login = $this->login();

        $this->withToken($login->json('access_token'))
            ->postJson('/api/v1/mobile/auth/logout', [
                'refresh_token' => $login->json('refresh_token'),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $auth = app(LegacyMobileAuth::class);

        $this->assertNull(
            $auth->userFromBearer('Bearer '.$login->json('access_token')),
            'A revoked access token must stop resolving to a user.',
        );

        // Revoking the access token alone would be pointless if the refresh
        // token could still mint a replacement.
        $this->postJson('/api/v2/login', [
            'grant_type' => 'refresh_token',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'refresh_token' => $login->json('refresh_token'),
        ])->assertStatus(400);
    }

    public function test_other_sessions_are_unaffected_by_a_logout(): void
    {
        $first = $this->login();
        $second = $this->login();

        $this->withToken($first->json('access_token'))
            ->postJson('/api/v1/mobile/auth/logout', [
                'refresh_token' => $first->json('refresh_token'),
            ])
            ->assertOk();

        $auth = app(LegacyMobileAuth::class);

        $this->assertNull($auth->userFromBearer('Bearer '.$first->json('access_token')));
        $this->assertNotNull(
            $auth->userFromBearer('Bearer '.$second->json('access_token')),
            'Revocation must be per token, not per user.',
        );
    }

    public function test_logout_requires_a_valid_token(): void
    {
        $this->postJson('/api/v1/mobile/auth/logout')->assertStatus(401);

        $this->withToken('not-a-real-token')
            ->postJson('/api/v1/mobile/auth/logout')
            ->assertStatus(401);

        $this->assertSame(0, DB::table('revoked_auth_tokens')->count());
    }

    public function test_revocations_are_recorded_even_while_enforcement_is_disabled(): void
    {
        // The rollout ships with enforcement off. Revocations recorded during
        // that window must still bite the moment it is switched on.
        Config::set('mapilio.mobile_auth.revocation.enabled', false);

        $login = $this->login();
        $auth = app(LegacyMobileAuth::class);

        $this->withToken($login->json('access_token'))
            ->postJson('/api/v1/mobile/auth/logout')
            ->assertOk();

        $this->assertSame(1, DB::table('revoked_auth_tokens')->count());
        $this->assertNotNull($auth->userFromBearer('Bearer '.$login->json('access_token')));

        Config::set('mapilio.mobile_auth.revocation.enabled', true);

        $this->assertNull($auth->userFromBearer('Bearer '.$login->json('access_token')));
    }

    public function test_a_token_signed_with_the_previous_key_still_verifies_during_rotation(): void
    {
        // Issued before the rotation.
        Config::set('mapilio.mobile_auth.signing_key', 'old-key');
        $login = $this->login();

        // Rotated: new key current, old key retained for verification.
        Config::set('mapilio.mobile_auth.signing_key', 'new-key');
        Config::set('mapilio.mobile_auth.previous_signing_key', 'old-key');

        $auth = app(LegacyMobileAuth::class);

        $this->assertNotNull(
            $auth->userFromBearer('Bearer '.$login->json('access_token')),
            'Rotating the signing key must not log out live sessions.',
        );

        // Once the old key is retired the token stops verifying.
        Config::set('mapilio.mobile_auth.previous_signing_key', null);

        $this->assertNull($auth->userFromBearer('Bearer '.$login->json('access_token')));
    }

    public function test_tokens_issued_after_rotation_use_the_current_key(): void
    {
        Config::set('mapilio.mobile_auth.signing_key', 'new-key');
        Config::set('mapilio.mobile_auth.previous_signing_key', 'old-key');

        $login = $this->login();

        // Retiring the old key must not affect a token signed with the new one.
        Config::set('mapilio.mobile_auth.previous_signing_key', null);

        $this->assertNotNull(
            app(LegacyMobileAuth::class)->userFromBearer('Bearer '.$login->json('access_token')),
        );
    }

    public function test_pruning_drops_only_expired_denylist_rows(): void
    {
        $auth = app(LegacyMobileAuth::class);

        DB::table('revoked_auth_tokens')->insert([
            [
                'jti' => 'expired-token',
                'subject' => 10,
                'token_type' => 'access',
                'reason' => 'logout',
                'expires_at' => now()->subHour(),
                'revoked_at' => now()->subHours(2),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'jti' => 'live-token',
                'subject' => 10,
                'token_type' => 'access',
                'reason' => 'logout',
                'expires_at' => now()->addHour(),
                'revoked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertSame(1, $auth->pruneRevokedTokens());
        $this->assertSame(['live-token'], DB::table('revoked_auth_tokens')->pluck('jti')->all());
    }

    /**
     * @return TestResponse<Response>
     */
    private function login(): TestResponse
    {
        return $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ])->assertOk();
    }

    private function createUsersTable(): void
    {
        Schema::create('revoked_auth_tokens', function ($table): void {
            $table->id();
            $table->string('jti', 64)->unique();
            $table->unsignedBigInteger('subject')->index();
            $table->string('token_type', 16);
            $table->string('reason', 64)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at');
            $table->timestamps();
        });

        Schema::create('default_users_users', function ($table): void {
            $table->increments('id');
            $table->string('email');
            $table->string('username')->nullable();
            $table->string('password');
            $table->boolean('activated')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::getConnection()->table('default_users_users')->insert([
            'id' => 10,
            'email' => 'alice@example.test',
            'username' => 'alice',
            'password' => Hash::make('correct-password'),
            'activated' => true,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

<?php

namespace Tests\Feature\Security;

use App\Domain\IdentityAccess\LegacyMobileAuth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers the public-client login path that lets the mobile app stop shipping a
 * client secret (mapilio/mobile-apps#84), and proves the legacy
 * client-credential path is unchanged for already shipped builds.
 */
class MobilePublicClientAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.mobile_auth.client_id', 'mobile-client');
        Config::set('mapilio.mobile_auth.client_secret', 'mobile-secret');
        Config::set('mapilio.mobile_auth.signing_key', 'test-signing-key');

        $this->createUsersTable();
    }

    public function test_public_client_login_issues_tokens_without_client_credentials(): void
    {
        $response = $this->postJson('/api/v1/mobile/auth/public-token', [
            'grant_type' => 'password',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ])->assertOk();

        $response->assertJsonPath('success', true);
        $response->assertJsonPath('id', 10);
        $response->assertJsonPath('token_type', 'Bearer');
        $this->assertIsString($response->json('access_token'));
        $this->assertIsString($response->json('refresh_token'));
    }

    public function test_public_client_accepts_username_as_well_as_email(): void
    {
        $this->postJson('/api/v1/mobile/auth/public-token', [
            'grant_type' => 'password',
            'username' => 'alice',
            'password' => 'correct-password',
        ])->assertOk()->assertJsonPath('id', 10);
    }

    public function test_public_client_refresh_grant_rotates_the_token_pair(): void
    {
        $login = $this->postJson('/api/v1/mobile/auth/public-token', [
            'grant_type' => 'password',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ])->assertOk();

        $refreshed = $this->postJson('/api/v1/mobile/auth/public-token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $login->json('refresh_token'),
        ])->assertOk();

        $refreshed->assertJsonPath('success', true);
        $this->assertIsString($refreshed->json('access_token'));
        $this->assertIsString($refreshed->json('refresh_token'));
    }

    public function test_public_client_token_is_interchangeable_with_a_legacy_token(): void
    {
        $public = $this->postJson('/api/v1/mobile/auth/public-token', [
            'grant_type' => 'password',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ])->assertOk();

        $legacy = $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ])->assertOk();

        // A token minted without client credentials must resolve to the same
        // user as a legacy one, otherwise migrated builds would lose access.
        $auth = app(LegacyMobileAuth::class);

        $fromPublic = $auth->userFromBearer('Bearer '.$public->json('access_token'));
        $fromLegacy = $auth->userFromBearer('Bearer '.$legacy->json('access_token'));

        $this->assertNotNull($fromPublic);
        $this->assertNotNull($fromLegacy);
        $this->assertSame(10, (int) $fromPublic->id);
        $this->assertSame((int) $fromLegacy->id, (int) $fromPublic->id);
    }

    public function test_public_client_rejects_wrong_credentials_with_the_legacy_shape(): void
    {
        $this->postJson('/api/v1/mobile/auth/public-token', [
            'grant_type' => 'password',
            'email' => 'alice@example.test',
            'password' => 'wrong-password',
        ])
            ->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_legacy_client_credential_login_is_unchanged(): void
    {
        // Already shipped builds still authenticate exactly as before.
        $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ])->assertOk()->assertJsonPath('id', 10);

        // And the legacy endpoint still demands them.
        $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ])->assertStatus(400);
    }

    public function test_config_token_is_accepted_from_a_header_as_well_as_the_query_string(): void
    {
        Config::set('mapilio.mobile_config.token', 'config-token-value');

        // Preferred: header, so the secret stays out of URLs and access logs.
        $this->withHeaders(['X-Mapilio-Config-Token' => 'config-token-value'])
            ->getJson('/api/v1/mobile/config/general')
            ->assertOk()
            ->assertJsonStructure(['config']);

        // withHeaders persists across requests, so clear it before the cases
        // that must arrive with no token at all.
        $this->flushHeaders();

        // Still supported: query string, for already shipped builds.
        $this->getJson('/api/v1/mobile/config/general?token=config-token-value')
            ->assertOk();

        // Neither present, or wrong: still forbidden.
        $this->getJson('/api/v1/mobile/config/general')->assertStatus(403);

        $this->withHeaders(['X-Mapilio-Config-Token' => 'wrong'])
            ->getJson('/api/v1/mobile/config/general')
            ->assertStatus(403);
    }

    private function createUsersTable(): void
    {
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

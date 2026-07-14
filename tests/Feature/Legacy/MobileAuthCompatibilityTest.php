<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileAuthCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.mobile_auth.client_id', 'mobile-client');
        Config::set('mapilio.mobile_auth.client_secret', 'mobile-secret');
        Config::set('mapilio.mobile_auth.signing_key', 'test-signing-key');
        Config::set('mapilio.mobile_auth.access_token_ttl', 3600);
        Config::set('mapilio.mobile_auth.refresh_token_ttl', 36000);
        Config::set('mapilio.mobile_auth.default_profile_photo_url', 'https://mapilio.test/default-avatar.png');
        Config::set('mapilio.mobile_auth.onesignal_rest_api_key', 'onesignal-key');

        $this->createTables();
        $this->seedUsers();
    }

    public function test_mobile_password_grant_preserves_passport_like_success_shape(): void
    {
        $response = $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('expires_in', 3600)
            ->assertJsonStructure([
                'access_token',
                'refresh_token',
            ]);

        $this->assertIsString($response->json('access_token'));
        $this->assertIsString($response->json('refresh_token'));
    }

    public function test_mobile_password_grant_accepts_username_in_email_field(): void
    {
        $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'email' => 'alice',
            'password' => 'correct-password',
        ])->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_mobile_password_grant_preserves_validation_and_auth_failures(): void
    {
        $this->postJson('/api/v2/login', [])
            ->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message.client_id.0', 'The client_id field is required.')
            ->assertJsonPath('message.client_secret.0', 'The client_secret field is required.')
            ->assertJsonPath('message.grant_type.0', 'The grant_type field is required.');

        $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'email' => 'alice@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ['Email or password is invalid.'],
            ]);
    }

    public function test_mobile_password_grant_preserves_inactive_account_failure(): void
    {
        $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'email' => 'disabled@example.test',
            'password' => 'correct-password',
        ])->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => 'This account is inactive.',
            ]);
    }

    public function test_mobile_refresh_grant_issues_new_token_pair(): void
    {
        $login = $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ])->assertOk();

        $this->postJson('/api/v2/login', [
            'grant_type' => 'refresh_token',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'refresh_token' => $login->json('refresh_token'),
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'access_token',
                'refresh_token',
            ]);
    }

    public function test_mobile_tokens_stop_working_when_user_is_disabled(): void
    {
        $login = $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ])->assertOk();

        Schema::getConnection()
            ->table('default_users_users')
            ->where('id', 10)
            ->update(['enabled' => false]);

        $this->postJson('/api/v2/login', [
            'grant_type' => 'refresh_token',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'refresh_token' => $login->json('refresh_token'),
        ])->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ['Email or password is invalid.'],
            ]);

        $this->withToken($login->json('access_token'))
            ->getJson('/api/function/user_profile/profile/getProfile')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_mobile_profile_endpoint_preserves_dynamic_function_wrapper(): void
    {
        $login = $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ])->assertOk();

        $this->withToken($login->json('access_token'))
            ->getJson('/api/function/user_profile/profile/getProfile')
            ->assertOk()
            ->assertJsonPath('data.0.id', 10)
            ->assertJsonPath('data.0.email', 'alice@example.test')
            ->assertJsonPath('data.0.username', 'alice')
            ->assertJsonPath('data.0.display_name', 'Alice Example')
            ->assertJsonPath('data.0.user_profile_photo', 'https://mapilio.test/default-avatar.png')
            ->assertJsonPath('data.0.isAdmin', true)
            ->assertJsonPath('data.0.sequences', 2)
            ->assertJsonPath('data.0.photos', 3)
            ->assertJsonPath('data.0.meters', '4.000')
            ->assertJsonPath('data.0.score', '16');
    }

    public function test_mobile_profile_and_onesignal_endpoints_require_valid_bearer(): void
    {
        $this->getJson('/api/function/user_profile/profile/getProfile')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);

        $this->postJson('/api/onesignal/identity-verification', [
            'options' => [
                'parameters' => [
                    'email' => 'alice@example.test',
                ],
            ],
        ])->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => ['Verification failed.'],
            ]);
    }

    public function test_mobile_onesignal_identity_verification_preserves_hash_shape(): void
    {
        $login = $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ])->assertOk();

        $this->withToken($login->json('access_token'))
            ->postJson('/api/onesignal/identity-verification', [
                'options' => [
                    'parameters' => [
                        'email' => 'alice@example.test',
                    ],
                ],
            ])->assertOk()
            ->assertExactJson([
                'status' => true,
                'response' => [
                    'hash' => hash_hmac('sha256', 'alice@example.test', 'onesignal-key'),
                ],
            ]);
    }

    public function test_versioned_mobile_auth_and_profile_aliases_match_legacy_behavior(): void
    {
        $login = $this->postJson('/api/v1/mobile/auth/token', [
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ])->assertOk();

        $this->withToken($login->json('access_token'))
            ->getJson('/api/v1/mobile/profile')
            ->assertOk()
            ->assertJsonPath('data.0.email', 'alice@example.test');
    }

    private function createTables(): void
    {
        Schema::create('default_users_users', function ($table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->string('display_name')->nullable();
            $table->string('user_profile_photo')->nullable();
            $table->string('str_id')->nullable();
            $table->boolean('hidden_profile')->default(false);
            $table->text('user_bio')->nullable();
            $table->integer('shape_limit')->nullable();
            $table->boolean('activated')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_users_roles', function ($table): void {
            $table->id();
            $table->string('slug')->nullable();
        });

        Schema::create('default_users_users_roles', function ($table): void {
            $table->integer('entry_id');
            $table->integer('related_id');
        });

        Schema::create('default_mapilio_imagery', function ($table): void {
            $table->id();
            $table->string('sequence_uuid')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->string('project_key')->nullable();
            $table->boolean('anomaly')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_mapilio_sequence_detail', function ($table): void {
            $table->id();
            $table->string('sequence_uuid')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->float('sequence_point')->nullable();
            $table->float('length_km')->nullable();
            $table->boolean('anomaly')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });
    }

    private function seedUsers(): void
    {
        Schema::getConnection()->table('default_users_users')->insert([
            [
                'id' => 10,
                'email' => 'alice@example.test',
                'username' => 'alice',
                'password' => Hash::make('correct-password'),
                'display_name' => 'Alice Example',
                'user_profile_photo' => null,
                'str_id' => 'alice-key',
                'hidden_profile' => false,
                'user_bio' => 'Mapping roads.',
                'shape_limit' => 100,
                'activated' => true,
                'enabled' => true,
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-02 00:00:00',
                'deleted_at' => null,
            ],
            [
                'id' => 20,
                'email' => 'disabled@example.test',
                'username' => 'disabled',
                'password' => Hash::make('correct-password'),
                'display_name' => 'Disabled User',
                'user_profile_photo' => null,
                'str_id' => 'disabled-key',
                'hidden_profile' => false,
                'user_bio' => null,
                'shape_limit' => null,
                'activated' => false,
                'enabled' => true,
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-02 00:00:00',
                'deleted_at' => null,
            ],
        ]);

        Schema::getConnection()->table('default_users_roles')->insert([
            ['id' => 1, 'slug' => 'admin'],
        ]);

        Schema::getConnection()->table('default_users_users_roles')->insert([
            ['entry_id' => 10, 'related_id' => 1],
        ]);

        Schema::getConnection()->table('default_mapilio_imagery')->insert([
            ['id' => 1, 'sequence_uuid' => 'seq-a', 'created_by_id' => 10, 'project_key' => null, 'deleted_at' => null],
            ['id' => 2, 'sequence_uuid' => 'seq-a', 'created_by_id' => 10, 'project_key' => null, 'deleted_at' => null],
            ['id' => 3, 'sequence_uuid' => 'seq-b', 'created_by_id' => 10, 'project_key' => null, 'deleted_at' => null],
            ['id' => 4, 'sequence_uuid' => 'seq-project', 'created_by_id' => 10, 'project_key' => 'project-a', 'deleted_at' => null],
            ['id' => 5, 'sequence_uuid' => 'seq-deleted', 'created_by_id' => 10, 'project_key' => null, 'deleted_at' => '2026-01-02 00:00:00'],
        ]);

        Schema::getConnection()->table('default_mapilio_sequence_detail')->insert([
            ['sequence_uuid' => 'seq-a', 'created_by_id' => 10, 'sequence_point' => 10, 'length_km' => 1.25, 'deleted_at' => null],
            ['sequence_uuid' => 'seq-b', 'created_by_id' => 10, 'sequence_point' => 5.7, 'length_km' => 2.75, 'deleted_at' => null],
            ['sequence_uuid' => 'seq-deleted', 'created_by_id' => 10, 'sequence_point' => 50, 'length_km' => 9, 'deleted_at' => '2026-01-02 00:00:00'],
        ]);
    }
}

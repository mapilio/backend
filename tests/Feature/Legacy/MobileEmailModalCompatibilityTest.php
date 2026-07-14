<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileEmailModalCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.mobile_auth.client_id', 'mobile-client');
        Config::set('mapilio.mobile_auth.client_secret', 'mobile-secret');
        Config::set('mapilio.mobile_auth.signing_key', 'test-signing-key');

        $this->createTables();
        $this->seedUsers();
    }

    public function test_mobile_check_email_modal_creates_first_seen_record_and_returns_false(): void
    {
        $login = $this->login('alice@example.test');

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/user_profile/profile/checkIsModalShown')
            ->assertOk()
            ->assertExactJson([
                'status' => false,
            ]);

        $this->assertDatabaseHas('default_user_profile_profile', [
            'created_by_id' => 10,
            'updated_by_id' => 10,
        ]);
    }

    public function test_mobile_check_email_modal_returns_true_after_it_was_seen(): void
    {
        Schema::getConnection()->table('default_user_profile_profile')->insert([
            'created_by_id' => 10,
            'updated_by_id' => 10,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $login = $this->login('alice@example.test');

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/user_profile/profile/checkIsModalShown')
            ->assertOk()
            ->assertExactJson([
                'status' => true,
            ]);

        $this->assertSame(1, Schema::getConnection()->table('default_user_profile_profile')->count());
    }

    public function test_mobile_check_email_modal_requires_valid_bearer_token(): void
    {
        $this->postJson('/api/function/user_profile/profile/checkIsModalShown')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_versioned_mobile_check_email_modal_alias_matches_legacy_contract(): void
    {
        $login = $this->login('alice@example.test');

        $this->withToken($login->json('access_token'))
            ->postJson('/api/v1/mobile/profile/email-modal')
            ->assertOk()
            ->assertExactJson([
                'status' => false,
            ]);
    }

    private function login(string $email)
    {
        return $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'email' => $email,
            'password' => 'correct-password',
        ])->assertOk();
    }

    private function createTables(): void
    {
        Schema::create('default_users_users', function ($table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->string('display_name')->nullable();
            $table->boolean('activated')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_user_profile_profile', function ($table): void {
            $table->id();
            $table->integer('sort_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
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
                'activated' => true,
                'enabled' => true,
                'deleted_at' => null,
            ],
        ]);
    }
}

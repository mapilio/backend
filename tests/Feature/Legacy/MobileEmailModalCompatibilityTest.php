<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Schema;
use Tests\Support\LegacyMobileAuthFixtures;
use Tests\TestCase;

class MobileEmailModalCompatibilityTest extends TestCase
{
    use LegacyMobileAuthFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureLegacyMobileAuth();

        $this->createTables();
        $this->seedLegacyUsers(['alice']);
    }

    public function test_mobile_check_email_modal_creates_first_seen_record_and_returns_false(): void
    {
        $login = $this->loginAsLegacyUser('alice');

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

        $login = $this->loginAsLegacyUser('alice');

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
        $login = $this->loginAsLegacyUser('alice');

        $this->withToken($login->json('access_token'))
            ->postJson('/api/v1/mobile/profile/email-modal')
            ->assertOk()
            ->assertExactJson([
                'status' => false,
            ]);
    }

    private function createTables(): void
    {
        $this->createLegacyUsersTable();

        Schema::create('default_user_profile_profile', function ($table): void {
            $table->id();
            $table->integer('sort_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
        });
    }
}

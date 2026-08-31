<?php

namespace Tests\Feature\Legacy;

use App\Domain\IdentityAccess\LegacyMobileAuth;
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

    public function test_mobile_check_email_modal_repeat_call_returns_false_then_true_and_creates_one_record(): void
    {
        $login = $this->loginAsLegacyUser('alice');

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/user_profile/profile/checkIsModalShown')
            ->assertOk()
            ->assertExactJson([
                'status' => false,
            ]);

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/user_profile/profile/checkIsModalShown')
            ->assertOk()
            ->assertExactJson([
                'status' => true,
            ]);

        $this->assertDatabaseHas('default_user_profile_profile', [
            'created_by_id' => 10,
            'updated_by_id' => 10,
        ]);

        $this->assertSame(1, Schema::getConnection()->table('default_user_profile_profile')->count());
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

        $this->postJson('/api/v1/mobile/profile/email-modal')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_mobile_check_email_modal_rejects_stale_auth_after_user_is_deleted(): void
    {
        $login = $this->loginAsLegacyUser('alice');
        $staleUser = Schema::getConnection()->table('default_users_users')->where('id', 10)->first();

        Schema::getConnection()->table('default_users_users')->where('id', 10)->update([
            'deleted_at' => '2026-01-02 00:00:00',
        ]);

        $this->app->instance(LegacyMobileAuth::class, new class($staleUser) extends LegacyMobileAuth
        {
            public function __construct(private readonly object $staleUser) {}

            public function userFromBearer(?string $authorizationHeader): object
            {
                return $this->staleUser;
            }
        });

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/user_profile/profile/checkIsModalShown')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);

        $this->assertSame(0, Schema::getConnection()->table('default_user_profile_profile')->count());
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

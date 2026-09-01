<?php

namespace Tests\Feature\Legacy;

use App\Domain\IdentityAccess\Actions\CheckMobileEmailModal;
use App\Domain\IdentityAccess\LegacyMobileAuth;
use App\Http\Controllers\Legacy\Identity\CheckMobileEmailModalController;
use Illuminate\Http\Request;
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

    public function test_mobile_check_email_modal_aliases_use_mobile_auth_middleware(): void
    {
        foreach (['api.legacy.mobile-profile.email-modal', 'api.v1.mobile.profile.email-modal'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route);
            $this->assertContains('mobile.auth', $route->middleware());
        }
    }

    public function test_mobile_check_email_modal_controller_fails_closed_without_usable_authenticated_user(): void
    {
        $modal = $this->createMock(CheckMobileEmailModal::class);
        $modal->expects($this->never())->method('check');

        foreach ([null, 'not-a-user', (object) [], (object) ['id' => 0], (object) ['id' => 'not-an-id']] as $user) {
            $request = Request::create('/api/v1/mobile/profile/email-modal', 'POST');

            if ($user !== null) {
                $request->attributes->set('mapilio_mobile_user', $user);
            }

            $response = app(CheckMobileEmailModalController::class)($request, $modal);

            $this->assertSame(401, $response->getStatusCode());
            $this->assertSame(['message' => 'Unauthenticated.'], $response->getData(true));
        }
    }

    public function test_mobile_check_email_modal_rejects_stale_auth_after_user_is_deleted(): void
    {
        $login = $this->loginAsLegacyUser('alice');

        Schema::getConnection()->table('default_users_users')->where('id', 10)->update([
            'deleted_at' => '2026-01-02 00:00:00',
        ]);

        $this->withToken($login->json('access_token'))
            ->postJson('/api/function/user_profile/profile/checkIsModalShown')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);

        $this->assertSame(0, Schema::getConnection()->table('default_user_profile_profile')->count());
    }

    public function test_authenticated_email_modal_route_resolves_bearer_once(): void
    {
        $login = $this->loginAsLegacyUser('alice');
        $token = $login->json('access_token');
        $user = Schema::getConnection()->table('default_users_users')->where('id', 10)->first();

        $auth = $this->createMock(LegacyMobileAuth::class);
        $auth->expects($this->once())
            ->method('userFromBearer')
            ->with('Bearer '.$token)
            ->willReturn($user);
        $this->app->instance(LegacyMobileAuth::class, $auth);

        $this->withToken($token)
            ->postJson('/api/function/user_profile/profile/checkIsModalShown')
            ->assertOk()
            ->assertExactJson([
                'status' => false,
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

<?php

namespace Tests\Feature\Legacy;

use App\Notifications\MobileAccountActionNotification;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MobileAccountCompatibilityTest extends TestCase
{
    use WithFaker;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.mobile_auth.signing_key', 'mobile-account-test-key');
        Config::set('mapilio.mobile_accounts.allowed_callback_hosts', ['mapilio.test']);
        Config::set('mapilio.mobile_accounts.allowed_callback_schemes', ['https']);
        Config::set('mapilio.mobile_accounts.verification_fallback_callback', 'https://mapilio.test/app');
        Config::set('mapilio.mobile_accounts.email_verification_callback', 'https://mapilio.test/app');
        Config::set('mapilio.mobile_accounts.profile_photo_disk', 'public');

        $this->createUserTable();
        $this->seedUsers();
    }

    public function test_registration_preserves_the_mobile_payload_and_activates_from_a_signed_email(): void
    {
        Notification::fake();

        $this->postJson('/api/register', [
            'name' => 'New Mapper',
            'username' => 'newmapper',
            'email' => 'NEW@example.test',
            'password' => 'strong-password',
            'callback' => 'https://mapilio.test/app?deeplink=mapilio%3A%2F%2F',
            'success-params' => 'tverification=true',
            'error-params' => 'tverification=false',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $user = Schema::getConnection()->table('default_users_users')->where('username', 'newmapper')->first();
        $this->assertNotNull($user);
        $this->assertSame('new@example.test', $user->email);
        $this->assertFalse((bool) $user->activated);
        $this->assertTrue(Hash::check('strong-password', $user->password));

        $url = $this->notificationUrl('Activate your Mapilio account');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $activationQuery);
        $this->assertSame(hash('sha256', $activationQuery['code']), $user->activation_code);

        $this->get($url)
            ->assertRedirectContains('tverification=true');

        $activated = Schema::getConnection()->table('default_users_users')->where('id', $user->id)->first();
        $this->assertTrue((bool) $activated->activated);
        $this->assertNull($activated->activation_code);
    }

    public function test_registration_rejects_duplicate_identity_and_untrusted_callback(): void
    {
        Notification::fake();

        $base = [
            'name' => 'Alice',
            'username' => 'alice',
            'email' => 'alice@example.test',
            'password' => 'strong-password',
            'callback' => 'https://mapilio.test/app',
            'success-params' => 'tverification=true',
            'error-params' => 'tverification=false',
        ];

        $this->postJson('/api/v1/mobile/accounts', $base)
            ->assertStatus(400)
            ->assertJsonPath('email.0', 'The email has already been taken.')
            ->assertJsonPath('username.0', 'The username has already been taken.');

        $this->postJson('/api/register', array_merge($base, [
            'username' => 'available',
            'email' => 'available@example.test',
            'callback' => 'https://attacker.example/capture',
        ]))->assertStatus(400)
            ->assertJsonPath('callback.0', 'The callback is not allowed.');

        Notification::assertNothingSent();
    }

    public function test_tampered_verification_link_uses_the_fixed_safe_fallback(): void
    {
        Notification::fake();

        $this->postJson('/api/register', [
            'name' => 'Safe Mapper',
            'username' => 'safemapper',
            'email' => 'safe@example.test',
            'password' => 'strong-password',
            'callback' => 'https://mapilio.test/app',
            'success-params' => 'tverification=true',
            'error-params' => 'tverification=false',
        ])->assertOk();

        $tampered = $this->notificationUrl('Activate your Mapilio account').'&callback=https%3A%2F%2Fattacker.example';

        $this->get($tampered)
            ->assertRedirect('https://mapilio.test/app?tverification=false');
    }

    public function test_activation_link_cannot_reenable_an_account_disabled_after_registration(): void
    {
        Notification::fake();

        $this->postJson('/api/register', [
            'name' => 'Disabled Mapper',
            'username' => 'disabledmapper',
            'email' => 'disabled@example.test',
            'password' => 'strong-password',
            'callback' => 'https://mapilio.test/app',
            'success-params' => 'tverification=true',
            'error-params' => 'tverification=false',
        ])->assertOk();

        Schema::getConnection()->table('default_users_users')
            ->where('email', 'disabled@example.test')
            ->update(['enabled' => false]);

        $this->get($this->notificationUrl('Activate your Mapilio account'))
            ->assertRedirectContains('tverification=false');

        $user = Schema::getConnection()->table('default_users_users')
            ->where('email', 'disabled@example.test')
            ->first();
        $this->assertFalse((bool) $user->enabled);
        $this->assertFalse((bool) $user->activated);
    }

    public function test_public_account_aliases_share_registration_and_reset_budgets(): void
    {
        Config::set('mapilio.mobile_accounts.rate_limits.registration', 1);
        Config::set('mapilio.mobile_accounts.rate_limits.password_reset_per_email', 1);
        Config::set('mapilio.mobile_accounts.rate_limits.password_reset_per_ip', 20);

        $this->postJson('/api/register', [])->assertStatus(400);
        $this->postJson('/api/v1/mobile/accounts', [])->assertTooManyRequests();

        $reset = [
            'email' => 'missing@example.test',
            'callback' => 'https://mapilio.test/reset-password',
            'success-params' => 'tverification=true',
            'error-params' => 'tverification=false',
        ];
        $this->postJson('/api/forgot-password', $reset)->assertOk();
        $this->postJson('/api/v1/mobile/password/forgot', $reset)->assertTooManyRequests();
    }

    public function test_authenticated_legacy_and_versioned_profile_writes_share_one_budget(): void
    {
        Config::set('mapilio.mobile_accounts.rate_limits.account_write', 1);
        $token = $this->login('alice@example.test');

        $this->withToken($token)
            ->postJson('/api/function/user_profile/profile/updateProfile', [
                'options' => ['parameters' => ['username' => 'social-user']],
            ])->assertStatus(400);

        $this->withToken($token)
            ->postJson('/api/v1/mobile/profile', ['display_name' => 'Alice Mapper'])
            ->assertTooManyRequests();
    }

    public function test_password_reset_is_non_enumerating_one_time_and_changes_the_password(): void
    {
        Notification::fake();
        $payload = [
            'callback' => 'https://mapilio.test/reset-password',
            'success-params' => 'tverification=true',
            'error-params' => 'tverification=false',
        ];

        $this->postJson('/api/forgot-password', array_merge($payload, [
            'email' => 'missing@example.test',
        ]))->assertExactJson(['success' => true]);

        Notification::assertNothingSent();

        $this->postJson('/api/v1/mobile/password/forgot', array_merge($payload, [
            'email' => 'alice@example.test',
        ]))->assertExactJson(['success' => true]);

        $verificationUrl = $this->notificationUrl('Reset your Mapilio password');
        parse_str((string) parse_url($verificationUrl, PHP_URL_QUERY), $resetQuery);
        $storedResetCode = Schema::getConnection()->table('default_users_users')->where('id', 10)->value('reset_code');
        $this->assertSame(hash('sha256', $resetQuery['code']), $storedResetCode);
        $redirect = $this->get($verificationUrl)
            ->assertRedirectContains('tverification=true')
            ->headers->get('Location');
        $this->assertIsString($redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

        $this->postJson('/api/renew-password', [
            'code' => $query['code'],
            'password' => 'new-strong-password',
            're-password' => 'new-strong-password',
        ])->assertExactJson(['success' => true]);

        $user = Schema::getConnection()->table('default_users_users')->where('id', 10)->first();
        $this->assertTrue(Hash::check('new-strong-password', $user->password));
        $this->assertNull($user->reset_code);

        $this->postJson('/api/v1/mobile/password/reset', [
            'code' => $query['code'],
            'password' => 'another-password',
            're-password' => 'another-password',
        ])->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_profile_update_accepts_the_existing_nested_form_and_a_safe_photo(): void
    {
        Storage::fake('public');
        $token = $this->login('alice@example.test');

        $response = $this->withToken($token)->post('/api/function/user_profile/profile/updateProfile', [
            'options' => [
                'parameters' => [
                    'user_bio' => 'Fresh roads and careful mapping.',
                    'display_name' => 'Alice Mapper',
                    'username' => 'alice-mapper',
                    'user_profile_photo' => UploadedFile::fake()->image('avatar.jpg', 256, 256),
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('response.username', 'alice-mapper')
            ->assertJsonPath('response.display_name', 'Alice Mapper');

        $user = Schema::getConnection()->table('default_users_users')->where('id', 10)->first();
        $this->assertSame('Fresh roads and careful mapping.', $user->user_bio);
        $this->assertStringContainsString('/storage/profile-photos/10/', $user->user_profile_photo);
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $user->user_profile_photo));
    }

    public function test_profile_writes_require_authentication_and_reject_duplicate_usernames(): void
    {
        $this->postJson('/api/function/user_profile/profile/updateProfile', [
            'options' => ['parameters' => ['username' => 'alice']],
        ])->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->withToken($this->login('alice@example.test'))
            ->postJson('/api/v1/mobile/profile', ['username' => 'social-user'])
            ->assertStatus(400)
            ->assertJsonPath('message.username.0', 'The username has already been taken.');
    }

    public function test_provider_email_change_is_applied_only_after_signed_confirmation(): void
    {
        Notification::fake();
        $token = $this->login('social@12345.invalid');

        $this->withToken($token)
            ->post('/api/function/user_profile/profile/updateMail', [
                'options' => ['parameters' => ['email' => 'social@example.test']],
            ])->assertOk()
            ->assertJsonPath('status', true);

        $before = Schema::getConnection()->table('default_users_users')->where('id', 11)->value('email');
        $this->assertSame('social@12345.invalid', $before);

        $this->get($this->notificationUrl('Confirm your Mapilio email'))
            ->assertRedirectContains('tverification=true');

        $after = Schema::getConnection()->table('default_users_users')->where('id', 11)->value('email');
        $this->assertSame('social@example.test', $after);
    }

    public function test_normal_account_cannot_use_the_provider_email_change_flow(): void
    {
        Notification::fake();

        $this->withToken($this->login('alice@example.test'))
            ->postJson('/api/v1/mobile/profile/email', ['email' => 'other@example.test'])
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        Notification::assertNothingSent();
    }

    public function test_default_account_deletion_anonymizes_identity_preserves_the_row_and_invalidates_tokens(): void
    {
        $token = $this->login('alice@example.test');

        $this->withToken($token)
            ->postJson('/api/function/user_profile/profile/delete-account', [
                'options' => ['parameters' => ['delete' => true, 'login_type' => 'default']],
            ])->assertOk()
            ->assertJsonPath('response.success', true);

        $user = Schema::getConnection()->table('default_users_users')->where('id', 10)->first();
        $this->assertNotNull($user);
        $this->assertSame('Deleted User', $user->display_name);
        $this->assertStringEndsWith('@deleted.invalid', $user->email);
        $this->assertFalse((bool) $user->enabled);
        $this->assertFalse((bool) $user->activated);
        $this->assertNull($user->user_bio);
        $this->assertStringNotContainsString('alice@example.test', (string) $user->reason_for_closing_account);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/profile')
            ->assertUnauthorized();
    }

    public function test_apple_deletion_fails_closed_when_provider_revocation_fails(): void
    {
        Http::fake([
            'appleid.apple.com/*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);
        Config::set('mapilio.mobile_accounts.apple.team_id', 'TEAM123');
        Config::set('mapilio.mobile_accounts.apple.client_id', 'com.mapilio.main');
        Config::set('mapilio.mobile_accounts.apple.key_id', 'KEY123');
        Config::set('mapilio.mobile_accounts.apple.private_key_path', '/missing/apple-key.p8');

        $this->withToken($this->login('alice@example.test'))
            ->deleteJson('/api/v1/mobile/account', [
                'delete' => true,
                'login_type' => 'apple',
                'auth_code' => 'one-time-code',
            ])->assertStatus(503)
            ->assertJsonPath('success', false);

        $this->assertTrue((bool) Schema::getConnection()
            ->table('default_users_users')
            ->where('id', 10)
            ->value('enabled'));
    }

    public function test_apple_deletion_exchanges_and_revokes_the_authorization_before_anonymizing(): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        $path = tempnam(sys_get_temp_dir(), 'mapilio-apple-key-');
        $this->assertIsString($path);
        file_put_contents($path, $privateKey);

        Config::set('mapilio.mobile_accounts.apple.team_id', 'TEAM123');
        Config::set('mapilio.mobile_accounts.apple.client_id', 'com.mapilio.main');
        Config::set('mapilio.mobile_accounts.apple.key_id', 'KEY123');
        Config::set('mapilio.mobile_accounts.apple.private_key_path', $path);
        Http::fakeSequence()
            ->push(['access_token' => 'apple-access-token'], 200)
            ->push([], 200);

        try {
            $this->withToken($this->login('alice@example.test'))
                ->deleteJson('/api/v1/mobile/account', [
                    'delete' => true,
                    'login_type' => 'apple',
                    'auth_code' => 'one-time-code',
                ])->assertOk()
                ->assertJsonPath('response.success', true);
        } finally {
            unlink($path);
        }

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://appleid.apple.com/auth/token'
            && $request['code'] === 'one-time-code'
            && substr_count((string) $request['client_secret'], '.') === 2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://appleid.apple.com/auth/revoke'
            && $request['token'] === 'apple-access-token');
        $this->assertFalse((bool) Schema::getConnection()
            ->table('default_users_users')
            ->where('id', 10)
            ->value('enabled'));
    }

    private function createUserTable(): void
    {
        Schema::create('default_users_users', function ($table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('username')->unique();
            $table->string('password');
            $table->string('display_name');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->boolean('activated')->default(false);
            $table->boolean('enabled')->default(true);
            $table->string('activation_code')->nullable();
            $table->string('reset_code')->nullable();
            $table->string('remember_token')->nullable();
            $table->string('str_id')->unique();
            $table->string('user_profile_photo')->nullable();
            $table->text('user_bio')->nullable();
            $table->text('reason_for_closing_account')->nullable();
            $table->string('referrer')->nullable();
            $table->text('auth_token')->nullable();
            $table->boolean('hidden_profile')->default(false);
            $table->integer('updated_by_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
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
                'first_name' => 'Alice',
                'activated' => true,
                'enabled' => true,
                'str_id' => 'alice-id',
                'user_bio' => 'Mapping roads.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 11,
                'email' => 'social@12345.invalid',
                'username' => 'social-user',
                'password' => Hash::make('social-password'),
                'display_name' => 'Social User',
                'first_name' => 'Social',
                'activated' => true,
                'enabled' => true,
                'str_id' => 'social-id',
                'user_bio' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function login(string $email): string
    {
        $password = $email === 'alice@example.test' ? 'correct-password' : 'social-password';

        return (string) $this->postJson('/api/v1/mobile/auth/public-token', [
            'grant_type' => 'password',
            'email' => $email,
            'password' => $password,
        ])->assertOk()->json('access_token');
    }

    private function notificationUrl(string $subject): string
    {
        $url = null;

        Notification::assertSentOnDemand(
            MobileAccountActionNotification::class,
            function (MobileAccountActionNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($subject, &$url): bool {
                $message = $notification->toMail($notifiable);

                if ($message->subject !== $subject) {
                    return false;
                }

                $url = $message->actionUrl;

                return true;
            },
        );

        $this->assertIsString($url);

        return $url;
    }
}

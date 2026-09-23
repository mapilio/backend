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
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('socialDeletionRoutes')]
    public function test_social_deletion_revokes_before_anonymizing_and_removes_only_the_matched_link(string $provider, string $method, string $route): void
    {
        $this->createSocialLinks($provider);
        $token = $this->login('alice@example.test');
        Http::preventStrayRequests();
        Http::fake(function ($request, $options) use ($provider) {
            $this->assertAccountUnchanged();
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(3, $options['connect_timeout']);
            $this->assertSame(8, $options['timeout']);

            if ($request->url() === 'https://openidconnect.googleapis.com/v1/userinfo') {
                $this->assertSame('GET', $request->method());
                $this->assertSame(['Bearer synthetic-google-token'], $request->header('Authorization'));

                return Http::response(['sub' => '123456']);
            }

            if ($provider === 'google') {
                $this->assertSame('https://oauth2.googleapis.com/revoke', $request->url());
                $this->assertSame('POST', $request->method());
                $this->assertSame('synthetic-google-token', $request['token']);
            } else {
                $this->assertSame('https://graph.facebook.com/v24.0/123456/permissions', $request->url());
                $this->assertSame('DELETE', $request->method());
                $this->assertSame(['Bearer synthetic-facebook-app-token'], $request->header('Authorization'));
            }

            return Http::response(['success' => true]);
        });

        $parameters = ['delete' => true, 'login_type' => $provider];
        if ($provider === 'google') {
            $parameters['provider_token'] = 'synthetic-google-token';
        }
        // Caller-supplied identities and app credentials must never choose the target.
        $parameters += ['user_id' => 11, 'uid' => '999999', 'app_access_token' => 'untrusted'];
        $payload = $method === 'POST' ? ['options' => ['parameters' => $parameters]] : $parameters;
        $response = $this->withToken($token)->json($method, $route, $payload)
            ->assertOk()->assertJsonPath('response.success', true);
        $this->assertStringNotContainsString('synthetic-', $response->getContent());
        Http::assertSentCount($provider === 'google' ? 2 : 1);

        $connection = Schema::getConnection();
        $this->assertFalse((bool) $connection->table('default_users_users')->where('id', 10)->value('enabled'));
        $this->assertSame(0, $connection->table('default_social_authentications')->where('id', 1)->count());
        $this->assertSame(2, $connection->table('default_social_authentications')->count());
        $this->assertStringNotContainsString('synthetic-', $connection->table('default_users_users')->where('id', 10)->value('reason_for_closing_account'));
        $this->withToken($token)->getJson('/api/v1/mobile/profile')->assertUnauthorized();
    }

    /** @return list<array{string, string, string}> */
    public static function socialDeletionRoutes(): array
    {
        return [
            ['google', 'DELETE', '/api/v1/mobile/account'],
            ['google', 'POST', '/api/function/user_profile/profile/delete-account'],
            ['facebook', 'DELETE', '/api/v1/mobile/account'],
            ['facebook', 'POST', '/api/function/user_profile/profile/delete-account'],
        ];
    }

    /** @param array<string, mixed> $profile */
    #[DataProvider('invalidGoogleProfiles')]
    public function test_google_deletion_does_not_revoke_a_different_or_unverified_identity(array $profile, int $upstreamStatus, int $expectedStatus): void
    {
        $this->createSocialLinks('google');
        Http::fake(['openidconnect.googleapis.com/*' => Http::response($profile, $upstreamStatus)]);
        $this->withToken($this->login('alice@example.test'))->deleteJson('/api/v1/mobile/account', [
            'delete' => true, 'login_type' => 'google', 'provider_token' => 'synthetic-google-token',
        ])->assertStatus($expectedStatus)->assertJsonPath('success', false);
        Http::assertSentCount(1);
        $this->assertAccountUnchanged();
    }

    /** @return array<string, array{array<string, mixed>, int, int}> */
    public static function invalidGoogleProfiles(): array
    {
        return [
            'another account' => [['sub' => '999999'], 200, 403],
            'missing subject' => [[], 200, 503],
            'malformed subject' => [['sub' => ['123456']], 200, 503],
            'expired token' => [['error' => 'invalid_token'], 401, 403],
            'upstream outage' => [[], 500, 503],
            'redirect rejected' => [[], 302, 503],
        ];
    }

    #[DataProvider('failedSocialRevocations')]
    public function test_social_provider_failures_preserve_the_account_and_link(string $provider, mixed $body, int $status): void
    {
        $this->createSocialLinks($provider);
        Http::fake([
            'openidconnect.googleapis.com/*' => Http::response(['sub' => '123456']),
            'oauth2.googleapis.com/*' => Http::response($body, $status),
            'graph.facebook.com/*' => Http::response($body, $status),
        ]);
        $this->withToken($this->login('alice@example.test'))->deleteJson('/api/v1/mobile/account', [
            'delete' => true, 'login_type' => $provider, 'provider_token' => 'synthetic-google-token',
        ])->assertStatus(503)->assertJsonPath('success', false);
        $this->assertAccountUnchanged();
    }

    /** @return list<array{string, array<string, mixed>, int}> */
    public static function failedSocialRevocations(): array
    {
        return [
            ['google', ['error' => 'invalid_token'], 400],
            ['google', [], 500],
            ['google', [], 302],
            ['facebook', ['error' => ['message' => 'Do not expose provider errors']], 400],
            ['facebook', ['success' => false], 200],
            ['facebook', ['success' => 'true'], 200],
            ['facebook', [], 200],
            ['facebook', [], 500],
            ['facebook', [], 302],
        ];
    }

    public function test_social_revocation_connection_failure_is_generic_and_preserves_the_account(): void
    {
        $this->createSocialLinks('facebook');
        Http::fake(['*' => Http::failedConnection('sensitive-upstream-message')]);
        $response = $this->withToken($this->login('alice@example.test'))->deleteJson('/api/v1/mobile/account', [
            'delete' => true, 'login_type' => 'facebook',
        ])->assertStatus(503)->assertJsonPath('success', false);
        $this->assertStringNotContainsString('sensitive-upstream-message', $response->getContent());
        $this->assertAccountUnchanged();
    }

    #[DataProvider('unavailableSocialLinks')]
    public function test_social_deletion_requires_one_unambiguous_configured_account_link(string $scenario, int $status): void
    {
        $this->createSocialLinks('facebook');
        $links = Schema::getConnection()->table('default_social_authentications');
        match ($scenario) {
            'unconfigured' => Config::set('mapilio.mobile_accounts.social.facebook.legacy_provider', ''),
            'missing app token' => Config::set('mapilio.mobile_accounts.social.facebook.app_access_token', ''),
            'invalid version' => Config::set('mapilio.mobile_accounts.social.facebook.graph_version', '../../me'),
            'missing link' => $links->where('id', 1)->delete(),
            'invalid uid' => $links->where('id', 1)->update(['uid' => '../999999']),
            'ambiguous links' => $links->insert(['id' => 4, 'user_id' => 10, 'provider' => 'test.facebook', 'uid' => '888888', 'application' => false]),
            default => throw new \LogicException('Unknown test scenario.'),
        };
        Http::fake();
        $this->withToken($this->login('alice@example.test'))->deleteJson('/api/v1/mobile/account', [
            'delete' => true, 'login_type' => 'facebook',
        ])->assertStatus($status);
        Http::assertNothingSent();
        $this->assertTrue((bool) Schema::getConnection()->table('default_users_users')->where('id', 10)->value('enabled'));
    }

    /** @return list<array{string, int}> */
    public static function unavailableSocialLinks(): array
    {
        return [
            ['unconfigured', 503], ['missing app token', 503], ['invalid version', 503],
            ['missing link', 409], ['invalid uid', 409], ['ambiguous links', 409],
        ];
    }

    public function test_social_deletion_rejects_missing_confirmation_token_or_authentication_without_provider_calls(): void
    {
        Config::set('mapilio.mobile_accounts.rate_limits.account_delete', 10);
        $this->createSocialLinks('google');
        Http::fake();
        $this->deleteJson('/api/v1/mobile/account', ['delete' => true, 'login_type' => 'facebook'])->assertUnauthorized();
        $this->withToken($this->login('alice@example.test'));
        foreach ([
            ['delete' => false, 'login_type' => 'facebook'],
            ['delete' => true, 'login_type' => 'google'],
            ['delete' => true, 'login_type' => 'google', 'provider_token' => str_repeat('x', 8193)],
        ] as $parameters) {
            $this->deleteJson('/api/v1/mobile/account', $parameters)->assertStatus(400);
        }
        Http::assertNothingSent();
        $this->assertAccountUnchanged();
    }

    public function test_account_is_not_anonymized_when_the_link_changes_during_revocation(): void
    {
        $this->createSocialLinks('facebook');
        Http::fake(function () {
            Schema::getConnection()->table('default_social_authentications')->where('id', 1)->update(['uid' => '654321']);

            return Http::response('true', 200, ['Content-Type' => 'application/json']);
        });
        $this->withToken($this->login('alice@example.test'))->deleteJson('/api/v1/mobile/account', [
            'delete' => true, 'login_type' => 'facebook',
        ])->assertStatus(409);
        $this->assertAccountUnchanged();
    }

    public function test_facebook_accepts_the_documented_boolean_success_body(): void
    {
        $this->createSocialLinks('facebook');
        Http::fake(['graph.facebook.com/*' => Http::response('true', 200, ['Content-Type' => 'application/json'])]);
        $this->withToken($this->login('alice@example.test'))->deleteJson('/api/v1/mobile/account', [
            'delete' => true, 'login_type' => 'facebook',
        ])->assertOk()->assertJsonPath('response.success', true);
        Http::assertSentCount(1);
    }

    public function test_social_link_removal_rolls_back_if_account_anonymization_fails(): void
    {
        $this->createSocialLinks('facebook');
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        Schema::getConnection()->statement("CREATE TRIGGER reject_delete BEFORE UPDATE ON default_users_users WHEN NEW.enabled = 0 BEGIN SELECT RAISE(ABORT, 'synthetic database failure'); END");
        $this->withToken($this->login('alice@example.test'))->deleteJson('/api/v1/mobile/account', [
            'delete' => true, 'login_type' => 'facebook',
        ])->assertStatus(500);
        $this->assertAccountUnchanged();
    }

    private function createSocialLinks(string $provider): void
    {
        Config::set("mapilio.mobile_accounts.social.{$provider}.legacy_provider", "test.{$provider}");
        Config::set('mapilio.mobile_accounts.social.facebook.app_access_token', 'synthetic-facebook-app-token');
        Config::set('mapilio.mobile_accounts.social.facebook.graph_version', 'v24.0');
        Schema::create('default_social_authentications', function ($table): void {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->string('provider');
            $table->string('uid');
            $table->boolean('application');
        });
        Schema::getConnection()->table('default_social_authentications')->insert([
            ['id' => 1, 'user_id' => 10, 'provider' => "test.{$provider}", 'uid' => '123456', 'application' => false],
            ['id' => 2, 'user_id' => 11, 'provider' => "test.{$provider}", 'uid' => '999999', 'application' => false],
            ['id' => 3, 'user_id' => 10, 'provider' => "test.{$provider}", 'uid' => '222222', 'application' => true],
        ]);
    }

    private function assertAccountUnchanged(): void
    {
        $connection = Schema::getConnection();
        $user = $connection->table('default_users_users')->where('id', 10)->first();
        $this->assertTrue((bool) $user->enabled);
        $this->assertSame('alice@example.test', $user->email);
        $this->assertSame(1, $connection->table('default_social_authentications')->where('id', 1)->count());
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

<?php

namespace Tests\Feature\Legacy;

use App\Domain\IdentityAccess\Queries\MobileProfileQuery;
use App\Http\Controllers\Legacy\Identity\MobileProfileController;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
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
        Config::set('mapilio.mobile_auth.rate_limits.password', 10);
        Config::set('mapilio.mobile_auth.rate_limits.refresh', 30);
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
            ->assertJsonPath('id', 10)
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

    public function test_unknown_mobile_accounts_use_the_dummy_hash_once_on_both_legacy_routes(): void
    {
        $dummyHash = (string) config('mapilio.mobile_auth.dummy_password_hash');
        Hash::shouldReceive('check')
            ->twice()
            ->with('wrong-password', $dummyHash)
            ->andReturn(false);

        foreach (['/api/v2/login', '/api/v1/mobile/auth/token'] as $path) {
            $this->postJson($path, [
                'grant_type' => 'password',
                'client_id' => 'mobile-client',
                'client_secret' => 'mobile-secret',
                'email' => 'unknown@example.test',
                'password' => 'wrong-password',
            ])
                ->assertStatus(400)
                ->assertExactJson([
                    'success' => false,
                    'message' => ['Email or password is invalid.'],
                ]);
        }
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

        $expected = $this->expectedMobileProfileResponse();

        foreach (['/api/function/user_profile/profile/getProfile', '/api/v1/mobile/profile'] as $path) {
            $this->withToken($login->json('access_token'))
                ->getJson($path)
                ->assertOk()
                ->assertExactJson($expected);
        }
    }

    public function test_successful_mobile_profile_request_uses_four_sqlite_schema_metadata_queries(): void
    {
        $login = $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ])->assertOk();

        $metadataQueries = 0;
        Schema::getConnection()->listen(static function (QueryExecuted $query) use (&$metadataQueries): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'sqlite_master') || str_contains($sql, 'pragma_table_')) {
                $metadataQueries++;
            }
        });

        $this->withToken($login->json('access_token'))
            ->getJson('/api/function/user_profile/profile/getProfile')
            ->assertOk()
            ->assertExactJson($this->expectedMobileProfileResponse());

        $this->assertSame(4, $metadataQueries);
    }

    public function test_mobile_profile_endpoints_require_valid_bearer(): void
    {
        foreach (['/api/function/user_profile/profile/getProfile', '/api/v1/mobile/profile'] as $path) {
            $this->getJson($path)
                ->assertUnauthorized()
                ->assertExactJson([
                    'message' => 'Unauthenticated.',
                ]);
        }
    }

    public function test_mobile_profile_aliases_use_mobile_auth_middleware(): void
    {
        foreach (['api.legacy.mobile-profile', 'api.v1.mobile.profile'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route);
            $this->assertContains('mobile.auth', $route->middleware());
        }
    }

    public function test_mobile_profile_controller_fails_closed_without_usable_authenticated_user(): void
    {
        $query = $this->createMock(MobileProfileQuery::class);
        $query->expects($this->never())->method('get');

        foreach ([null, 'not-a-user', (object) [], (object) ['id' => 0], (object) ['id' => 'not-an-id']] as $user) {
            $request = Request::create('/api/v1/mobile/profile', 'GET');

            if ($user !== null) {
                $request->attributes->set('mapilio_mobile_user', $user);
            }

            $response = app(MobileProfileController::class)($request, $query);

            $this->assertSame(401, $response->getStatusCode());
            $this->assertSame(['message' => 'Unauthenticated.'], $response->getData(true));
        }
    }

    public function test_legacy_onesignal_identity_verification_failure_remains_server_error(): void
    {
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

    public function test_legacy_onesignal_identity_verification_preserves_authenticated_email_failures(): void
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
                    'parameters' => [],
                ],
            ])->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => ['Verification failed.'],
            ]);

        $this->withToken($login->json('access_token'))
            ->postJson('/api/onesignal/identity-verification', [
                'options' => [
                    'parameters' => [
                        'email' => 'bob@example.test',
                    ],
                ],
            ])->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => ['Verification failed.'],
            ]);
    }

    public function test_versioned_onesignal_identity_verification_rejects_missing_and_invalid_bearer(): void
    {
        $payload = [
            'options' => [
                'parameters' => [
                    'email' => 'alice@example.test',
                ],
            ],
        ];

        $this->postJson('/api/v1/mobile/onesignal/identity-verification', $payload)
            ->assertStatus(401)
            ->assertExactJson([
                'success' => false,
                'message' => ['Verification failed.'],
            ]);

        $this->withToken('malformed-mobile-access-token')
            ->postJson('/api/v1/mobile/onesignal/identity-verification', $payload)
            ->assertStatus(401)
            ->assertExactJson([
                'success' => false,
                'message' => ['Verification failed.'],
            ]);
    }

    public function test_versioned_onesignal_identity_verification_hides_authenticated_email_failures(): void
    {
        $login = $this->postJson('/api/v2/login', [
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ])->assertOk();

        $this->withToken($login->json('access_token'))
            ->postJson('/api/v1/mobile/onesignal/identity-verification', [
                'options' => [
                    'parameters' => [
                        'email' => 'bob@example.test',
                    ],
                ],
            ])->assertStatus(401)
            ->assertExactJson([
                'success' => false,
                'message' => ['Verification failed.'],
            ]);

        $this->withToken($login->json('access_token'))
            ->postJson('/api/v1/mobile/onesignal/identity-verification', [
                'options' => [
                    'parameters' => [],
                ],
            ])->assertStatus(401)
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

        $this->withToken($login->json('access_token'))
            ->postJson('/api/v1/mobile/onesignal/identity-verification', [
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

    public function test_mobile_auth_aliases_share_the_password_bucket(): void
    {
        Config::set('mapilio.mobile_auth.rate_limits.password', 2);

        $this->mobileAuthRequest('/api/v2/login', '198.51.100.10')->assertStatus(400);
        $this->mobileAuthRequest('/api/v1/mobile/auth/token', '198.51.100.10')->assertStatus(400);

        $this->mobileAuthRequest('/api/v2/login', '198.51.100.10')
            ->assertTooManyRequests();
    }

    public function test_switching_mobile_auth_aliases_cannot_bypass_the_password_limit(): void
    {
        Config::set('mapilio.mobile_auth.rate_limits.password', 3);

        foreach (['/api/v2/login', '/api/v1/mobile/auth/token', '/api/v2/login'] as $path) {
            $this->mobileAuthRequest($path, '198.51.100.11')->assertStatus(400);
        }

        $this->mobileAuthRequest('/api/v1/mobile/auth/token', '198.51.100.11')
            ->assertTooManyRequests();
    }

    public function test_malformed_password_rate_limits_use_the_password_default(): void
    {
        foreach (['not-a-number', true, 1.5, '1.5', '', null] as $index => $configuredLimit) {
            Config::set('mapilio.mobile_auth.rate_limits.password', $configuredLimit);

            $this->mobileAuthRequest('/api/v2/login', '198.51.100.'.(30 + $index))
                ->assertStatus(400)
                ->assertHeader('X-RateLimit-Limit', '10');
        }
    }

    public function test_malformed_refresh_rate_limits_use_the_refresh_default(): void
    {
        $login = $this->mobileAuthRequest('/api/v2/login', '198.51.100.40', [
            'password' => 'correct-password',
        ])->assertOk();
        $refresh = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $login->json('refresh_token'),
        ];

        foreach (['not-a-number', false, 1.5, '1.5', '', null] as $index => $configuredLimit) {
            Config::set('mapilio.mobile_auth.rate_limits.refresh', $configuredLimit);

            $this->mobileAuthRequest('/api/v1/mobile/auth/token', '198.51.100.'.(50 + $index), $refresh)
                ->assertOk()
                ->assertHeader('X-RateLimit-Limit', '30');
        }
    }

    public function test_password_rate_limits_clamp_zero_negative_and_oversized_values(): void
    {
        foreach ([[0, 1], [-5, 1], [1001, 1000], [5000, 1000], ['0', 1], ['-5', 1], ['1001', 1000], ['5000', 1000]] as $index => [$configuredLimit, $expectedLimit]) {
            Config::set('mapilio.mobile_auth.rate_limits.password', $configuredLimit);

            $this->mobileAuthRequest('/api/v2/login', '198.51.100.'.(80 + $index))
                ->assertStatus(400)
                ->assertHeader('X-RateLimit-Limit', (string) $expectedLimit);
        }
    }

    public function test_refresh_rate_limits_clamp_zero_negative_and_oversized_values(): void
    {
        $login = $this->mobileAuthRequest('/api/v2/login', '198.51.100.70', [
            'password' => 'correct-password',
        ])->assertOk();
        $refresh = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $login->json('refresh_token'),
        ];

        foreach ([[0, 1], [-5, 1], [1001, 1000], [5000, 1000], ['0', 1], ['-5', 1], ['1001', 1000], ['5000', 1000]] as $index => [$configuredLimit, $expectedLimit]) {
            Config::set('mapilio.mobile_auth.rate_limits.refresh', $configuredLimit);

            $this->mobileAuthRequest('/api/v1/mobile/auth/token', '198.51.100.'.(90 + $index), $refresh)
                ->assertOk()
                ->assertHeader('X-RateLimit-Limit', (string) $expectedLimit);
        }
    }

    public function test_mobile_password_and_refresh_budgets_are_independent(): void
    {
        Config::set('mapilio.mobile_auth.rate_limits.password', 2);
        Config::set('mapilio.mobile_auth.rate_limits.refresh', 2);

        $login = $this->mobileAuthRequest('/api/v2/login', '198.51.100.12', [
            'email' => 'alice@example.test',
            'password' => 'correct-password',
        ])->assertOk();
        $refresh = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $login->json('refresh_token'),
        ];

        $this->mobileAuthRequest('/api/v1/mobile/auth/token', '198.51.100.12', $refresh)->assertOk();
        $this->mobileAuthRequest('/api/v2/login', '198.51.100.12', $refresh)->assertOk();
        $this->mobileAuthRequest('/api/v1/mobile/auth/token', '198.51.100.12', $refresh)
            ->assertTooManyRequests();

        $this->mobileAuthRequest('/api/v1/mobile/auth/token', '198.51.100.12')->assertStatus(400);
    }

    public function test_mobile_auth_budgets_are_independent_per_resolved_client_ip(): void
    {
        Config::set('mapilio.mobile_auth.rate_limits.password', 1);

        $this->mobileAuthRequest('/api/v2/login', '198.51.100.13')->assertStatus(400);
        $this->mobileAuthRequest('/api/v1/mobile/auth/token', '198.51.100.14')->assertStatus(400);

        $this->mobileAuthRequest('/api/v2/login', '198.51.100.13')->assertTooManyRequests();
        $this->mobileAuthRequest('/api/v1/mobile/auth/token', '198.51.100.14')->assertTooManyRequests();
    }

    public function test_mobile_auth_rate_limit_returns_stable_legacy_json_and_retry_header(): void
    {
        Config::set('mapilio.mobile_auth.rate_limits.password', 1);

        $this->mobileAuthRequest('/api/v2/login', '198.51.100.15')->assertStatus(400);

        $this->mobileAuthRequest('/api/v1/mobile/auth/token', '198.51.100.15')
            ->assertStatus(429)
            ->assertExactJson([
                'success' => false,
                'message' => ['Too many authentication attempts. Please try again later.'],
            ])
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '1')
            ->assertHeader('X-RateLimit-Remaining', '0');
    }

    public function test_mobile_auth_rate_limit_allows_a_request_after_the_window_resets(): void
    {
        Config::set('mapilio.mobile_auth.rate_limits.password', 1);

        $this->mobileAuthRequest('/api/v2/login', '198.51.100.16')->assertStatus(400);
        $this->mobileAuthRequest('/api/v2/login', '198.51.100.16')->assertTooManyRequests();

        $this->travel(61)->seconds();

        $this->mobileAuthRequest('/api/v1/mobile/auth/token', '198.51.100.16')->assertStatus(400);
    }

    public function test_mobile_auth_uses_trusted_forwarded_ip_but_ignores_untrusted_forwarding(): void
    {
        Config::set('mapilio.mobile_auth.rate_limits.password', 1);

        $this->mobileAuthRequest('/api/v2/login', '203.0.113.20', [], '198.51.100.20')->assertStatus(400);
        $this->mobileAuthRequest('/api/v1/mobile/auth/token', '203.0.113.20', [], '198.51.100.21')
            ->assertTooManyRequests();

        $this->mobileAuthRequest('/api/v2/login', '192.0.2.20', [], '198.51.100.22')->assertStatus(400);
        $this->mobileAuthRequest('/api/v1/mobile/auth/token', '192.0.2.20', [], '198.51.100.23')->assertStatus(400);
        $this->mobileAuthRequest('/api/v2/login', '192.0.2.20', [], '198.51.100.22')
            ->assertTooManyRequests();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function mobileAuthRequest(
        string $path,
        string $remoteAddress,
        array $overrides = [],
        ?string $forwardedFor = null,
    ): TestResponse {
        $payload = array_merge([
            'grant_type' => 'password',
            'client_id' => 'mobile-client',
            'client_secret' => 'mobile-secret',
            'email' => 'alice@example.test',
            'password' => 'wrong-password',
        ], $overrides);

        $request = $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress]);

        if ($forwardedFor !== null) {
            $request = $request->withHeader('X-Forwarded-For', $forwardedFor);
        }

        return $request->postJson($path, $payload);
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

    /**
     * @return array{data: list<array<string, mixed>>}
     */
    private function expectedMobileProfileResponse(): array
    {
        return [
            'data' => [[
                'id' => 10,
                'username' => 'alice',
                'email' => 'alice@example.test',
                'user_profile_photo' => 'https://mapilio.test/default-avatar.png',
                'display_name' => 'Alice Example',
                'str_id' => 'alice-key',
                'hidden_profile' => 0,
                'user_bio' => 'Mapping roads.',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-02 00:00:00',
                'shape_limit' => 100,
                'isAdmin' => true,
                'sequences' => 2,
                'photos' => 3,
                'meters' => '4.000',
                'score' => '16',
            ]],
        ];
    }
}

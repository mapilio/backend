<?php

namespace Tests\Feature\Legacy;

use App\Domain\IdentityAccess\LegacyMobileAuth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ImageReportCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.mobile_auth.client_id', 'mobile-client');
        Config::set('mapilio.mobile_auth.client_secret', 'mobile-secret');
        Config::set('mapilio.mobile_auth.signing_key', 'test-signing-key');
        Config::set('mapilio.mobile_auth.revocation.enabled', true);
        Config::set('mapilio.imagery_reports.max_message_length', 2000);
        Config::set('mapilio.imagery_reports.rate_limit', 10);

        $this->createTables();
        $this->seedImagery();
        $this->seedUsers();
    }

    public function test_image_report_accepts_mobile_nested_payload_and_records_authenticated_user(): void
    {
        $login = $this->login();

        $response = $this->withToken($login->json('access_token'))
            ->postJson('/api/image-report', [
                'options' => [
                    'parameters' => [
                        'imagery_id' => 123,
                        'message' => 'Privacy Violation',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.imagery_id', 123)
            ->assertJsonPath('data.description', 'Privacy Violation')
            ->assertJsonPath('data.created_by_id', 10)
            ->assertJsonPath('data.updated_by_id', 10);

        $data = $response->json('data');
        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame([
            'id',
            'sort_order',
            'created_at',
            'created_by_id',
            'updated_at',
            'updated_by_id',
            'deleted_at',
            'imagery_id',
            'description',
        ], array_keys($data));
        $this->assertIsInt($data['id']);
        $this->assertIsString($data['created_at']);
        $this->assertIsString($data['updated_at']);
        $this->assertNull($data['sort_order']);
        $this->assertNull($data['deleted_at']);

        $this->assertDatabaseHas('default_image_complaint_complaint', [
            'imagery_id' => 123,
            'description' => 'Privacy Violation',
            'created_by_id' => 10,
        ]);
    }

    public function test_image_report_keeps_legacy_flat_payload_compatibility(): void
    {
        $this->postJson('/api/image-report', [
            'imagery_id' => 456,
            'message' => 'Low Quality',
        ])
            ->assertOk()
            ->assertJsonPath('data.imagery_id', 456)
            ->assertJsonPath('data.description', 'Low Quality')
            ->assertJsonPath('data.created_by_id', null);

        $this->assertDatabaseHas('default_image_complaint_complaint', [
            'imagery_id' => 456,
            'description' => 'Low Quality',
            'created_by_id' => null,
        ]);
    }

    public function test_image_report_preserves_missing_parameter_error_shape(): void
    {
        $this->postJson('/api/image-report')
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'imagery_id' is required!"],
                'error_code' => 400,
            ]);

        $this->postJson('/api/image-report', [
            'imagery_id' => 123,
        ])
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'message' is required!"],
                'error_code' => 400,
            ]);
    }

    public function test_versioned_image_report_alias_preserves_negative_contract_without_writing_complaints(): void
    {
        foreach (['/api/image-report', '/api/v1/imagery/reports'] as $path) {
            $this->postJson($path)
                ->assertStatus(400)
                ->assertExactJson([
                    'success' => false,
                    'message' => ["'imagery_id' is required!"],
                    'error_code' => 400,
                ]);
        }

        foreach (['/api/image-report', '/api/v1/imagery/reports'] as $path) {
            $this->postJson($path, ['imagery_id' => 123])
                ->assertStatus(400)
                ->assertExactJson([
                    'success' => false,
                    'message' => ["'message' is required!"],
                    'error_code' => 400,
                ]);
        }

        $this->assertSame(0, Schema::getConnection()->table('default_image_complaint_complaint')->count());
    }

    public function test_versioned_image_report_alias_matches_legacy_write_contract(): void
    {
        $this->postJson('/api/v1/imagery/reports', [
            'options' => [
                'parameters' => [
                    'imagery_id' => 789,
                    'message' => 'Other',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.imagery_id', 789)
            ->assertJsonPath('data.description', 'Other');
    }

    public function test_legacy_and_versioned_aliases_return_the_same_success_shape(): void
    {
        foreach (['/api/image-report', '/api/v1/imagery/reports'] as $path) {
            $response = $this->postJson($path, [
                'imagery_id' => 123,
                'message' => 'Alias parity',
            ])->assertOk();

            $data = $response->json('data');

            $this->assertSame(['data'], array_keys($response->json()));
            $this->assertSame([
                'id',
                'sort_order',
                'created_at',
                'created_by_id',
                'updated_at',
                'updated_by_id',
                'deleted_at',
                'imagery_id',
                'description',
            ], array_keys($data));
            $this->assertSame(123, $data['imagery_id']);
            $this->assertSame('Alias parity', $data['description']);
            $this->assertNull($data['created_by_id']);
            $this->assertNull($data['updated_by_id']);
            $this->assertNull($data['sort_order']);
            $this->assertNull($data['deleted_at']);
            $this->assertIsInt($data['id']);
            $this->assertIsString($data['created_at']);
            $this->assertIsString($data['updated_at']);
        }
    }

    public function test_nested_null_values_win_over_top_level_fallbacks(): void
    {
        $this->postJson('/api/v1/imagery/reports', [
            'imagery_id' => 123,
            'message' => 'Top-level message',
            'options' => ['parameters' => [
                'imagery_id' => null,
                'message' => 'Nested message',
            ]],
        ])
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'imagery_id' is required!"],
                'error_code' => 400,
            ]);

        $this->postJson('/api/v1/imagery/reports', [
            'imagery_id' => 123,
            'message' => 'Top-level message',
            'options' => ['parameters' => [
                'message' => null,
            ]],
        ])
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'message' is required!"],
                'error_code' => 400,
            ]);

        $this->assertSame(0, Schema::getConnection()->table('default_image_complaint_complaint')->count());
    }

    public function test_top_level_values_fall_back_when_options_or_parameters_are_non_objects(): void
    {
        foreach ([
            ['options' => null],
            ['options' => 'scalar-options'],
            ['options' => []],
            ['options' => ['parameters' => null]],
            ['options' => ['parameters' => 'scalar-parameters']],
            ['options' => ['parameters' => []]],
        ] as $case) {
            $this->postJson('/api/v1/imagery/reports', array_merge([
                'imagery_id' => 123,
                'message' => 'Top-level fallback',
            ], $case))
                ->assertOk()
                ->assertJsonPath('data.imagery_id', 123)
                ->assertJsonPath('data.description', 'Top-level fallback');
        }
    }

    public function test_imagery_id_preserves_numeric_string_and_json_number_casts(): void
    {
        foreach ([
            ['value' => '123.9', 'expected' => 123],
            ['value' => 456.9, 'expected' => 456],
        ] as $case) {
            $this->postJson('/api/v1/imagery/reports', [
                'imagery_id' => $case['value'],
                'message' => 'Numeric coercion',
            ])
                ->assertOk()
                ->assertJsonPath('data.imagery_id', $case['expected']);
        }

        $this->postJson('/api/v1/imagery/reports', [
            'imagery_id' => '0.9',
            'message' => 'Cast becomes zero',
        ])
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'imagery_id' is required!"],
                'error_code' => 400,
            ]);
    }

    public function test_message_is_trimmed_and_counted_by_multibyte_characters_at_the_boundary(): void
    {
        Config::set('mapilio.imagery_reports.max_message_length', 4);

        $this->postJson('/api/v1/imagery/reports', [
            'imagery_id' => 123,
            'message' => '  你好ab  ',
        ])
            ->assertOk()
            ->assertJsonPath('data.description', '你好ab');

        $this->postJson('/api/v1/imagery/reports', [
            'imagery_id' => 456,
            'message' => '  你好abc  ',
        ])
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'message' accepts at most 4 characters!"],
                'error_code' => 400,
            ]);
    }

    public function test_missing_non_string_and_blank_messages_use_the_exact_400_envelope(): void
    {
        foreach ([
            [],
            ['message' => null],
            ['message' => 123],
            ['message' => false],
            ['message' => []],
            ['message' => ['text' => 'array value']],
            ['message' => '   '],
        ] as $payload) {
            $this->postJson('/api/v1/imagery/reports', array_merge(['imagery_id' => 123], $payload))
                ->assertStatus(400)
                ->assertExactJson([
                    'success' => false,
                    'message' => ["'message' is required!"],
                    'error_code' => 400,
                ]);
        }
    }

    public function test_invalid_and_missing_imagery_ids_use_the_exact_400_envelope(): void
    {
        foreach ([
            [],
            ['imagery_id' => null],
            ['imagery_id' => 'not-numeric'],
            ['imagery_id' => true],
            ['imagery_id' => []],
            ['imagery_id' => ['id' => 123]],
            ['imagery_id' => 0],
            ['imagery_id' => -1],
        ] as $payload) {
            $this->postJson('/api/v1/imagery/reports', array_merge(['message' => 'Invalid imagery id'], $payload))
                ->assertStatus(400)
                ->assertExactJson([
                    'success' => false,
                    'message' => ["'imagery_id' is required!"],
                    'error_code' => 400,
                ]);
        }

        $this->postJson('/api/v1/imagery/reports', [
            'imagery_id' => 999999,
            'message' => 'Missing imagery row',
        ])
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'imagery_id' does not exist!"],
                'error_code' => 400,
            ]);
    }

    public function test_missing_malformed_expired_inactive_deleted_and_revoked_bearers_remain_anonymous(): void
    {
        $this->assertAnonymousReport(null);
        $this->assertAnonymousReport('not-a-real-token');
        $this->assertAnonymousReport($this->makeAccessToken(-1));

        foreach ([
            'activated' => false,
            'enabled' => false,
            'deleted_at' => '2026-01-03 00:00:00',
        ] as $column => $value) {
            $login = $this->login();
            Schema::getConnection()->table('default_users_users')->where('id', 10)->update([$column => $value]);

            $this->assertAnonymousReport($login->json('access_token'));

            Schema::getConnection()->table('default_users_users')->where('id', 10)->update([
                'activated' => true,
                'enabled' => true,
                'deleted_at' => null,
            ]);
        }

        $login = $this->login();
        $this->assertTrue(app(LegacyMobileAuth::class)->revokeToken($login->json('access_token'), 'access'));

        $this->assertAnonymousReport($login->json('access_token'));
    }

    public function test_valid_active_bearer_attributes_both_report_audit_columns(): void
    {
        $login = $this->login();

        $data = $this->withToken($login->json('access_token'))
            ->postJson('/api/v1/imagery/reports', [
                'imagery_id' => 789,
                'message' => 'Authenticated report',
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(10, $data['created_by_id']);
        $this->assertSame(10, $data['updated_by_id']);
        $this->assertSame('Authenticated report', $data['description']);
    }

    public function test_soft_deleted_imagery_is_still_reportable(): void
    {
        Schema::getConnection()->table('default_mapilio_imagery')
            ->where('id', 789)
            ->update(['deleted_at' => '2026-01-03 00:00:00']);

        $this->postJson('/api/v1/imagery/reports', [
            'imagery_id' => 789,
            'message' => 'Report after imagery deletion',
        ])
            ->assertOk()
            ->assertJsonPath('data.imagery_id', 789)
            ->assertJsonPath('data.description', 'Report after imagery deletion');
    }

    public function test_dedicated_rate_limit_returns_exact_body_and_headers(): void
    {
        Config::set('mapilio.imagery_reports.rate_limit', 1);

        $payload = [
            'imagery_id' => 123,
            'message' => 'Rate-limited report',
        ];

        $this->postJson('/api/v1/imagery/reports', $payload)->assertOk();

        $response = $this->postJson('/api/v1/imagery/reports', $payload)
            ->assertStatus(429)
            ->assertExactJson([
                'success' => false,
                'message' => ['Too many reports. Please try again later.'],
                'error_code' => 429,
            ])
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '1')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('X-RateLimit-Reset');

        foreach (['Retry-After', 'X-RateLimit-Reset'] as $header) {
            $this->assertMatchesRegularExpression('/^\d+$/', (string) $response->headers->get($header));
        }
    }

    /**
     * @return TestResponse<Response>
     */
    private function assertAnonymousReport(?string $token): TestResponse
    {
        $request = $token === null ? $this : $this->withToken($token);

        return $request->postJson('/api/v1/imagery/reports', [
            'imagery_id' => 123,
            'message' => 'Anonymous report',
        ])
            ->assertOk()
            ->assertJsonPath('data.created_by_id', null)
            ->assertJsonPath('data.updated_by_id', null);
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

    private function makeAccessToken(int $ttl): string
    {
        $method = new \ReflectionMethod(LegacyMobileAuth::class, 'encodeToken');
        $method->setAccessible(true);

        return $method->invoke(app(LegacyMobileAuth::class), 10, 'access', $ttl);
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

        // The report path now confirms the imagery exists before inserting, so
        // the fixture needs the rows these cases report against.
        Schema::create('default_mapilio_imagery', function ($table): void {
            $table->id();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_image_complaint_complaint', function ($table): void {
            $table->id();
            $table->integer('sort_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->integer('imagery_id')->nullable();
            $table->text('description')->nullable();
        });

        Schema::create('revoked_auth_tokens', function ($table): void {
            $table->id();
            $table->string('jti')->unique();
            $table->integer('subject');
            $table->string('token_type');
            $table->string('reason')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at');
            $table->timestamps();
        });
    }

    private function seedImagery(): void
    {
        Schema::getConnection()->table('default_mapilio_imagery')->insert([
            ['id' => 123, 'deleted_at' => null],
            ['id' => 456, 'deleted_at' => null],
            ['id' => 789, 'deleted_at' => null],
        ]);
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

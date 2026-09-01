<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicUserProfileCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.mobile_auth.default_profile_photo_url', 'https://mapilio.test/default-avatar.png');
        Config::set('mapilio.rate_limiting.enabled', false);
        Config::set('mapilio.rate_limiting.enforce', false);
        RateLimiter::clear('mapilio-api|'.sha1('127.0.0.1'));

        $this->createTables();
        $this->seedData();
    }

    public function test_versioned_public_user_profile_populated_response_freezes_exact_row_pagination_and_link_types(): void
    {
        $response = $this->getJson('/api/v1/users/profile?options[parameters][id]=210')
            ->assertOk()
            ->assertExactJson([
                'data' => [[
                    'id' => 210,
                    'username' => 'mapper',
                    'user_profile_photo' => 'https://mapilio.test/default-avatar.png',
                    'user_bio' => 'Mapping roads.',
                    'created_at' => '2022-08-22T10:11:41.000000Z',
                    'updated_at' => '2025-11-20T09:00:32.000000Z',
                    'km' => '6.8',
                    'photos' => 2,
                ]],
                'pagination' => [
                    'current_page' => 1,
                    'first_page_url' => '/api/search-user?options%5Bparameters%5D%5Bid%5D=210&page=1',
                    'from' => 1,
                    'last_page' => 1,
                    'last_page_url' => '/api/search-user?options%5Bparameters%5D%5Bid%5D=210&page=1',
                    'links' => [
                        ['url' => null, 'label' => '&laquo; Previous', 'active' => false],
                        ['url' => '/api/search-user?options%5Bparameters%5D%5Bid%5D=210&page=1', 'label' => '1', 'active' => true],
                        ['url' => null, 'label' => 'Next &raquo;', 'active' => false],
                    ],
                    'next_page_url' => null,
                    'path' => '/api/search-user',
                    'per_page' => 15,
                    'prev_page_url' => null,
                    'to' => 1,
                    'total' => 1,
                ],
            ]);

        $row = $response->json('data.0');
        $this->assertIsInt($row['id']);
        $this->assertIsString($row['username']);
        $this->assertIsString($row['user_profile_photo']);
        $this->assertIsString($row['user_bio']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $row['created_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $row['updated_at']);
        $this->assertIsString($row['km']);
        $this->assertIsInt($row['photos']);

        $pagination = $response->json('pagination');
        $this->assertSame(12, count($pagination));
        $this->assertIsInt($pagination['current_page']);
        $this->assertIsInt($pagination['from']);
        $this->assertIsInt($pagination['last_page']);
        $this->assertIsInt($pagination['per_page']);
        $this->assertIsInt($pagination['to']);
        $this->assertIsInt($pagination['total']);
        $this->assertCount(3, $pagination['links']);

        foreach ($pagination['links'] as $link) {
            $this->assertSame(['url', 'label', 'active'], array_keys($link));
            $this->assertTrue($link['url'] === null || is_string($link['url']));
            $this->assertIsString($link['label']);
            $this->assertIsBool($link['active']);
        }
    }

    public function test_versioned_public_user_profile_alias_matches_legacy_response_exactly(): void
    {
        $legacy = $this->getJson('/api/search-user?options[parameters][id]=210&page=1')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/users/profile?options[parameters][id]=210&page=1')
            ->assertOk()
            ->json();

        $this->assertSame($legacy, $versioned);
    }

    public function test_versioned_public_user_profile_applies_nested_numeric_cast_and_ignores_top_level_id(): void
    {
        $this->getJson('/api/v1/users/profile?id=211&options[parameters][id]=210.9')
            ->assertOk()
            ->assertJsonPath('data.0.id', 210);

        $this->getJson('/api/v1/users/profile?options[parameters][id]=2.1e2')
            ->assertOk()
            ->assertJsonPath('data.0.id', 210);
    }

    public function test_versioned_public_user_profile_rejects_missing_and_invalid_nested_ids_exactly(): void
    {
        foreach (['not-numeric', '0', '-1', '0.9'] as $id) {
            $this->getJson('/api/v1/users/profile?options[parameters][id]='.rawurlencode($id))
                ->assertNotFound()
                ->assertExactJson(['message' => 'Not Found']);
        }

        $this->getJson('/api/v1/users/profile')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not Found']);

        $this->getJson('/api/v1/users/profile?id=210')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not Found']);

        $this->call('GET', '/api/v1/users/profile', [
            'options' => ['parameters' => ['id' => null]],
        ])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not Found']);
    }

    public function test_versioned_public_user_profile_returns_null_for_unknown_and_soft_deleted_users(): void
    {
        $this->getJson('/api/v1/users/profile?options[parameters][id]=999')
            ->assertOk()
            ->assertExactJson(['data' => null]);

        Schema::getConnection()->table('default_users_users')
            ->where('id', 211)
            ->update(['deleted_at' => '2026-01-03 00:00:00']);

        $this->getJson('/api/v1/users/profile?options[parameters][id]=211')
            ->assertOk()
            ->assertExactJson(['data' => null]);
    }

    public function test_versioned_public_user_profile_preserves_nullable_and_derived_fields(): void
    {
        $this->getJson('/api/v1/users/profile?options[parameters][id]=211')
            ->assertOk()
            ->assertExactJson([
                'data' => [[
                    'id' => 211,
                    'username' => null,
                    'user_profile_photo' => 'https://mapilio.test/quiet-avatar.png',
                    'user_bio' => null,
                    'created_at' => null,
                    'updated_at' => null,
                    'km' => '0',
                    'photos' => 0,
                ]],
                'pagination' => [
                    'current_page' => 1,
                    'first_page_url' => '/api/search-user?options%5Bparameters%5D%5Bid%5D=211&page=1',
                    'from' => 1,
                    'last_page' => 1,
                    'last_page_url' => '/api/search-user?options%5Bparameters%5D%5Bid%5D=211&page=1',
                    'links' => [
                        ['url' => null, 'label' => '&laquo; Previous', 'active' => false],
                        ['url' => '/api/search-user?options%5Bparameters%5D%5Bid%5D=211&page=1', 'label' => '1', 'active' => true],
                        ['url' => null, 'label' => 'Next &raquo;', 'active' => false],
                    ],
                    'next_page_url' => null,
                    'path' => '/api/search-user',
                    'per_page' => 15,
                    'prev_page_url' => null,
                    'to' => 1,
                    'total' => 1,
                ],
            ]);
    }

    public function test_versioned_public_user_profile_is_bearer_irrelevant(): void
    {
        $withoutBearer = $this->getJson('/api/v1/users/profile?options[parameters][id]=210')
            ->assertOk()
            ->json();

        $withBearer = $this->withHeaders(['Authorization' => 'Bearer synthetic-irrelevant-token'])
            ->getJson('/api/v1/users/profile?options[parameters][id]=210')
            ->assertOk()
            ->json();

        $this->assertSame($withoutBearer, $withBearer);
    }

    public function test_versioned_public_user_profile_optional_global_rate_limit_preserves_exact_envelope_and_headers(): void
    {
        Config::set('mapilio.rate_limiting.enabled', true);
        Config::set('mapilio.rate_limiting.enforce', true);
        Config::set('mapilio.rate_limiting.max_attempts', 1);
        RateLimiter::clear('mapilio-api|'.sha1('127.0.0.1'));

        $this->getJson('/api/v1/users/profile?options[parameters][id]=210')->assertOk();

        $response = $this->getJson('/api/v1/users/profile?options[parameters][id]=210')
            ->assertStatus(429)
            ->assertExactJson([
                'success' => false,
                'message' => ['Too many requests.'],
                'error_code' => 429,
            ]);

        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertSame('1', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_versioned_public_user_profile_ignores_conditional_headers_and_emits_no_etag(): void
    {
        $response = $this->withHeaders(['If-None-Match' => '"synthetic-profile-etag"'])
            ->getJson('/api/v1/users/profile?options[parameters][id]=210')
            ->assertOk()
            ->assertJsonPath('data.0.id', 210);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->headers->has('ETag'));
    }

    private function createTables(): void
    {
        Schema::create('default_users_users', function ($table): void {
            $table->id();
            $table->string('username')->nullable();
            $table->string('user_profile_photo')->nullable();
            $table->text('user_bio')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_mapilio_sequence_detail', function ($table): void {
            $table->id();
            $table->integer('created_by_id')->nullable();
            $table->float('length_km')->nullable();
            $table->string('project_key')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_mapilio_imagery', function ($table): void {
            $table->id();
            $table->integer('created_by_id')->nullable();
            $table->string('project_key')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    private function seedData(): void
    {
        Schema::getConnection()->table('default_users_users')->insert([
            [
                'id' => 210,
                'username' => 'mapper',
                'user_profile_photo' => '',
                'user_bio' => 'Mapping roads.',
                'created_at' => '2022-08-22 10:11:41',
                'updated_at' => '2025-11-20 09:00:32',
                'deleted_at' => null,
            ],
            [
                'id' => 211,
                'username' => null,
                'user_profile_photo' => 'https://mapilio.test/quiet-avatar.png',
                'user_bio' => null,
                'created_at' => null,
                'updated_at' => null,
                'deleted_at' => null,
            ],
        ]);

        Schema::getConnection()->table('default_mapilio_sequence_detail')->insert([
            [
                'created_by_id' => 210,
                'length_km' => 1.24,
                'project_key' => null,
                'deleted_at' => null,
            ],
            [
                'created_by_id' => 210,
                'length_km' => 5.51,
                'project_key' => null,
                'deleted_at' => null,
            ],
            [
                'created_by_id' => 210,
                'length_km' => 9.99,
                'project_key' => 'private-project',
                'deleted_at' => null,
            ],
            [
                'created_by_id' => 210,
                'length_km' => 100.0,
                'project_key' => null,
                'deleted_at' => '2026-01-03 00:00:00',
            ],
        ]);

        Schema::getConnection()->table('default_mapilio_imagery')->insert([
            [
                'created_by_id' => 210,
                'project_key' => null,
                'deleted_at' => null,
            ],
            [
                'created_by_id' => 210,
                'project_key' => null,
                'deleted_at' => null,
            ],
            [
                'created_by_id' => 210,
                'project_key' => 'private-project',
                'deleted_at' => null,
            ],
            [
                'created_by_id' => 210,
                'project_key' => null,
                'deleted_at' => '2026-01-03 00:00:00',
            ],
        ]);
    }
}

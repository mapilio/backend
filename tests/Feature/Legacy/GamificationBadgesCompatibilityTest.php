<?php

namespace Tests\Feature\Legacy;

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GamificationBadgesCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.leaderboard.public_role_slugs', []);
        Config::set('mapilio.leaderboard.excluded_role_slugs', []);

        Schema::create('default_users_users', function ($table): void {
            $table->id();
            $table->string('username')->nullable();
            $table->string('display_name')->nullable();
            $table->string('user_profile_photo')->nullable();
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

        Schema::create('default_mapilio_sequence_detail', function ($table): void {
            $table->id();
            $table->string('sequence_uuid')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->float('sequence_point')->nullable();
            $table->float('length_km')->nullable();
            $table->boolean('anomaly')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_mapilio_imagery', function ($table): void {
            $table->id();
            $table->string('sequence_uuid')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->boolean('anomaly')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_gamification_badge', function ($table): void {
            $table->id();
            $table->integer('sort_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->string('slug');
            $table->integer('image_id')->nullable();
            $table->integer('available_level');
            $table->boolean('is_custom')->default(false);
            $table->string('color_code')->nullable();
            $table->integer('disabled_image_id')->nullable();
        });

        Schema::create('default_gamification_badge_translations', function ($table): void {
            $table->id();
            $table->integer('entry_id');
            $table->string('locale');
            $table->string('title')->nullable();
            $table->text('info')->nullable();
        });

        Schema::create('default_gamification_level', function ($table): void {
            $table->id();
            $table->integer('sort_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->integer('level');
            $table->integer('xp');
        });

        Schema::create('default_gamification_user_badge', function ($table): void {
            $table->integer('user_id');
            $table->integer('badge_id');
        });

        Schema::create('default_gamification_user_level', function ($table): void {
            $table->integer('user_id');
            $table->integer('level_id');
        });

        Schema::create('default_files_disks', function ($table): void {
            $table->id();
            $table->integer('sort_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('slug');
            $table->string('adapter');
        });

        Schema::create('default_files_disks_translations', function ($table): void {
            $table->id();
            $table->integer('entry_id');
            $table->string('locale');
            $table->string('name')->nullable();
            $table->text('description')->nullable();
        });

        Schema::create('default_files_folders', function ($table): void {
            $table->id();
            $table->integer('sort_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->integer('disk_id');
            $table->string('slug');
            $table->text('allowed_types')->nullable();
            $table->string('str_id');
        });

        Schema::create('default_files_folders_translations', function ($table): void {
            $table->id();
            $table->integer('entry_id');
            $table->string('locale');
            $table->string('name')->nullable();
            $table->text('description')->nullable();
        });

        Schema::create('default_files_files', function ($table): void {
            $table->id();
            $table->integer('sort_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('name');
            $table->integer('disk_id');
            $table->integer('folder_id');
            $table->string('extension');
            $table->integer('size');
            $table->string('mime_type');
            $table->integer('entry_id')->nullable();
            $table->string('entry_type')->nullable();
            $table->text('keywords')->nullable();
            $table->string('height')->nullable();
            $table->string('width')->nullable();
            $table->string('alt_text')->nullable();
            $table->string('title')->nullable();
            $table->text('caption')->nullable();
            $table->text('description')->nullable();
            $table->string('str_id');
        });

        $this->seedFixtures();
    }

    public function test_legacy_gamification_badges_preserves_enabled_and_disabled_badge_shape(): void
    {
        $this->getJson('/api/gamification/badges/10')
            ->assertOk()
            ->assertExactJson($this->expectedPayload());
    }

    public function test_gamification_malformed_timestamps_return_null(): void
    {
        $db = Schema::getConnection();

        $db->table('default_gamification_badge')->update([
            'created_at' => 'not-a-date',
            'updated_at' => 'not-a-date',
        ]);
        $db->table('default_files_files')->where('id', 101)->update([
            'created_at' => 'not-a-date',
            'updated_at' => 'not-a-date',
            'deleted_at' => 'not-a-date',
        ]);
        $db->table('default_files_disks')->where('id', 1)->update([
            'created_at' => 'not-a-date',
            'updated_at' => 'not-a-date',
            'deleted_at' => 'not-a-date',
        ]);
        $db->table('default_files_folders')->where('id', 12)->update([
            'created_at' => 'not-a-date',
            'updated_at' => 'not-a-date',
            'deleted_at' => 'not-a-date',
        ]);

        $payload = $this->getJson('/api/gamification/badges/10')->assertOk()->json();

        foreach ($payload['badges'] as $badge) {
            $this->assertNull($badge['created_at']);
            $this->assertNull($badge['updated_at']);
        }

        $disabledImage = $payload['badges'][1]['disabled_image'];
        $this->assertNull($disabledImage['created_at']);
        $this->assertNull($disabledImage['updated_at']);
        $this->assertNull($disabledImage['deleted_at']);
        $this->assertNull($disabledImage['disk']['created_at']);
        $this->assertNull($disabledImage['disk']['updated_at']);
        $this->assertNull($disabledImage['disk']['deleted_at']);
        $this->assertNull($disabledImage['folder']['created_at']);
        $this->assertNull($disabledImage['folder']['updated_at']);
        $this->assertNull($disabledImage['folder']['deleted_at']);
        $this->assertNull($payload['next']['badge']['created_at']);
        $this->assertNull($payload['next']['badge']['updated_at']);
    }

    public function test_versioned_gamification_badges_alias_returns_same_contract(): void
    {
        $legacy = $this->getJson('/api/gamification/badges/10')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/gamification/badges/10')
            ->assertOk()
            ->json();

        $this->assertSame($legacy, $versioned);
    }

    public function test_gamification_badges_locale_uses_app_locale_and_falls_back_to_en_for_empty_or_non_string_values(): void
    {
        Schema::getConnection()->table('default_gamification_badge_translations')->insert([
            ['entry_id' => 5, 'locale' => 'tr', 'title' => 'Sokak Gezginı', 'info' => 'İlk adımlar.'],
            ['entry_id' => 6, 'locale' => 'tr', 'title' => 'Kaşif', 'info' => 'Keşfetmeye devam et.'],
        ]);
        app()->setLocale('tr');

        $this->getJson('/api/v1/gamification/badges/10')
            ->assertOk()
            ->assertJsonPath('badges.0.title', 'Sokak Gezginı');

        $this->withoutMiddleware(ConvertEmptyStringsToNull::class)
            ->call('GET', '/api/v1/gamification/badges/10', ['locale' => ''])
            ->assertOk()
            ->assertJsonPath('badges.0.title', 'Street Stoller')
            ->assertJsonPath('badges.1.title', 'Pathfinder');

        $this->getJson('/api/v1/gamification/badges/10?locale[]=tr')
            ->assertOk()
            ->assertJsonPath('badges.0.title', 'Street Stoller')
            ->assertJsonPath('badges.1.title', 'Pathfinder');
    }

    public function test_gamification_badges_unrecognized_scalar_locale_is_passed_through_without_translation_fallback(): void
    {
        $this->getJson('/api/v1/gamification/badges/10?locale=synthetic-unknown')
            ->assertOk()
            ->assertJsonPath('badges.0.title', null)
            ->assertJsonPath('badges.0.info', null)
            ->assertJsonPath('badges.1.title', null)
            ->assertJsonPath('badges.1.info', null);
    }

    public function test_versioned_gamification_badges_requires_numeric_user_id_only(): void
    {
        $this->getJson('/api/v1/gamification/badges/not-numeric')
            ->assertStatus(404);
    }

    public function test_gamification_badges_does_not_filter_deleted_users(): void
    {
        Schema::getConnection()->table('default_users_users')->where('id', 10)->update([
            'deleted_at' => '2026-08-01 00:00:00',
        ]);

        $payload = $this->getJson('/api/v1/gamification/badges/10')
            ->assertOk()
            ->json();
        $expected = $this->expectedPayload();

        $this->assertSame($expected['badges'], $payload['badges']);
        $this->assertSame($expected['next']['badge'], $payload['next']['badge']);
        $this->assertSame(0, $payload['point']);
        $this->assertSame('0', $payload['next']['percentage']);
    }

    public function test_gamification_badges_optional_global_rate_limit_preserves_legacy_error_envelope(): void
    {
        Config::set('mapilio.rate_limiting.enabled', true);
        Config::set('mapilio.rate_limiting.enforce', true);
        Config::set('mapilio.rate_limiting.max_attempts', 1);
        RateLimiter::clear('mapilio-api|'.sha1('127.0.0.1'));

        $this->getJson('/api/v1/gamification/badges/10')->assertOk();

        $response = $this->getJson('/api/v1/gamification/badges/10')
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

    public function test_gamification_badges_missing_user_preserves_empty_array_response(): void
    {
        foreach (['/api/gamification/badges/999', '/api/v1/gamification/badges/999'] as $path) {
            $this->getJson($path)
                ->assertOk()
                ->assertExactJson([]);
        }
    }

    private function seedFixtures(): void
    {
        $db = Schema::getConnection();

        $db->table('default_users_users')->insert([
            'id' => 10,
            'username' => 'mapper',
            'display_name' => 'Mapper',
            'user_profile_photo' => null,
            'deleted_at' => null,
        ]);

        $db->table('default_mapilio_sequence_detail')->insert([
            [
                'sequence_uuid' => 'seq-gamification',
                'created_by_id' => 10,
                'sequence_point' => 97,
                'length_km' => 1.25,
                'anomaly' => false,
                'created_at' => '2026-07-01 10:00:00',
                'deleted_at' => null,
            ],
        ]);

        $db->table('default_mapilio_imagery')->insert([
            [
                'sequence_uuid' => 'seq-gamification',
                'created_by_id' => 10,
                'anomaly' => false,
                'created_at' => '2026-07-01 10:00:01',
                'deleted_at' => null,
            ],
        ]);

        $db->table('default_gamification_level')->insert([
            [
                'id' => 1,
                'sort_order' => 1,
                'created_at' => '2026-06-01 01:02:03',
                'created_by_id' => 7,
                'updated_at' => '2026-06-02 01:02:03',
                'updated_by_id' => 8,
                'level' => 1,
                'xp' => 1,
            ],
            [
                'id' => 2,
                'sort_order' => 2,
                'created_at' => '2026-06-03 01:02:03',
                'created_by_id' => 7,
                'updated_at' => '2026-06-04 01:02:03',
                'updated_by_id' => 8,
                'level' => 2,
                'xp' => 1000,
            ],
        ]);

        $db->table('default_files_disks')->insert([
            [
                'id' => 1,
                'sort_order' => 1,
                'created_at' => '2026-05-01 01:02:03',
                'created_by_id' => null,
                'updated_at' => '2026-05-02 01:02:03',
                'updated_by_id' => null,
                'deleted_at' => null,
                'slug' => 'local',
                'adapter' => 'private_storage',
            ],
        ]);

        $db->table('default_files_disks_translations')->insert([
            [
                'entry_id' => 1,
                'locale' => 'en',
                'name' => 'Local',
                'description' => 'Local private storage.',
            ],
        ]);

        $db->table('default_files_folders')->insert([
            [
                'id' => 12,
                'sort_order' => 12,
                'created_at' => '2026-05-03 01:02:03',
                'created_by_id' => null,
                'updated_at' => '2026-05-04 01:02:03',
                'updated_by_id' => null,
                'deleted_at' => null,
                'disk_id' => 1,
                'slug' => 'badges',
                'allowed_types' => 'a:1:{i:0;s:3:"png";}',
                'str_id' => 'folder-str',
            ],
        ]);

        $db->table('default_files_folders_translations')->insert([
            [
                'entry_id' => 12,
                'locale' => 'en',
                'name' => 'Badge Images',
                'description' => 'A folder for badge images.',
            ],
        ]);

        $db->table('default_files_files')->insert([
            [
                'id' => 100,
                'sort_order' => 100,
                'created_at' => '2026-05-05 01:02:03',
                'created_by_id' => 1,
                'updated_at' => '2026-05-06 01:02:03',
                'updated_by_id' => 2,
                'deleted_at' => null,
                'name' => 'active.png',
                'disk_id' => 1,
                'folder_id' => 12,
                'extension' => 'png',
                'size' => 120,
                'mime_type' => 'image/png',
                'entry_id' => null,
                'entry_type' => 'FilesEntry',
                'keywords' => null,
                'height' => '196',
                'width' => '237',
                'alt_text' => null,
                'title' => null,
                'caption' => null,
                'description' => null,
                'str_id' => 'active-file',
            ],
            [
                'id' => 101,
                'sort_order' => 101,
                'created_at' => '2026-05-07 01:02:03',
                'created_by_id' => 1,
                'updated_at' => '2026-05-08 01:02:03',
                'updated_by_id' => 2,
                'deleted_at' => null,
                'name' => 'disabled.png',
                'disk_id' => 1,
                'folder_id' => 12,
                'extension' => 'png',
                'size' => 121,
                'mime_type' => 'image/png',
                'entry_id' => null,
                'entry_type' => 'FilesEntry',
                'keywords' => null,
                'height' => '196',
                'width' => '237',
                'alt_text' => null,
                'title' => null,
                'caption' => null,
                'description' => null,
                'str_id' => 'disabled-file',
            ],
        ]);

        $db->table('default_gamification_badge')->insert([
            [
                'id' => 5,
                'sort_order' => 1,
                'created_at' => '2026-06-05 01:02:03',
                'created_by_id' => 3,
                'updated_at' => '2026-06-06 01:02:03',
                'updated_by_id' => 4,
                'slug' => 'street_stoller',
                'image_id' => 100,
                'available_level' => 1,
                'is_custom' => true,
                'color_code' => '#465973',
                'disabled_image_id' => 101,
            ],
            [
                'id' => 6,
                'sort_order' => 2,
                'created_at' => '2026-06-07 01:02:03',
                'created_by_id' => 3,
                'updated_at' => '2026-06-08 01:02:03',
                'updated_by_id' => 4,
                'slug' => 'pathfinder',
                'image_id' => 100,
                'available_level' => 2,
                'is_custom' => true,
                'color_code' => '#1781ED',
                'disabled_image_id' => 101,
            ],
        ]);

        $db->table('default_gamification_badge_translations')->insert([
            ['entry_id' => 5, 'locale' => 'en', 'title' => 'Street Stoller', 'info' => 'First steps.'],
            ['entry_id' => 6, 'locale' => 'en', 'title' => 'Pathfinder', 'info' => 'Keep exploring.'],
        ]);

        $db->table('default_gamification_user_badge')->insert([
            ['user_id' => 10, 'badge_id' => 5],
        ]);

        $db->table('default_gamification_user_level')->insert([
            ['user_id' => 10, 'level_id' => 1],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function expectedPayload(): array
    {
        $assetRoot = config('app.url');

        return [
            'badges' => [
                [
                    'id' => 5,
                    'sort_order' => 1,
                    'created_at' => '2026-06-05T01:02:03.000000Z',
                    'created_by_id' => 3,
                    'updated_at' => '2026-06-06T01:02:03.000000Z',
                    'updated_by_id' => 4,
                    'slug' => 'street_stoller',
                    'image_id' => 100,
                    'available_level' => 1,
                    'is_custom' => true,
                    'color_code' => '#465973',
                    'disabled_image_id' => 101,
                    'enable' => true,
                    'icon' => $assetRoot,
                    'point' => 1,
                    'title' => 'Street Stoller',
                    'info' => 'First steps.',
                ],
                [
                    'id' => 6,
                    'sort_order' => 2,
                    'created_at' => '2026-06-07T01:02:03.000000Z',
                    'created_by_id' => 3,
                    'updated_at' => '2026-06-08T01:02:03.000000Z',
                    'updated_by_id' => 4,
                    'slug' => 'pathfinder',
                    'image_id' => 100,
                    'available_level' => 2,
                    'is_custom' => true,
                    'color_code' => '#1781ED',
                    'disabled_image_id' => 101,
                    'enable' => false,
                    'icon' => $assetRoot,
                    'point' => 1000,
                    'title' => 'Pathfinder',
                    'info' => 'Keep exploring.',
                    'disabled_image' => [
                        'id' => 101,
                        'sort_order' => 101,
                        'created_at' => '2026-05-07T01:02:03.000000Z',
                        'created_by_id' => 1,
                        'updated_at' => '2026-05-08T01:02:03.000000Z',
                        'updated_by_id' => 2,
                        'deleted_at' => null,
                        'name' => 'disabled.png',
                        'disk_id' => 1,
                        'folder_id' => 12,
                        'extension' => 'png',
                        'size' => 121,
                        'mime_type' => 'image/png',
                        'entry_id' => null,
                        'entry_type' => 'FilesEntry',
                        'keywords' => null,
                        'height' => '196',
                        'width' => '237',
                        'alt_text' => null,
                        'title' => null,
                        'caption' => null,
                        'description' => null,
                        'str_id' => 'disabled-file',
                        'disk' => [
                            'id' => 1,
                            'sort_order' => 1,
                            'created_at' => '2026-05-01T01:02:03.000000Z',
                            'created_by_id' => null,
                            'updated_at' => '2026-05-02T01:02:03.000000Z',
                            'updated_by_id' => null,
                            'deleted_at' => null,
                            'slug' => 'local',
                            'adapter' => 'private_storage',
                            'name' => 'Local',
                            'description' => 'Local private storage.',
                        ],
                        'folder' => [
                            'id' => 12,
                            'sort_order' => 12,
                            'created_at' => '2026-05-03T01:02:03.000000Z',
                            'created_by_id' => null,
                            'updated_at' => '2026-05-04T01:02:03.000000Z',
                            'updated_by_id' => null,
                            'deleted_at' => null,
                            'disk_id' => 1,
                            'slug' => 'badges',
                            'allowed_types' => 'a:1:{i:0;s:3:"png";}',
                            'str_id' => 'folder-str',
                            'name' => 'Badge Images',
                            'description' => 'A folder for badge images.',
                        ],
                        'entry' => null,
                        'path' => 'badges/disabled.png',
                        'location' => 'local://badges/disabled.png',
                    ],
                ],
            ],
            'point' => '97',
            'next' => [
                'badge' => [
                    'id' => 5,
                    'sort_order' => 1,
                    'created_at' => '2026-06-05T01:02:03.000000Z',
                    'created_by_id' => 3,
                    'updated_at' => '2026-06-06T01:02:03.000000Z',
                    'updated_by_id' => 4,
                    'slug' => 'street_stoller',
                    'image_id' => 100,
                    'available_level' => 1,
                    'is_custom' => true,
                    'color_code' => '#465973',
                    'disabled_image_id' => 101,
                    'icon' => $assetRoot,
                    'title' => 'Street Stoller',
                    'info' => 'First steps.',
                ],
                'percentage' => '97',
            ],
        ];
    }
}

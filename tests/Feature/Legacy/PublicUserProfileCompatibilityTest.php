<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicUserProfileCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.mobile_auth.default_profile_photo_url', 'https://mapilio.test/default-avatar.png');

        $this->createTables();
        $this->seedData();
    }

    public function test_legacy_search_user_by_nested_id_preserves_mobile_profile_contract(): void
    {
        $this->getJson('/api/search-user?options[parameters][id]=210')
            ->assertOk()
            ->assertJsonPath('data.0.id', 210)
            ->assertJsonPath('data.0.username', 'mapper')
            ->assertJsonPath('data.0.user_profile_photo', 'https://mapilio.test/default-avatar.png')
            ->assertJsonPath('data.0.user_bio', 'Mapping roads.')
            ->assertJsonPath('data.0.created_at', '2022-08-22T10:11:41.000000Z')
            ->assertJsonPath('data.0.updated_at', '2025-11-20T09:00:32.000000Z')
            ->assertJsonPath('data.0.km', '6.8')
            ->assertJsonPath('data.0.photos', 2)
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.path', '/api/search-user')
            ->assertJsonPath('pagination.per_page', 15)
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_search_user_missing_user_preserves_empty_data_shape(): void
    {
        $this->getJson('/api/search-user?options[parameters][id]=999')
            ->assertOk()
            ->assertExactJson([
                'data' => null,
            ]);
    }

    public function test_search_user_keeps_direct_id_and_keyword_account_discovery_closed(): void
    {
        $this->getJson('/api/search-user?id=210')
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Not Found',
            ]);

        $this->postJson('/api/search-user', [
            'keywords' => 'mapper',
        ])->assertNotFound()
            ->assertExactJson([
                'message' => 'Not Found',
            ]);
    }

    public function test_versioned_public_user_profile_alias_matches_legacy_contract(): void
    {
        $legacy = $this->getJson('/api/search-user?options[parameters][id]=210')
            ->assertOk()
            ->json();

        $this->getJson('/api/v1/users/profile?options[parameters][id]=210')
            ->assertOk()
            ->assertJsonPath('data.0.id', $legacy['data'][0]['id'])
            ->assertJsonPath('data.0.username', $legacy['data'][0]['username'])
            ->assertJsonPath('data.0.km', $legacy['data'][0]['km'])
            ->assertJsonPath('data.0.photos', $legacy['data'][0]['photos']);
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
            'id' => 210,
            'username' => 'mapper',
            'user_profile_photo' => null,
            'user_bio' => 'Mapping roads.',
            'created_at' => '2022-08-22 10:11:41',
            'updated_at' => '2025-11-20 09:00:32',
            'deleted_at' => null,
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
        ]);
    }
}

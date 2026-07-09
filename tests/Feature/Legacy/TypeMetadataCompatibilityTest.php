<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TypeMetadataCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('default_types_type', function ($table): void {
            $table->id();
            $table->integer('sort_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('code')->nullable();
            $table->integer('group_id')->nullable();
            $table->string('icon')->nullable();
        });

        Schema::create('default_types_type_translations', function ($table): void {
            $table->id();
            $table->integer('entry_id');
            $table->string('locale');
            $table->string('name')->nullable();
        });

        Schema::create('default_types_groups', function ($table): void {
            $table->id();
            $table->integer('sort_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('slug')->nullable();
        });

        Schema::create('default_types_groups_translations', function ($table): void {
            $table->id();
            $table->integer('entry_id');
            $table->string('locale');
            $table->string('name')->nullable();
        });

        Schema::getConnection()->table('default_types_type')->insert([
            [
                'id' => 1,
                'sort_order' => 1,
                'created_at' => '2026-01-01 01:02:03',
                'created_by_id' => 10,
                'updated_at' => '2026-01-02 01:02:03',
                'updated_by_id' => 11,
                'deleted_at' => null,
                'code' => 'detect-reg-stop-c1',
                'group_id' => 1,
                'icon' => null,
            ],
            [
                'id' => 2,
                'sort_order' => 2,
                'created_at' => '2026-01-03 01:02:03',
                'created_by_id' => 10,
                'updated_at' => '2026-01-04 01:02:03',
                'updated_by_id' => 11,
                'deleted_at' => null,
                'code' => 'inst-pole',
                'group_id' => 2,
                'icon' => 'pole.svg',
            ],
            [
                'id' => 3,
                'sort_order' => 3,
                'created_at' => '2026-01-05 01:02:03',
                'created_by_id' => 10,
                'updated_at' => '2026-01-06 01:02:03',
                'updated_by_id' => 11,
                'deleted_at' => '2026-01-07 01:02:03',
                'code' => 'deleted-type',
                'group_id' => 2,
                'icon' => null,
            ],
        ]);

        Schema::getConnection()->table('default_types_type_translations')->insert([
            ['entry_id' => 1, 'locale' => 'en', 'name' => 'Stop'],
            ['entry_id' => 1, 'locale' => 'tr', 'name' => 'Dur'],
            ['entry_id' => 2, 'locale' => 'en', 'name' => 'Pole'],
        ]);

        Schema::getConnection()->table('default_types_groups')->insert([
            [
                'id' => 1,
                'sort_order' => 1,
                'created_at' => '2026-02-01 01:02:03',
                'created_by_id' => 20,
                'updated_at' => '2026-02-02 01:02:03',
                'updated_by_id' => null,
                'deleted_at' => null,
                'slug' => 'traffic_signs',
            ],
            [
                'id' => 2,
                'sort_order' => 2,
                'created_at' => '2026-02-03 01:02:03',
                'created_by_id' => 20,
                'updated_at' => '2026-02-04 01:02:03',
                'updated_by_id' => 21,
                'deleted_at' => null,
                'slug' => 'objects',
            ],
        ]);

        Schema::getConnection()->table('default_types_groups_translations')->insert([
            ['entry_id' => 1, 'locale' => 'en', 'name' => 'Traffic Signs'],
            ['entry_id' => 2, 'locale' => 'en', 'name' => 'Objects'],
        ]);
    }

    public function test_legacy_get_types_path_preserves_wrapper_and_pagination_shape(): void
    {
        $this->getJson('/api/get-types')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'id' => 1,
                        'sort_order' => 1,
                        'created_at' => '2026-01-01T01:02:03.000000Z',
                        'created_by_id' => 10,
                        'updated_at' => '2026-01-02T01:02:03.000000Z',
                        'updated_by_id' => 11,
                        'deleted_at' => null,
                        'code' => 'detect-reg-stop-c1',
                        'group_id' => 1,
                        'icon' => null,
                        'name' => 'Stop',
                    ],
                    [
                        'id' => 2,
                        'sort_order' => 2,
                        'created_at' => '2026-01-03T01:02:03.000000Z',
                        'created_by_id' => 10,
                        'updated_at' => '2026-01-04T01:02:03.000000Z',
                        'updated_by_id' => 11,
                        'deleted_at' => null,
                        'code' => 'inst-pole',
                        'group_id' => 2,
                        'icon' => 'pole.svg',
                        'name' => 'Pole',
                    ],
                ],
                'pagination' => [
                    'current_page' => 1,
                    'first_page_url' => '/api/get-types?page=1',
                    'from' => 1,
                    'last_page' => 1,
                    'last_page_url' => '/api/get-types?page=1',
                    'links' => [
                        ['url' => null, 'label' => '&laquo; Previous', 'active' => false],
                        ['url' => '/api/get-types?page=1', 'label' => '1', 'active' => true],
                        ['url' => null, 'label' => 'Next &raquo;', 'active' => false],
                    ],
                    'next_page_url' => null,
                    'path' => '/api/get-types',
                    'per_page' => 15,
                    'prev_page_url' => null,
                    'to' => 2,
                    'total' => 2,
                ],
            ]);
    }

    public function test_legacy_get_types_empty_page_omits_pagination(): void
    {
        $this->getJson('/api/get-types?page=2')
            ->assertOk()
            ->assertExactJson([
                'data' => null,
            ]);
    }

    public function test_versioned_types_alias_returns_same_contract(): void
    {
        $legacy = $this->getJson('/api/get-types')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/inventory/types')
            ->assertOk()
            ->json();

        $this->assertSame($legacy, $versioned);
    }

    public function test_legacy_get_groups_path_preserves_wrapper_and_pagination_shape(): void
    {
        $this->getJson('/api/get-groups')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'traffic_signs')
            ->assertJsonPath('data.0.name', 'Traffic Signs')
            ->assertJsonPath('data.1.slug', 'objects')
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('pagination.per_page', 15);
    }

    public function test_versioned_groups_alias_returns_same_contract(): void
    {
        $legacy = $this->getJson('/api/get-groups')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/inventory/groups')
            ->assertOk()
            ->json();

        $this->assertSame($legacy, $versioned);
    }

    public function test_sprite_metadata_paths_return_public_sprite_maps(): void
    {
        $this->getJson('/api/get-sprites')
            ->assertOk()
            ->assertJsonPath('detect-comp-accident-area-c3.width', 24);

        $this->getJson('/api/get-sprites2x')
            ->assertOk()
            ->assertJsonPath('detect-comp-accident-area-c3.width', 48);
    }

    public function test_versioned_sprite_aliases_return_same_contract(): void
    {
        $legacy = $this->getJson('/api/get-sprites')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/inventory/sprites')
            ->assertOk()
            ->json();

        $this->assertSame($legacy, $versioned);

        $legacyRetina = $this->getJson('/api/get-sprites2x')
            ->assertOk()
            ->json();

        $versionedRetina = $this->getJson('/api/v1/inventory/sprites-2x')
            ->assertOk()
            ->json();

        $this->assertSame($legacyRetina, $versionedRetina);
    }
}

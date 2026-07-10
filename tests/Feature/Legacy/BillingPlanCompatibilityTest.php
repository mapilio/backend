<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BillingPlanCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('default_billing_package', function ($table): void {
            $table->id();
            $table->integer('sort_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->float('km_price')->nullable();
            $table->string('currency')->nullable();
            $table->string('interval_period')->nullable();
            $table->integer('image_id')->nullable();
            $table->integer('hover_image_id')->nullable();
        });

        Schema::create('default_billing_package_translations', function ($table): void {
            $table->id();
            $table->integer('entry_id');
            $table->timestamp('created_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->string('locale');
            $table->string('name')->nullable();
        });

        Schema::create('default_billing_hosting', function ($table): void {
            $table->id();
            $table->integer('sort_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->float('price')->nullable();
            $table->string('currency')->nullable();
            $table->integer('image_count')->nullable();
        });

        Schema::create('default_billing_hosting_translations', function ($table): void {
            $table->id();
            $table->integer('entry_id');
            $table->timestamp('created_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('updated_by_id')->nullable();
            $table->string('locale');
            $table->string('name')->nullable();
        });

        Schema::getConnection()->table('default_billing_package')->insert([
            [
                'id' => 2,
                'sort_order' => 2,
                'created_at' => '2026-06-01 01:02:03',
                'created_by_id' => 20,
                'updated_at' => '2026-06-02 01:02:03',
                'updated_by_id' => 21,
                'deleted_at' => null,
                'km_price' => 0.2,
                'currency' => 'USD',
                'interval_period' => 'month',
                'image_id' => 971,
                'hover_image_id' => 974,
            ],
            [
                'id' => 1,
                'sort_order' => 1,
                'created_at' => '2026-06-03 01:02:03',
                'created_by_id' => 22,
                'updated_at' => '2026-06-04 01:02:03',
                'updated_by_id' => 23,
                'deleted_at' => null,
                'km_price' => 150,
                'currency' => 'USD',
                'interval_period' => 'month',
                'image_id' => null,
                'hover_image_id' => null,
            ],
        ]);

        Schema::getConnection()->table('default_billing_package_translations')->insert([
            ['entry_id' => 2, 'locale' => 'en', 'name' => 'Imagery'],
            ['entry_id' => 1, 'locale' => 'en', 'name' => 'Private Hosting'],
        ]);

        Schema::getConnection()->table('default_billing_hosting')->insert([
            [
                'id' => 1,
                'sort_order' => 1,
                'created_at' => '2026-07-01 01:02:03',
                'created_by_id' => null,
                'updated_at' => '2026-07-02 01:02:03',
                'updated_by_id' => null,
                'deleted_at' => null,
                'price' => 45,
                'currency' => null,
                'image_count' => 200000,
            ],
            [
                'id' => 2,
                'sort_order' => 2,
                'created_at' => '2026-07-03 01:02:03',
                'created_by_id' => null,
                'updated_at' => '2026-07-04 01:02:03',
                'updated_by_id' => null,
                'deleted_at' => null,
                'price' => 55,
                'currency' => null,
                'image_count' => 200000,
            ],
        ]);

        Schema::getConnection()->table('default_billing_hosting_translations')->insert([
            ['entry_id' => 1, 'locale' => 'en', 'name' => 'Regular Images'],
            ['entry_id' => 2, 'locale' => 'en', 'name' => 'Panoramas'],
        ]);
    }

    public function test_legacy_package_list_preserves_wrapper_and_price_shape(): void
    {
        $assetRoot = config('app.url');

        $this->getJson('/api/package-list')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'id' => 1,
                        'sort_order' => 1,
                        'created_at' => '2026-06-03T01:02:03.000000Z',
                        'created_by_id' => 22,
                        'updated_at' => '2026-06-04T01:02:03.000000Z',
                        'updated_by_id' => 23,
                        'deleted_at' => null,
                        'km_price' => '150',
                        'currency' => 'USD',
                        'interval_period' => 'month',
                        'image_id' => null,
                        'hover_image_id' => null,
                        'image_url' => null,
                        'hover_image_url' => null,
                        'name' => 'Private Hosting',
                    ],
                    [
                        'id' => 2,
                        'sort_order' => 2,
                        'created_at' => '2026-06-01T01:02:03.000000Z',
                        'created_by_id' => 20,
                        'updated_at' => '2026-06-02T01:02:03.000000Z',
                        'updated_by_id' => 21,
                        'deleted_at' => null,
                        'km_price' => '0.2',
                        'currency' => 'USD',
                        'interval_period' => 'month',
                        'image_id' => 971,
                        'hover_image_id' => 974,
                        'image_url' => $assetRoot,
                        'hover_image_url' => $assetRoot,
                        'name' => 'Imagery',
                    ],
                ],
                'pagination' => [
                    'current_page' => 1,
                    'first_page_url' => '/api/package-list?page=1',
                    'from' => 1,
                    'last_page' => 1,
                    'last_page_url' => '/api/package-list?page=1',
                    'links' => [
                        ['url' => null, 'label' => '&laquo; Previous', 'active' => false],
                        ['url' => '/api/package-list?page=1', 'label' => '1', 'active' => true],
                        ['url' => null, 'label' => 'Next &raquo;', 'active' => false],
                    ],
                    'next_page_url' => null,
                    'path' => '/api/package-list',
                    'per_page' => 15,
                    'prev_page_url' => null,
                    'to' => 2,
                    'total' => 2,
                ],
            ]);
    }

    public function test_legacy_hosting_list_preserves_wrapper_and_price_shape(): void
    {
        $this->getJson('/api/hosting-list')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'id' => 1,
                        'sort_order' => 1,
                        'created_at' => '2026-07-01T01:02:03.000000Z',
                        'created_by_id' => null,
                        'updated_at' => '2026-07-02T01:02:03.000000Z',
                        'updated_by_id' => null,
                        'deleted_at' => null,
                        'price' => '45',
                        'currency' => null,
                        'image_count' => 200000,
                        'name' => 'Regular Images',
                    ],
                    [
                        'id' => 2,
                        'sort_order' => 2,
                        'created_at' => '2026-07-03T01:02:03.000000Z',
                        'created_by_id' => null,
                        'updated_at' => '2026-07-04T01:02:03.000000Z',
                        'updated_by_id' => null,
                        'deleted_at' => null,
                        'price' => '55',
                        'currency' => null,
                        'image_count' => 200000,
                        'name' => 'Panoramas',
                    ],
                ],
                'pagination' => [
                    'current_page' => 1,
                    'first_page_url' => '/api/hosting-list?page=1',
                    'from' => 1,
                    'last_page' => 1,
                    'last_page_url' => '/api/hosting-list?page=1',
                    'links' => [
                        ['url' => null, 'label' => '&laquo; Previous', 'active' => false],
                        ['url' => '/api/hosting-list?page=1', 'label' => '1', 'active' => true],
                        ['url' => null, 'label' => 'Next &raquo;', 'active' => false],
                    ],
                    'next_page_url' => null,
                    'path' => '/api/hosting-list',
                    'per_page' => 15,
                    'prev_page_url' => null,
                    'to' => 2,
                    'total' => 2,
                ],
            ]);
    }

    public function test_empty_billing_pages_return_data_null(): void
    {
        $this->getJson('/api/package-list?page=2')
            ->assertOk()
            ->assertExactJson(['data' => null]);

        $this->getJson('/api/hosting-list?page=2')
            ->assertOk()
            ->assertExactJson(['data' => null]);
    }

    public function test_versioned_billing_aliases_return_same_contract(): void
    {
        $legacyPackages = $this->getJson('/api/package-list')
            ->assertOk()
            ->json();
        $versionedPackages = $this->getJson('/api/v1/billing/packages')
            ->assertOk()
            ->json();

        $this->assertSame($legacyPackages, $versionedPackages);

        $legacyHosting = $this->getJson('/api/hosting-list')
            ->assertOk()
            ->json();
        $versionedHosting = $this->getJson('/api/v1/billing/hosting')
            ->assertOk()
            ->json();

        $this->assertSame($legacyHosting, $versionedHosting);
    }
}

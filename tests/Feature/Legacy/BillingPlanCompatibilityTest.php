<?php

namespace Tests\Feature\Legacy;

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BillingPlanCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mapilio.rate_limiting.enabled', false);
        Config::set('mapilio.rate_limiting.enforce', false);
        Config::set('app.locale', 'en');
        RateLimiter::clear('mapilio-api|'.sha1('127.0.0.1'));

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
            ['entry_id' => 2, 'locale' => 'tr', 'name' => 'Goruntu'],
            ['entry_id' => 1, 'locale' => 'tr', 'name' => 'Ozel Barindirma'],
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

    public function test_versioned_packages_rows_preserve_exact_order_scalar_types_and_nullability(): void
    {
        Schema::getConnection()->table('default_billing_package')->insert([
            [
                'id' => 3,
                'sort_order' => null,
                'created_at' => null,
                'created_by_id' => null,
                'updated_at' => null,
                'updated_by_id' => null,
                'deleted_at' => null,
                'km_price' => null,
                'currency' => null,
                'interval_period' => null,
                'image_id' => null,
                'hover_image_id' => null,
            ],
        ]);
        Schema::getConnection()->table('default_billing_package_translations')->insert([
            ['entry_id' => 3, 'locale' => 'en', 'name' => null],
        ]);

        $payload = $this->getJson('/api/v1/billing/packages')->assertOk()->json();
        $row = collect($payload['data'])->firstWhere('id', 3);

        $this->assertIsArray($row);
        $this->assertSame([
            'id',
            'sort_order',
            'created_at',
            'created_by_id',
            'updated_at',
            'updated_by_id',
            'deleted_at',
            'km_price',
            'currency',
            'interval_period',
            'image_id',
            'hover_image_id',
            'image_url',
            'hover_image_url',
            'name',
        ], array_keys($row));
        $this->assertSame(3, $row['id']);
        $this->assertIsInt($row['id']);

        foreach ([
            'sort_order',
            'created_at',
            'created_by_id',
            'updated_at',
            'updated_by_id',
            'deleted_at',
            'km_price',
            'currency',
            'interval_period',
            'image_id',
            'hover_image_id',
            'image_url',
            'hover_image_url',
            'name',
        ] as $field) {
            $this->assertNull($row[$field]);
        }

        $populated = collect($payload['data'])->firstWhere('id', 2);
        $this->assertIsInt($populated['sort_order']);
        $this->assertIsString($populated['created_at']);
        $this->assertIsInt($populated['created_by_id']);
        $this->assertIsString($populated['updated_at']);
        $this->assertIsInt($populated['updated_by_id']);
        $this->assertIsString($populated['km_price']);
        $this->assertIsString($populated['currency']);
        $this->assertIsString($populated['interval_period']);
        $this->assertIsInt($populated['image_id']);
        $this->assertIsInt($populated['hover_image_id']);
        $this->assertIsString($populated['image_url']);
        $this->assertIsString($populated['hover_image_url']);
        $this->assertIsString($populated['name']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/',
            $populated['created_at'],
        );
    }

    public function test_packages_use_deployment_locale_and_fall_back_to_en_for_empty_or_non_string_locale(): void
    {
        Config::set('app.locale', 'tr');

        $this->getJson('/api/v1/billing/packages')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Ozel Barindirma');

        $this->getJson('/api/v1/billing/packages?locale=en')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Private Hosting');

        $this->withoutMiddleware(ConvertEmptyStringsToNull::class)
            ->call('GET', '/api/v1/billing/packages', ['locale' => ''])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Private Hosting');

        $this->call('GET', '/api/v1/billing/packages', ['locale' => ['tr']])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Private Hosting');
    }

    #[DataProvider('pageCoercionProvider')]
    public function test_packages_scalar_page_is_php_cast_and_minimum_clamped(
        ?string $page,
        int $expectedPage,
        bool $hasRows,
    ): void {
        $path = '/api/v1/billing/packages'.($page === null ? '' : '?page='.urlencode($page));
        $response = $this->getJson($path)->assertOk();

        if ($hasRows) {
            $response->assertJsonPath('pagination.current_page', $expectedPage);

            return;
        }

        $response->assertExactJson(['data' => null]);
    }

    /**
     * @return iterable<string, array{0: ?string, 1: int, 2: bool}>
     */
    public static function pageCoercionProvider(): iterable
    {
        yield 'omitted' => [null, 1, true];
        yield 'zero' => ['0', 1, true];
        yield 'negative' => ['-2', 1, true];
        yield 'fractional' => ['2.9', 2, false];
        yield 'non numeric' => ['not-a-page', 1, true];
        yield 'empty' => ['', 1, true];
    }

    public function test_packages_page_two_uses_a_hundred_row_offset_but_fifteen_row_legacy_metadata(): void
    {
        $rows = [];
        for ($id = 3; $id <= 101; $id++) {
            $rows[] = [
                'id' => $id,
                'sort_order' => null,
                'created_at' => null,
                'created_by_id' => null,
                'updated_at' => null,
                'updated_by_id' => null,
                'deleted_at' => null,
                'km_price' => null,
                'currency' => null,
                'interval_period' => null,
                'image_id' => null,
                'hover_image_id' => null,
            ];
        }
        Schema::getConnection()->table('default_billing_package')->insert($rows);

        $payload = $this->getJson('/api/v1/billing/packages?locale=en&view=synthetic&page=2')
            ->assertOk()
            ->json();

        $this->assertSame([101], array_column($payload['data'], 'id'));
        $this->assertSame(2, $payload['pagination']['current_page']);
        $this->assertSame(16, $payload['pagination']['from']);
        $this->assertSame(7, $payload['pagination']['last_page']);
        $this->assertSame(15, $payload['pagination']['per_page']);
        $this->assertSame(16, $payload['pagination']['to']);
        $this->assertSame(101, $payload['pagination']['total']);
        $this->assertSame('/api/package-list?locale=en&view=synthetic&page=1', $payload['pagination']['first_page_url']);
        $this->assertSame('/api/package-list?locale=en&view=synthetic&page=7', $payload['pagination']['last_page_url']);
        $this->assertSame('/api/package-list?locale=en&view=synthetic&page=1', $payload['pagination']['prev_page_url']);
        $this->assertSame('/api/package-list?locale=en&view=synthetic&page=3', $payload['pagination']['next_page_url']);
        $this->assertSame('/api/package-list?locale=en&view=synthetic&page=2', $payload['pagination']['links'][2]['url']);
        $this->assertSame('/api/package-list', $payload['pagination']['path']);
    }

    public function test_versioned_packages_use_request_scheme_and_host_for_image_urls(): void
    {
        $response = $this->getJson('https://pricing.synthetic.test/api/v1/billing/packages')
            ->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', 2);

        $this->assertSame('https://pricing.synthetic.test', $row['image_url']);
        $this->assertSame('https://pricing.synthetic.test', $row['hover_image_url']);
    }

    public function test_versioned_packages_are_bearer_irrelevant_and_preserve_conditional_header_behavior(): void
    {
        $withoutBearer = $this->getJson('/api/v1/billing/packages')
            ->assertOk()
            ->json();

        $withBearer = $this->withHeader('Authorization', 'Bearer synthetic-irrelevant-token')
            ->getJson('/api/v1/billing/packages')
            ->assertOk()
            ->json();

        $this->assertSame($withoutBearer, $withBearer);

        $response = $this->withHeaders([
            'If-None-Match' => '"synthetic-billing-etag"',
            'If-Modified-Since' => 'Wed, 01 Jul 2026 00:00:00 GMT',
        ])
            ->getJson('/api/v1/billing/packages')
            ->assertOk()
            ->assertJsonStructure(['data', 'pagination']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->headers->has('ETag'));
    }

    public function test_versioned_packages_optional_global_rate_limit_preserves_exact_envelope_and_headers(): void
    {
        Config::set('mapilio.rate_limiting.enabled', true);
        Config::set('mapilio.rate_limiting.enforce', true);
        Config::set('mapilio.rate_limiting.max_attempts', 1);
        RateLimiter::clear('mapilio-api|'.sha1('127.0.0.1'));

        $this->getJson('/api/v1/billing/packages')->assertOk();

        $response = $this->getJson('/api/v1/billing/packages')
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

    public function test_billing_malformed_timestamps_return_null(): void
    {
        $db = Schema::getConnection();

        $db->table('default_billing_package')->update([
            'created_at' => 'not-a-date',
            'updated_at' => 'not-a-date',
        ]);
        $db->table('default_billing_hosting')->update([
            'created_at' => 'not-a-date',
            'updated_at' => 'not-a-date',
        ]);

        $packages = $this->getJson('/api/package-list')->assertOk()->json();
        foreach ($packages['data'] as $package) {
            $this->assertNull($package['created_at']);
            $this->assertNull($package['updated_at']);
        }

        $hosting = $this->getJson('/api/hosting-list')->assertOk()->json();
        foreach ($hosting['data'] as $plan) {
            $this->assertNull($plan['created_at']);
            $this->assertNull($plan['updated_at']);
        }
    }

    public function test_empty_billing_pages_return_data_null(): void
    {
        $this->getJson('/api/package-list?page=2')
            ->assertOk()
            ->assertExactJson(['data' => null]);

        $this->getJson('/api/hosting-list?page=2')
            ->assertOk()
            ->assertExactJson(['data' => null]);

        $this->getJson('/api/v1/billing/packages?page=2')
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

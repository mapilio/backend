<?php

namespace Tests\Feature\Legacy;

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TypeMetadataCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('mapilio-api|'.sha1('127.0.0.1'));

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

    public function test_versioned_types_rows_preserve_exact_order_scalar_types_and_nullability(): void
    {
        Schema::getConnection()->table('default_types_type')->insert([
            [
                'id' => 4,
                'sort_order' => null,
                'created_at' => null,
                'created_by_id' => null,
                'updated_at' => null,
                'updated_by_id' => null,
                'deleted_at' => null,
                'code' => null,
                'group_id' => null,
                'icon' => null,
            ],
        ]);
        Schema::getConnection()->table('default_types_type_translations')->insert([
            ['entry_id' => 4, 'locale' => 'en', 'name' => null],
        ]);

        $payload = $this->getJson('/api/v1/inventory/types')->assertOk()->json();
        $row = $payload['data'][2];

        $this->assertSame([
            'id',
            'sort_order',
            'created_at',
            'created_by_id',
            'updated_at',
            'updated_by_id',
            'deleted_at',
            'code',
            'group_id',
            'icon',
            'name',
        ], array_keys($row));
        $this->assertSame(4, $row['id']);
        $this->assertIsInt($row['id']);
        $this->assertNull($row['sort_order']);
        $this->assertNull($row['created_at']);
        $this->assertNull($row['created_by_id']);
        $this->assertNull($row['updated_at']);
        $this->assertNull($row['updated_by_id']);
        $this->assertNull($row['deleted_at']);
        $this->assertNull($row['code']);
        $this->assertNull($row['group_id']);
        $this->assertNull($row['icon']);
        $this->assertNull($row['name']);

        $this->assertIsString($payload['data'][0]['created_at']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/',
            $payload['data'][0]['created_at'],
        );
        $this->assertIsInt($payload['data'][0]['sort_order']);
        $this->assertIsInt($payload['data'][0]['created_by_id']);
        $this->assertIsInt($payload['data'][0]['updated_by_id']);
        $this->assertIsInt($payload['data'][0]['group_id']);
        $this->assertIsString($payload['data'][0]['code']);
        $this->assertIsString($payload['data'][0]['name']);
    }

    public function test_types_use_deployment_locale_and_fall_back_to_en_for_empty_or_non_string_locale(): void
    {
        Config::set('app.locale', 'tr');

        $this->getJson('/api/v1/inventory/types')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Dur');

        $this->getJson('/api/v1/inventory/types?locale=en')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Stop');

        Config::set('app.locale', 'en');

        $this->getJson('/api/v1/inventory/types?locale=')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Stop');

        $this->getJson('/api/v1/inventory/types?locale[]=tr')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Stop');
    }

    #[DataProvider('pageCoercionProvider')]
    public function test_scalar_page_is_php_cast_and_minimum_clamped(
        ?string $page,
        int $expectedPage,
        bool $hasRows,
    ): void {
        $path = '/api/v1/inventory/types'.($page === null ? '' : '?page='.urlencode($page));
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

    public function test_ignored_web_options_do_not_change_fetch_or_metadata_and_are_echoed_in_legacy_urls(): void
    {
        $response = $this->getJson(
            '/api/v1/inventory/types?options[parameters][group_id]=999&options[paginate]=1&per_page=1&locale=en',
        )->assertOk();
        $payload = $response->json();
        $expectedQuery = 'options%5Bparameters%5D%5Bgroup_id%5D=999&options%5Bpaginate%5D=1&per_page=1&locale=en&page=1';

        $this->assertCount(2, $payload['data']);
        $this->assertSame('/api/get-types?'.$expectedQuery, $payload['pagination']['first_page_url']);
        $this->assertSame('/api/get-types?'.$expectedQuery, $payload['pagination']['last_page_url']);
        $this->assertSame('/api/get-types', $payload['pagination']['path']);
        $this->assertSame(15, $payload['pagination']['per_page']);
        $this->assertSame('/api/get-types?'.$expectedQuery, $payload['pagination']['links'][1]['url']);
    }

    public function test_end_of_pedestrians_uses_464_point_5_sort_position(): void
    {
        $rows = [];
        foreach ([463 => 'before-special', 464 => 'id-464', 465 => 'id-465', 500 => 'end-of-pedestrians'] as $id => $code) {
            $rows[] = [
                'id' => $id,
                'sort_order' => null,
                'created_at' => null,
                'created_by_id' => null,
                'updated_at' => null,
                'updated_by_id' => null,
                'deleted_at' => null,
                'code' => $code,
                'group_id' => null,
                'icon' => null,
            ];
        }
        Schema::getConnection()->table('default_types_type')->insert($rows);

        $payload = $this->getJson('/api/v1/inventory/types')->assertOk()->json();

        $this->assertSame([463, 464, 500, 465], array_slice(array_column($payload['data'], 'id'), -4));
    }

    public function test_page_two_uses_a_hundred_row_offset_but_fifteen_row_legacy_metadata(): void
    {
        $rows = [];
        for ($id = 10; $id <= 110; $id++) {
            $rows[] = [
                'id' => $id,
                'sort_order' => null,
                'created_at' => null,
                'created_by_id' => null,
                'updated_at' => null,
                'updated_by_id' => null,
                'deleted_at' => null,
                'code' => 'type-'.$id,
                'group_id' => null,
                'icon' => null,
            ];
        }
        Schema::getConnection()->table('default_types_type')->insert($rows);

        $payload = $this->getJson('/api/v1/inventory/types?page=2')->assertOk()->json();

        $this->assertSame([108, 109, 110], array_column($payload['data'], 'id'));
        $this->assertSame(2, $payload['pagination']['current_page']);
        $this->assertSame(16, $payload['pagination']['from']);
        $this->assertSame(7, $payload['pagination']['last_page']);
        $this->assertSame(15, $payload['pagination']['per_page']);
        $this->assertSame(18, $payload['pagination']['to']);
        $this->assertSame(103, $payload['pagination']['total']);
    }

    public function test_type_metadata_malformed_timestamps_return_null(): void
    {
        $db = Schema::getConnection();

        $db->table('default_types_type')->where('id', 1)->update([
            'created_at' => 'not-a-date',
            'updated_at' => 'not-a-date',
        ]);
        $db->table('default_types_groups')->where('id', 1)->update([
            'created_at' => 'not-a-date',
            'updated_at' => 'not-a-date',
        ]);

        $types = $this->getJson('/api/get-types')->assertOk()->json();
        $this->assertNull($types['data'][0]['created_at']);
        $this->assertNull($types['data'][0]['updated_at']);

        $versionedTypes = $this->getJson('/api/v1/inventory/types')->assertOk()->json();
        $this->assertSame($types, $versionedTypes);

        $groups = $this->getJson('/api/get-groups')->assertOk()->json();
        $this->assertNull($groups['data'][0]['created_at']);
        $this->assertNull($groups['data'][0]['updated_at']);
    }

    public function test_legacy_get_types_empty_page_omits_pagination(): void
    {
        $this->getJson('/api/get-types?page=2')
            ->assertOk()
            ->assertExactJson([
                'data' => null,
            ]);

        $this->getJson('/api/v1/inventory/types?page=2')
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

    public function test_versioned_groups_rows_preserve_exact_order_scalar_types_and_nullability(): void
    {
        Schema::getConnection()->table('default_types_groups')->insert([
            [
                'id' => 3,
                'sort_order' => null,
                'created_at' => 'not-a-date',
                'created_by_id' => null,
                'updated_at' => 'not-a-date',
                'updated_by_id' => null,
                'deleted_at' => null,
                'slug' => null,
            ],
        ]);

        $payload = $this->getJson('/api/v1/inventory/groups')->assertOk()->json();
        $data = $payload['data'];
        $this->assertIsArray($data);

        $row = null;
        foreach ($data as $candidate) {
            if (! is_array($candidate) || ($candidate['id'] ?? null) !== 3) {
                continue;
            }

            $row = $candidate;

            break;
        }
        $this->assertIsArray($row);

        $this->assertSame([
            'id',
            'sort_order',
            'created_at',
            'created_by_id',
            'updated_at',
            'updated_by_id',
            'deleted_at',
            'slug',
            'name',
        ], array_keys($row));
        $this->assertNull($row['sort_order']);
        $this->assertNull($row['created_at']);
        $this->assertNull($row['created_by_id']);
        $this->assertNull($row['updated_at']);
        $this->assertNull($row['updated_by_id']);
        $this->assertNull($row['deleted_at']);
        $this->assertNull($row['slug']);
        $this->assertNull($row['name']);

        $firstRow = null;
        foreach ($data as $candidate) {
            if (! is_array($candidate) || ($candidate['id'] ?? null) !== 2) {
                continue;
            }

            $firstRow = $candidate;

            break;
        }
        $this->assertIsArray($firstRow);
        $this->assertIsInt($firstRow['sort_order']);
        $this->assertIsString($firstRow['created_at']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/',
            $firstRow['created_at'],
        );
        $this->assertIsInt($firstRow['created_by_id']);
        $this->assertIsString($firstRow['updated_at']);
        $this->assertIsInt($firstRow['updated_by_id']);
        $this->assertIsString($firstRow['slug']);
        $this->assertIsString($firstRow['name']);
    }

    public function test_groups_use_deployment_locale_and_fall_back_to_en_for_empty_or_non_string_locale(): void
    {
        Schema::getConnection()->table('default_types_groups_translations')->insert([
            ['entry_id' => 1, 'locale' => 'tr', 'name' => 'Trafik Isaretleri'],
            ['entry_id' => 1, 'locale' => 'tr-TR', 'name' => 'Trafik Isaretleri TR'],
        ]);
        Config::set('app.locale', 'tr');

        $this->getJson('/api/v1/inventory/groups')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Trafik Isaretleri');

        $this->getJson('/api/v1/inventory/groups?locale=en')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Traffic Signs');

        $this->getJson('/api/v1/inventory/groups?locale=tr-TR')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Trafik Isaretleri TR');

        $this->withoutMiddleware(ConvertEmptyStringsToNull::class)
            ->call('GET', '/api/v1/inventory/groups', ['locale' => ''])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Traffic Signs');

        $this->call('GET', '/api/v1/inventory/groups', ['locale' => ['tr']])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Traffic Signs');
    }

    #[DataProvider('pageCoercionProvider')]
    public function test_groups_scalar_page_is_php_cast_and_minimum_clamped(
        ?string $page,
        int $expectedPage,
        bool $hasRows,
    ): void {
        $path = '/api/v1/inventory/groups'.($page === null ? '' : '?page='.urlencode($page));
        $response = $this->getJson($path)->assertOk();

        if ($hasRows) {
            $response->assertJsonPath('pagination.current_page', $expectedPage);

            return;
        }

        $response->assertExactJson(['data' => null]);
    }

    public function test_groups_filter_deleted_rows_and_empty_pages_omit_pagination(): void
    {
        Schema::getConnection()->table('default_types_groups')->insert([
            [
                'id' => 99,
                'sort_order' => 99,
                'created_at' => null,
                'created_by_id' => null,
                'updated_at' => null,
                'updated_by_id' => null,
                'deleted_at' => '2026-02-05 01:02:03',
                'slug' => 'deleted-group',
            ],
        ]);

        $payload = $this->getJson('/api/v1/inventory/groups')->assertOk()->json();
        $this->assertSame([1, 2], array_column($payload['data'], 'id'));
        $this->assertSame(2, $payload['pagination']['total']);

        $this->getJson('/api/v1/inventory/groups?page=2')
            ->assertOk()
            ->assertExactJson(['data' => null]);
    }

    public function test_groups_page_two_uses_a_hundred_row_offset_but_fifteen_row_legacy_metadata(): void
    {
        $rows = [];
        for ($id = 10; $id <= 110; $id++) {
            $rows[] = [
                'id' => $id,
                'sort_order' => $id,
                'created_at' => null,
                'created_by_id' => null,
                'updated_at' => null,
                'updated_by_id' => null,
                'deleted_at' => null,
                'slug' => 'group-'.$id,
            ];
        }
        Schema::getConnection()->table('default_types_groups')->insert($rows);

        $payload = $this->getJson(
            '/api/v1/inventory/groups?options[parameters][slug]=ignored&options[paginate]=1&per_page=1&locale=en&page=2',
        )->assertOk()->json();

        $this->assertCount(3, $payload['data']);
        $this->assertSame(2, $payload['pagination']['current_page']);
        $this->assertSame(16, $payload['pagination']['from']);
        $this->assertSame(7, $payload['pagination']['last_page']);
        $this->assertSame(15, $payload['pagination']['per_page']);
        $this->assertSame(18, $payload['pagination']['to']);
        $this->assertSame(103, $payload['pagination']['total']);

        $expectedQuery = 'options%5Bparameters%5D%5Bslug%5D=ignored&options%5Bpaginate%5D=1&per_page=1&locale=en';
        $this->assertSame('/api/get-groups?'.$expectedQuery.'&page=1', $payload['pagination']['first_page_url']);
        $this->assertSame('/api/get-groups?'.$expectedQuery.'&page=3', $payload['pagination']['next_page_url']);
        $this->assertSame('/api/get-groups', $payload['pagination']['path']);
        $this->assertSame('/api/get-groups?'.$expectedQuery.'&page=7', $payload['pagination']['last_page_url']);
        $this->assertSame('/api/get-groups?'.$expectedQuery.'&page=2', $payload['pagination']['links'][2]['url']);
    }

    public function test_groups_optional_global_rate_limit_preserves_legacy_error_envelope(): void
    {
        Config::set('mapilio.rate_limiting.enabled', true);
        Config::set('mapilio.rate_limiting.enforce', true);
        Config::set('mapilio.rate_limiting.max_attempts', 1);
        RateLimiter::clear('mapilio-api|'.sha1('127.0.0.1'));

        $this->getJson('/api/v1/inventory/groups')->assertOk();

        $this->getJson('/api/v1/inventory/groups')
            ->assertStatus(429)
            ->assertExactJson([
                'success' => false,
                'message' => ['Too many requests.'],
                'error_code' => 429,
            ])
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '1')
            ->assertHeader('X-RateLimit-Remaining', '0');
    }

    public function test_sprite_metadata_paths_return_public_sprite_maps(): void
    {
        $this->getJson('/api/get-sprites')->assertOk();

        $this->getJson('/api/get-sprites2x')
            ->assertOk()
            ->assertJsonPath('detect-comp-accident-area-c3.width', 48);
    }

    public function test_standard_sprite_metadata_has_exact_legacy_shape_and_scalar_constraints(): void
    {
        $legacy = $this->getJson('/api/get-sprites')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/inventory/sprites')
            ->assertOk()
            ->json();

        $this->assertSame($legacy, $versioned);
        $fields = ['x', 'y', 'height', 'width', 'visible', 'pixelRatio'];
        $expectedFields = $fields;
        sort($expectedFields);
        $violations = [];

        if (! is_array($versioned)) {
            $violations['<response>'] = 'top-level response is not an object map';
        } else {
            foreach ($versioned as $code => $sprite) {
                $reasons = [];

                if (! is_array($sprite)) {
                    $violations[(string) $code] = 'value is not an object';

                    continue;
                }

                $actualFields = array_keys($sprite);
                sort($actualFields);
                if ($actualFields !== $expectedFields) {
                    $reasons[] = 'fields '.json_encode($actualFields).' do not match the required set';
                }

                foreach (['x' => 0, 'y' => 0, 'height' => 1, 'width' => 1, 'pixelRatio' => 1] as $field => $minimum) {
                    if (! array_key_exists($field, $sprite)) {
                        $reasons[] = $field.' is missing';

                        continue;
                    }

                    if (! is_int($sprite[$field])) {
                        $reasons[] = $field.' is not an integer';

                        continue;
                    }

                    if ($sprite[$field] < $minimum) {
                        $reasons[] = $field.' is below '.$minimum;
                    }
                }

                if (! array_key_exists('visible', $sprite)) {
                    $reasons[] = 'visible is missing';
                } elseif (! is_bool($sprite['visible'])) {
                    $reasons[] = 'visible is not a boolean';
                }

                if ($reasons !== []) {
                    $violations[(string) $code] = implode('; ', $reasons);
                }
            }
        }

        $this->assertSame([], $violations, 'Invalid standard sprite metadata: '.json_encode($violations, JSON_UNESCAPED_SLASHES));
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

    public function test_retina_sprite_metadata_has_exact_legacy_shape_and_scalar_constraints(): void
    {
        $legacy = $this->getJson('/api/get-sprites2x')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/inventory/sprites-2x')
            ->assertOk()
            ->json();

        $this->assertSame($legacy, $versioned);
        $fields = ['x', 'y', 'height', 'width', 'visible', 'pixelRatio'];
        $expectedFields = $fields;
        sort($expectedFields);
        $violations = [];

        if (! is_array($versioned)) {
            $violations['<response>'] = 'top-level response is not an object map';
        } else {
            foreach ($versioned as $code => $sprite) {
                $reasons = [];

                if (! is_array($sprite)) {
                    $violations[(string) $code] = 'value is not an object';

                    continue;
                }

                $actualFields = array_keys($sprite);
                sort($actualFields);
                if ($actualFields !== $expectedFields) {
                    $reasons[] = 'fields '.json_encode($actualFields).' do not match the required set';
                }

                foreach (['x' => 0, 'y' => 0, 'height' => 1, 'width' => 1, 'pixelRatio' => 1] as $field => $minimum) {
                    if (! array_key_exists($field, $sprite)) {
                        $reasons[] = $field.' is missing';

                        continue;
                    }

                    if (! is_int($sprite[$field])) {
                        $reasons[] = $field.' is not an integer';

                        continue;
                    }

                    if ($sprite[$field] < $minimum) {
                        $reasons[] = $field.' is below '.$minimum;
                    }
                }

                if (! array_key_exists('visible', $sprite)) {
                    $reasons[] = 'visible is missing';
                } elseif (! is_bool($sprite['visible'])) {
                    $reasons[] = 'visible is not a boolean';
                }

                if ($reasons !== []) {
                    $violations[(string) $code] = implode('; ', $reasons);
                }
            }
        }

        $this->assertSame([], $violations, 'Invalid retina sprite metadata: '.json_encode($violations, JSON_UNESCAPED_SLASHES));
    }
}

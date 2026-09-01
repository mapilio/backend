<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserUploadDetailsCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        $this->seedData();
    }

    public function test_legacy_user_uploads_detail_v2_preserves_mobile_feed_detail_contract(): void
    {
        $this->getJson('/api/user-uploads-detail-v2?options[parameters][user_id]=10&options[parameters][group_key]=group-new&options[limit]=2&page=1')
            ->assertOk()
            ->assertJsonPath('data.0.filename', 'first.jpeg')
            ->assertJsonPath('data.0.last_status', 'completed')
            ->assertJsonPath('data.0.sequence_uuid', 'sequence-new-a')
            ->assertJsonPath('data.0.id', 1)
            ->assertJsonPath('data.0.img_code', 'hash-a')
            ->assertJsonPath('data.0.latitude', '41.073179701381')
            ->assertJsonPath('data.0.longitude', '-81.517028929742')
            ->assertJsonPath('data.0.heading', 200.0390625)
            ->assertJsonPath('data.0.created_by_id', 10)
            ->assertJsonPath('data.0.created_at', '2026-07-07T19:46:30.000000Z')
            ->assertJsonPath('data.0.capture_time', '2026-05-08 17:09:11')
            ->assertJsonPath('data.1.id', 2)
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonPath('pagination.next_page_url', '/api/user-uploads-detail-v2?options%5Bparameters%5D%5Buser_id%5D=10&options%5Bparameters%5D%5Bgroup_key%5D=group-new&options%5Blimit%5D=2&page=2')
            ->assertJsonPath('pagination.path', '/api/user-uploads-detail-v2')
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.total', 4);
    }

    public function test_legacy_user_uploads_detail_v2_orders_by_imagery_id(): void
    {
        $this->getJson('/api/user-uploads-detail-v2?options[parameters][user_id]=10&options[parameters][group_key]=group-new&options[limit]=10&page=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', 1)
            ->assertJsonPath('data.1.id', 2)
            ->assertJsonPath('data.2.id', 4)
            ->assertJsonPath('data.3.id', 6);
    }

    public function test_populated_response_has_exact_row_keys_scalars_nulls_timestamps_and_pagination(): void
    {
        $rowFields = ['filename', 'last_status', 'sequence_uuid', 'id', 'img_code', 'latitude', 'longitude', 'heading', 'created_by_id', 'created_at', 'capture_time'];
        $response = $this->getJson('/api/user-uploads-detail-v2?options[parameters][user_id]=10&options[parameters][group_key]=group-new&options[limit]=4&page=1')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'filename' => 'first.jpeg',
                        'last_status' => 'completed',
                        'sequence_uuid' => 'sequence-new-a',
                        'id' => 1,
                        'img_code' => 'hash-a',
                        'latitude' => '41.073179701381',
                        'longitude' => '-81.517028929742',
                        'heading' => 200.0390625,
                        'created_by_id' => 10,
                        'created_at' => '2026-07-07T19:46:30.000000Z',
                        'capture_time' => '2026-05-08 17:09:11',
                    ],
                    [
                        'filename' => 'second.jpeg',
                        'last_status' => 'completed',
                        'sequence_uuid' => 'sequence-new-b',
                        'id' => 2,
                        'img_code' => 'hash-b',
                        'latitude' => '41.073129368053',
                        'longitude' => '-81.517028259189',
                        'heading' => 198.28125,
                        'created_by_id' => 10,
                        'created_at' => '2026-07-07T19:46:31.000000Z',
                        'capture_time' => '2026-05-08 17:09:55',
                    ],
                    [
                        'filename' => 'third.jpeg',
                        'last_status' => 'completed',
                        'sequence_uuid' => 'sequence-new-c',
                        'id' => 4,
                        'img_code' => 'hash-c',
                        'latitude' => '41.073082513214',
                        'longitude' => '-81.517021888943',
                        'heading' => 168.046875,
                        'created_by_id' => 10,
                        'created_at' => '2026-07-07T19:46:33.000000Z',
                        'capture_time' => '2026-05-08 17:09:57',
                    ],
                    [
                        'filename' => null,
                        'last_status' => null,
                        'sequence_uuid' => 'sequence-new-d',
                        'id' => 6,
                        'img_code' => null,
                        'latitude' => null,
                        'longitude' => null,
                        'heading' => null,
                        'created_by_id' => 10,
                        'created_at' => null,
                        'capture_time' => null,
                    ],
                ],
                'pagination' => [
                    'current_page' => 1,
                    'first_page_url' => '/api/user-uploads-detail-v2?options%5Bparameters%5D%5Buser_id%5D=10&options%5Bparameters%5D%5Bgroup_key%5D=group-new&options%5Blimit%5D=4&page=1',
                    'from' => 1,
                    'last_page' => 1,
                    'last_page_url' => '/api/user-uploads-detail-v2?options%5Bparameters%5D%5Buser_id%5D=10&options%5Bparameters%5D%5Bgroup_key%5D=group-new&options%5Blimit%5D=4&page=1',
                    'links' => [
                        ['url' => null, 'label' => '&laquo; Previous', 'active' => false],
                        ['url' => '/api/user-uploads-detail-v2?options%5Bparameters%5D%5Buser_id%5D=10&options%5Bparameters%5D%5Bgroup_key%5D=group-new&options%5Blimit%5D=4&page=1', 'label' => '1', 'active' => true],
                        ['url' => null, 'label' => 'Next &raquo;', 'active' => false],
                    ],
                    'next_page_url' => null,
                    'path' => '/api/user-uploads-detail-v2',
                    'per_page' => 4,
                    'prev_page_url' => null,
                    'to' => 4,
                    'total' => 4,
                ],
            ])
            ->json();

        $this->assertSame($rowFields, array_keys($response['data'][0]));
        $this->assertSame($rowFields, array_keys($response['data'][3]));
    }

    public function test_legacy_user_uploads_detail_v2_empty_results_preserve_data_null_without_pagination(): void
    {
        $this->getJson('/api/user-uploads-detail-v2?options[parameters][user_id]=99&options[parameters][group_key]=missing&options[limit]=10&page=1')
            ->assertOk()
            ->assertExactJson([
                'data' => null,
            ]);
    }

    public function test_legacy_user_uploads_detail_v2_out_of_range_page_is_exactly_data_null(): void
    {
        $this->getJson('/api/user-uploads-detail-v2?options[parameters][user_id]=10&options[parameters][group_key]=group-new&options[limit]=2&page=3')
            ->assertOk()
            ->assertExactJson(['data' => null]);
    }

    public function test_pagination_urls_reencode_parsed_query_keys_and_overwrite_page(): void
    {
        $query = 'options[parameters][user_id]=10&options[parameters][group_key]=group-new&options[limit]=2&ignored=hello%20world&options[extra][flag]=x&page=1';
        $base = '/api/user-uploads-detail-v2?options%5Bparameters%5D%5Buser_id%5D=10&options%5Bparameters%5D%5Bgroup_key%5D=group-new&options%5Blimit%5D=2&options%5Bextra%5D%5Bflag%5D=x&ignored=hello+world&page=';

        $this->getJson('/api/user-uploads-detail-v2?'.$query)
            ->assertOk()
            ->assertJsonPath('pagination.first_page_url', $base.'1')
            ->assertJsonPath('pagination.last_page_url', $base.'2')
            ->assertJsonPath('pagination.next_page_url', $base.'2')
            ->assertJsonPath('pagination.links.1.url', $base.'1')
            ->assertJsonPath('pagination.links.2.url', $base.'2');
    }

    public function test_legacy_user_uploads_detail_v2_missing_parameters_preserve_error_shape(): void
    {
        foreach (['/api/user-uploads-detail-v2', '/api/v1/imagery/user-upload-details'] as $path) {
            $this->getJson($path)
                ->assertStatus(400)
                ->assertExactJson([
                    'success' => false,
                    'message' => ["'user_id' is required!"],
                    'error_code' => 400,
                ]);

            $this->getJson($path.'?options[parameters][user_id]=10')
                ->assertStatus(400)
                ->assertExactJson([
                    'success' => false,
                    'message' => ["'group_key' is required!"],
                    'error_code' => 400,
                ]);
        }
    }

    public function test_versioned_user_upload_details_alias_returns_same_data_contract(): void
    {
        $legacy = $this->getJson('/api/user-uploads-detail-v2?options[parameters][user_id]=10&options[parameters][group_key]=group-new&options[limit]=2&page=1')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/imagery/user-upload-details?options[parameters][user_id]=10&options[parameters][group_key]=group-new&options[limit]=2&page=1')
            ->assertOk()
            ->json();

        $this->assertSame($legacy, $versioned);
    }

    /**
     * @param  array{success: false, message: list<string>, error_code: 400}  $expected
     */
    #[DataProvider('invalidRequiredScalarProvider')]
    public function test_invalid_required_scalars_preserve_exact_error_precedence(string $path, string $query, array $expected): void
    {
        $this->getJson($path.$query)
            ->assertStatus(400)
            ->assertExactJson($expected);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: array{success: false, message: list<string>, error_code: 400}}>
     */
    public static function invalidRequiredScalarProvider(): iterable
    {
        $userIdError = [
            'success' => false,
            'message' => ["'user_id' is required!"],
            'error_code' => 400,
        ];
        $groupKeyError = [
            'success' => false,
            'message' => ["'group_key' is required!"],
            'error_code' => 400,
        ];

        foreach (['/api/user-uploads-detail-v2', '/api/v1/imagery/user-upload-details'] as $path) {
            foreach (['nonnumeric' => 'not-a-number', 'empty' => '', 'zero' => '0', 'negative' => '-10'] as $case => $userId) {
                yield $path.' '.$case.' user_id' => [$path, '?options[parameters][user_id]='.$userId.'&options[parameters][group_key]=group-new', $userIdError];
            }

            yield $path.' empty group_key' => [$path, '?options[parameters][user_id]=10&options[parameters][group_key]=', $groupKeyError];
        }
    }

    /**
     * @param  list<int>  $expectedIds
     */
    #[DataProvider('supportedScalarCoercionAndDefaultProvider')]
    public function test_supported_scalar_coercion_defaults_and_precedence(string $query, array $expectedIds, int $expectedLimit, int $expectedPage): void
    {
        $response = $this->getJson('/api/v1/imagery/user-upload-details?'.$query)
            ->assertOk()
            ->json();

        $this->assertSame($expectedIds, array_column($response['data'], 'id'));
        $this->assertSame($expectedLimit, $response['pagination']['per_page']);
        $this->assertSame($expectedPage, $response['pagination']['current_page']);
    }

    /**
     * @return iterable<string, array{0: string, 1: list<int>, 2: int, 3: int}>
     */
    public static function supportedScalarCoercionAndDefaultProvider(): iterable
    {
        yield 'decimal scalar values truncate before pagination' => [
            'options[parameters][user_id]=10.9&options[parameters][group_key]=group-new&options[limit]=2.9&page=2.9',
            [4, 6],
            2,
            2,
        ];
        yield 'omitted pagination values use legacy defaults' => [
            'options[parameters][user_id]=10&options[parameters][group_key]=group-new',
            [1, 2, 4, 6],
            15,
            1,
        ];
        yield 'zero scalar values clamp to one' => [
            'options[parameters][user_id]=10&options[parameters][group_key]=group-new&options[limit]=0&page=0',
            [1],
            1,
            1,
        ];
        yield 'negative scalar values clamp to one' => [
            'options[parameters][user_id]=10&options[parameters][group_key]=group-new&options[limit]=-4&page=-2',
            [1],
            1,
            1,
        ];
        yield 'nonnumeric scalar values cast to zero then clamp to one' => [
            'options[parameters][user_id]=10&options[parameters][group_key]=group-new&options[limit]=not-a-number&page=not-a-page',
            [1],
            1,
            1,
        ];
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function nonEmptyGroupKeyProvider(): iterable
    {
        yield 'legacy alias accepts zero group key' => ['/api/user-uploads-detail-v2', '?options[parameters][user_id]=10&options[parameters][group_key]=0'];
        yield 'versioned alias accepts zero group key' => ['/api/v1/imagery/user-upload-details', '?options[parameters][user_id]=10&options[parameters][group_key]=0'];
    }

    #[DataProvider('nonEmptyGroupKeyProvider')]
    public function test_nonempty_scalar_group_key_zero_is_accepted(string $path, string $query): void
    {
        $this->getJson($path.$query)
            ->assertOk()
            ->assertExactJson(['data' => null]);
    }

    private function createTables(): void
    {
        Schema::create('default_mapilio_imagery', function ($table): void {
            $table->id();
            $table->integer('created_by_id')->nullable();
            $table->string('sequence_uuid')->nullable();
            $table->string('uploaded_hash')->nullable();
            $table->string('filename')->nullable();
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
            $table->float('heading')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('capture_time')->nullable();
            $table->boolean('anomaly')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_mapilio_sequence_detail', function ($table): void {
            $table->id();
            $table->integer('created_by_id')->nullable();
            $table->string('sequence_uuid')->nullable();
            $table->string('group_key')->nullable();
            $table->string('last_status')->nullable();
            $table->boolean('anomaly')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });
    }

    private function seedData(): void
    {
        Schema::getConnection()->table('default_mapilio_imagery')->insert([
            [
                'id' => 2,
                'created_by_id' => 10,
                'sequence_uuid' => 'sequence-new-b',
                'uploaded_hash' => 'hash-b',
                'filename' => 'second.jpeg',
                'latitude' => '41.073129368053',
                'longitude' => '-81.517028259189',
                'heading' => 198.28125,
                'created_at' => '2026-07-07 19:46:31',
                'capture_time' => '2026-05-08 17:09:55',
                'anomaly' => false,
                'deleted_at' => null,
            ],
            [
                'id' => 1,
                'created_by_id' => 10,
                'sequence_uuid' => 'sequence-new-a',
                'uploaded_hash' => 'hash-a',
                'filename' => 'first.jpeg',
                'latitude' => '41.073179701381',
                'longitude' => '-81.517028929742',
                'heading' => 200.0390625,
                'created_at' => '2026-07-07 19:46:30',
                'capture_time' => '2026-05-08 17:09:11',
                'anomaly' => false,
                'deleted_at' => null,
            ],
            [
                'id' => 3,
                'created_by_id' => 10,
                'sequence_uuid' => 'sequence-anomaly',
                'uploaded_hash' => 'hash-anomaly',
                'filename' => 'anomaly.jpeg',
                'latitude' => '41.0',
                'longitude' => '-81.0',
                'heading' => 1,
                'created_at' => '2026-07-07 19:46:32',
                'capture_time' => '2026-05-08 17:09:57',
                'anomaly' => true,
                'deleted_at' => null,
            ],
            [
                'id' => 4,
                'created_by_id' => 10,
                'sequence_uuid' => 'sequence-new-c',
                'uploaded_hash' => 'hash-c',
                'filename' => 'third.jpeg',
                'latitude' => '41.073082513214',
                'longitude' => '-81.517021888943',
                'heading' => 168.046875,
                'created_at' => '2026-07-07 19:46:33',
                'capture_time' => '2026-05-08 17:09:57',
                'anomaly' => false,
                'deleted_at' => null,
            ],
            [
                'id' => 5,
                'created_by_id' => 11,
                'sequence_uuid' => 'sequence-other-user',
                'uploaded_hash' => 'hash-other-user',
                'filename' => 'other-user.jpeg',
                'latitude' => '41.1',
                'longitude' => '-81.1',
                'heading' => 2,
                'created_at' => '2026-07-07 19:46:34',
                'capture_time' => '2026-05-08 17:09:58',
                'anomaly' => false,
                'deleted_at' => null,
            ],
            [
                'id' => 6,
                'created_by_id' => 10,
                'sequence_uuid' => 'sequence-new-d',
                'uploaded_hash' => null,
                'filename' => null,
                'latitude' => null,
                'longitude' => null,
                'heading' => null,
                'created_at' => null,
                'capture_time' => null,
                'anomaly' => false,
                'deleted_at' => null,
            ],
        ]);

        Schema::getConnection()->table('default_mapilio_sequence_detail')->insert([
            [
                'created_by_id' => 10,
                'sequence_uuid' => 'sequence-new-a',
                'group_key' => 'group-new',
                'last_status' => 'completed',
                'anomaly' => false,
                'deleted_at' => null,
            ],
            [
                'created_by_id' => 10,
                'sequence_uuid' => 'sequence-new-b',
                'group_key' => 'group-new',
                'last_status' => 'completed',
                'anomaly' => false,
                'deleted_at' => null,
            ],
            [
                'created_by_id' => 10,
                'sequence_uuid' => 'sequence-anomaly',
                'group_key' => 'group-new',
                'last_status' => 'completed',
                'anomaly' => false,
                'deleted_at' => null,
            ],
            [
                'created_by_id' => 10,
                'sequence_uuid' => 'sequence-new-c',
                'group_key' => 'group-new',
                'last_status' => 'completed',
                'anomaly' => false,
                'deleted_at' => null,
            ],
            [
                'created_by_id' => 11,
                'sequence_uuid' => 'sequence-other-user',
                'group_key' => 'group-new',
                'last_status' => 'completed',
                'anomaly' => false,
                'deleted_at' => null,
            ],
            [
                'created_by_id' => 10,
                'sequence_uuid' => 'sequence-new-d',
                'group_key' => 'group-new',
                'last_status' => null,
                'anomaly' => false,
                'deleted_at' => null,
            ],
        ]);
    }
}

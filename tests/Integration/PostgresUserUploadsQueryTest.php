<?php

namespace Tests\Integration;

use App\Domain\ImagerySequences\Queries\UserUploadsQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PostgresUserUploadsQueryTest extends TestCase
{
    private const CONNECTION = 'pgsql';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('true', getenv('MAPILIO_DISPOSABLE_DB_CONFIRMED'));
        $this->assertSame('testing', app()->environment());
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertSame('', config('database.connections.pgsql.url'));
        $this->assertSame('127.0.0.1', config('database.connections.pgsql.host'));
        $this->assertSame('5432', config('database.connections.pgsql.port'));
        $this->assertSame('mapilio_ci', DB::scalar('select current_database()'));
        $this->assertSame('mapilio_ci', DB::scalar('select current_user'));
        Config::set('mapilio.legacy_database_connection', self::CONNECTION);

        $schema = Schema::connection(self::CONNECTION);
        $schema->dropIfExists('default_mapilio_sequence_detail');
        $schema->dropIfExists('default_mapilio_imagery');

        $schema->create('default_mapilio_imagery', function ($table): void {
            $table->id();
            $table->integer('created_by_id')->nullable();
            $table->string('sequence_uuid')->nullable();
            $table->string('uploaded_hash')->nullable();
            $table->string('filename')->nullable();
            $table->timestamp('capture_time')->nullable();
            $table->boolean('anomaly')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });

        $schema->create('default_mapilio_sequence_detail', function ($table): void {
            $table->id();
            $table->integer('created_by_id')->nullable();
            $table->string('sequence_uuid')->nullable();
            $table->string('group_key')->nullable();
            $table->string('start_address')->nullable();
            $table->string('last_status')->nullable();
            $table->boolean('anomaly')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });

        $this->seedData();
    }

    public function test_nonempty_page_preserves_exact_contract_and_uses_one_select(): void
    {
        $queries = [];
        DB::connection(self::CONNECTION)->listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query;
        });

        $actual = (new UserUploadsQuery)->get(42, $this->request(1));

        $expectedData = [];
        for ($group = 1; $group <= 10; $group++) {
            $number = str_pad((string) $group, 2, '0', STR_PAD_LEFT);
            $expectedData[] = [
                'total' => $group === 1 ? 3 : 1,
                'uploaded_hash' => $group === 1 ? 'hash-01-latest' : 'hash-'.$number,
                'capture_time' => $group === 1
                    ? '2026-08-01 00:11:00'
                    : '2026-08-01 00:'.str_pad((string) (11 - $group), 2, '0', STR_PAD_LEFT).':00',
                'cover_photo' => $group === 1 ? 'photo-01-latest.jpg' : 'photo-'.$number.'.jpg',
                'group_key' => 'group-'.$number,
                'start_address' => $group === 1 ? 'Address One' : 'Address '.$group,
                'last_status' => $group === 1 ? 'latest' : 'status-'.$group,
            ];
        }

        $this->assertSame([
            'data' => $expectedData,
            'pagination' => [
                'current_page' => 1,
                'first_page_url' => '/api/user-uploads-v2?options%5Blimit%5D=10&page=1',
                'from' => 1,
                'last_page' => 1,
                'last_page_url' => '/api/user-uploads-v2?options%5Blimit%5D=10&page=1',
                'links' => [
                    [
                        'url' => null,
                        'label' => '&laquo; Previous',
                        'active' => false,
                    ],
                    [
                        'url' => '/api/user-uploads-v2?options%5Blimit%5D=10&page=1',
                        'label' => '1',
                        'active' => true,
                    ],
                    [
                        'url' => null,
                        'label' => 'Next &raquo;',
                        'active' => false,
                    ],
                ],
                'next_page_url' => null,
                'path' => '/api/user-uploads-v2',
                'per_page' => 10,
                'prev_page_url' => null,
                'to' => 10,
                'total' => 10,
            ],
        ], $actual);

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('count(*) over()', strtolower($queries[0]->sql));
        $this->assertStringContainsString('page_metadata', strtolower($queries[0]->sql));
    }

    public function test_out_of_range_page_uses_fallback_total(): void
    {
        $queries = [];
        DB::connection(self::CONNECTION)->listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query;
        });

        $actual = (new UserUploadsQuery)->get(42, $this->request(2));

        $this->assertNull($actual['data']);
        $this->assertSame(10, $actual['pagination']['total']);
        $this->assertSame(1, $actual['pagination']['last_page']);
        $this->assertNull($actual['pagination']['from']);
        $this->assertNull($actual['pagination']['to']);
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('count(*) over()', strtolower($queries[1]->sql));
    }

    private function request(int $page): Request
    {
        return Request::create('/api/user-uploads-v2', 'GET', [
            'options' => ['limit' => 10],
            'page' => $page,
        ]);
    }

    private function seedData(): void
    {
        $imagery = [];
        $details = [];

        for ($group = 1; $group <= 10; $group++) {
            $number = str_pad((string) $group, 2, '0', STR_PAD_LEFT);
            $imagery[] = [
                'id' => $group,
                'created_by_id' => 42,
                'sequence_uuid' => 'sequence-'.$number,
                'uploaded_hash' => 'hash-'.$number,
                'filename' => 'photo-'.$number.'.jpg',
                'capture_time' => '2026-08-01 00:'.str_pad((string) (11 - $group), 2, '0', STR_PAD_LEFT).':00',
                'anomaly' => false,
                'deleted_at' => null,
            ];
            $details[] = [
                'id' => $group * 100,
                'created_by_id' => 42,
                'sequence_uuid' => 'sequence-'.$number,
                'group_key' => 'group-'.$number,
                'start_address' => $group === 1 ? null : 'Address '.$group,
                'last_status' => $group === 1 ? 'uploaded' : 'status-'.$group,
                'anomaly' => false,
                'deleted_at' => null,
            ];
        }

        $imagery[] = [
            'id' => 11,
            'created_by_id' => 42,
            'sequence_uuid' => 'sequence-01-latest',
            'uploaded_hash' => 'hash-01-latest',
            'filename' => 'photo-01-latest.jpg',
            'capture_time' => '2026-08-01 00:11:00',
            'anomaly' => false,
            'deleted_at' => null,
        ];
        $imagery[] = [
            'id' => 12,
            'created_by_id' => 42,
            'sequence_uuid' => 'sequence-01-tie',
            'uploaded_hash' => 'hash-01-tie',
            'filename' => 'photo-01-tie.jpg',
            'capture_time' => '2026-08-01 00:11:00',
            'anomaly' => false,
            'deleted_at' => null,
        ];

        $details[] = [
            'id' => 103,
            'created_by_id' => 42,
            'sequence_uuid' => 'sequence-01-tie',
            'group_key' => 'group-01',
            'start_address' => 'Address Later',
            'last_status' => 'latest',
            'anomaly' => false,
            'deleted_at' => null,
        ];
        $details[] = [
            'id' => 101,
            'created_by_id' => 99,
            'sequence_uuid' => 'sequence-01',
            'group_key' => 'group-01',
            'start_address' => 'Address One',
            'last_status' => 'completed',
            'anomaly' => true,
            'deleted_at' => '2026-08-02 00:00:00',
        ];
        $details[] = [
            'id' => 102,
            'created_by_id' => 42,
            'sequence_uuid' => 'sequence-01-latest',
            'group_key' => 'group-01',
            'start_address' => 'Address Middle',
            'last_status' => 'processing',
            'anomaly' => false,
            'deleted_at' => null,
        ];

        DB::connection(self::CONNECTION)->table('default_mapilio_imagery')->insert($imagery);
        DB::connection(self::CONNECTION)->table('default_mapilio_sequence_detail')->insert($details);
    }
}

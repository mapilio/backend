<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AssertsQueryBudgets;
use Tests\TestCase;

class PublicReadBoundsPaginationTest extends TestCase
{
    use AssertsQueryBudgets;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('default_mapilio_imagery', function ($table): void {
            $table->integer('id')->primary();
            $table->string('photo_uuid')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->timestamp('capture_time')->nullable();
            $table->string('filename')->nullable();
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
            $table->string('uploaded_hash')->nullable();
            $table->string('sequence_uuid');
            $table->integer('heading')->nullable();
            $table->string('resolution')->nullable();
            $table->string('fov')->nullable();
            $table->string('vfov')->nullable();
            $table->string('pitch')->nullable();
            $table->boolean('anomaly')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_mapilio_sequence_detail', function ($table): void {
            $table->id();
            $table->string('sequence_uuid');
            $table->string('start_address')->nullable();
            $table->string('group_key')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_mapilio_road', function ($table): void {
            $table->id();
            $table->string('sequence_uuid');
            $table->text('linefeature')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::getConnection()->table('default_mapilio_sequence_detail')->insert([
            'sequence_uuid' => 'seq-public',
            'start_address' => 'North Road',
            'group_key' => 'group-public',
        ]);

        Schema::getConnection()->table('default_mapilio_imagery')->insert([
            $this->image(3, '2026-01-01 12:00:02', 'third.jpg'),
            $this->image(1, '2026-01-01 12:00:01', 'first.jpg'),
            $this->image(2, '2026-01-01 12:00:02', 'second.jpg'),
            $this->image(4, '2026-01-01 12:00:04', 'fourth.jpg'),
        ]);

        Schema::getConnection()->table('default_mapilio_road')->insert([
            ['sequence_uuid' => 'seq-public', 'linefeature' => 'road-1'],
            ['sequence_uuid' => 'seq-public', 'linefeature' => 'road-2'],
            ['sequence_uuid' => 'seq-public', 'linefeature' => 'road-3'],
        ]);
    }

    public function test_legacy_ignores_pagination_and_full_read_can_be_exactly_at_row_limit(): void
    {
        Config::set('mapilio.public_read_bounds.max_imagery_rows', 4);

        $this->getJson('/api/sequence-detail?sequence_uuid=seq-public&page=2&per_page=1')
            ->assertOk()
            ->assertJsonMissingPath('pagination')
            ->assertJsonCount(4, 'data');
    }

    public function test_full_read_row_overflow_is_413_and_sql_is_bounded(): void
    {
        Config::set('mapilio.public_read_bounds.max_imagery_rows', 3);
        $sql = [];
        $this->app['db']->connection('sqlite')->listen(function ($query) use (&$sql): void {
            $sql[] = strtolower($query->sql);
        });

        $this->getJson('/api/v1/imagery/sequence-detail?sequence_uuid=seq-public')
            ->assertStatus(413)
            ->assertExactJson($this->payloadTooLarge());

        $this->assertTrue(collect($sql)->contains(fn (string $statement): bool => str_contains($statement, 'limit 4')));
    }

    public function test_disabled_rollback_restores_unbounded_legacy_and_v1_reads(): void
    {
        Config::set('mapilio.public_read_bounds.enabled', false);
        Config::set('mapilio.public_read_bounds.max_imagery_rows', 2);

        $this->getJson('/api/sequence-detail?sequence_uuid=seq-public')
            ->assertOk()
            ->assertJsonCount(4, 'data');
        $this->getJson('/api/v1/imagery/sequence-detail?sequence_uuid=seq-public')
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }

    public function test_pagination_boundaries_order_and_has_more_are_additive_on_v1(): void
    {
        $this->getJson('/api/v1/imagery/sequence-detail?sequence_uuid=seq-public&page=1&per_page=2')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    ['id' => 1, 'heading' => 90, 'filename' => 'first.jpg', 'uploaded_hash' => 'hash-a', 'fov' => '120.2', 'vfov' => '88.7', 'pitch' => '78.9', 'capture_time' => '2026-01-01 12:00:01', 'created_by_id' => 10, 'resolution' => '4160x2336'],
                    ['id' => 2, 'heading' => 94, 'filename' => 'second.jpg', 'uploaded_hash' => 'hash-a', 'fov' => '120.2', 'vfov' => '88.7', 'pitch' => '78.9', 'capture_time' => '2026-01-01 12:00:02', 'created_by_id' => 10, 'resolution' => '4160x2336'],
                ],
                'pagination' => ['current_page' => 1, 'per_page' => 2, 'has_more' => true],
            ]);

        $this->getJson('/api/v1/imagery/sequence-detail?sequence_uuid=seq-public&page=2&per_page=2')
            ->assertOk()
            ->assertJsonPath('data.0.id', 3)
            ->assertJsonPath('data.1.id', 4)
            ->assertJsonPath('pagination.has_more', false);
    }

    public function test_sequence_final_permitted_page_does_not_see_rows_beyond_divisible_ceiling(): void
    {
        Config::set('mapilio.public_read_bounds.max_imagery_rows', 2);
        $sql = [];
        $this->app['db']->connection('sqlite')->listen(function ($query) use (&$sql): void {
            $sql[] = strtolower($query->sql);
        });

        $this->getJson('/api/v1/imagery/sequence-detail?sequence_uuid=seq-public&page=1&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', 1)
            ->assertJsonPath('data.1.id', 2)
            ->assertJsonPath('pagination.has_more', false);

        $this->assertBoundedLimit($sql, 'default_mapilio_imagery', 2);

        $this->assertQueryBudget(
            fn () => $this->getJson('/api/v1/imagery/sequence-detail?sequence_uuid=seq-public&page=2&per_page=2')
                ->assertOk()
                ->assertExactJson([
                    'data' => null,
                    'pagination' => ['current_page' => 2, 'per_page' => 2, 'has_more' => false],
                ]),
            0,
            [['connection' => 'sqlite', 'tables' => ['default_mapilio_imagery']]],
        );
    }

    public function test_embed_final_permitted_page_does_not_see_rows_beyond_divisible_ceiling(): void
    {
        Config::set('mapilio.public_read_bounds.max_imagery_rows', 2);
        $sql = [];
        $this->app['db']->connection('sqlite')->listen(function ($query) use (&$sql): void {
            $sql[] = strtolower($query->sql);
        });

        $this->getJson('/api/v1/imagery/embed/seq-public?page=1&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.entries')
            ->assertJsonPath('data.entries.0.id', 1)
            ->assertJsonPath('data.entries.1.id', 2)
            ->assertJsonPath('pagination.has_more', false);

        $this->assertBoundedLimit($sql, 'default_mapilio_imagery', 2);
        $sql = [];

        $this->getJson('/api/v1/imagery/embed/seq-public?page=2&per_page=2')
            ->assertOk()
            ->assertJsonCount(0, 'data.entries')
            ->assertJsonPath('pagination.has_more', false);

        $this->assertFalse(collect($sql)->contains(
            fn (string $statement): bool => str_contains($statement, 'default_mapilio_imagery')
                && str_contains($statement, ' offset '),
        ));
    }

    public function test_roads_final_permitted_page_does_not_see_rows_beyond_divisible_ceiling(): void
    {
        Config::set('mapilio.public_read_bounds.max_road_rows', 2);
        $sql = [];
        $this->app['db']->connection('sqlite')->listen(function ($query) use (&$sql): void {
            $sql[] = strtolower($query->sql);
        });

        $this->getJson('/api/v1/geo/uploaded-roads-group?group_key=group-public&page=1&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.linefeature', 'road-1')
            ->assertJsonPath('data.1.linefeature', 'road-2')
            ->assertJsonPath('pagination.has_more', false);

        $this->assertBoundedLimit($sql, 'default_mapilio_road', 2);

        $this->assertQueryBudget(
            fn () => $this->getJson('/api/v1/geo/uploaded-roads-group?group_key=group-public&page=2&per_page=2')
                ->assertOk()
                ->assertExactJson([
                    'data' => null,
                    'pagination' => ['current_page' => 2, 'per_page' => 2, 'has_more' => false],
                ]),
            0,
            [['connection' => 'sqlite', 'tables' => ['default_mapilio_road']]],
        );
    }

    public function test_explicit_pagination_stays_bounded_when_full_read_guard_is_disabled(): void
    {
        Config::set('mapilio.public_read_bounds.enabled', false);
        Config::set('mapilio.public_read_bounds.max_imagery_rows', 2);
        Config::set('mapilio.public_read_bounds.max_road_rows', 2);
        $sql = [];
        $this->app['db']->connection('sqlite')->listen(function ($query) use (&$sql): void {
            $sql[] = strtolower($query->sql);
        });

        $this->getJson('/api/v1/imagery/sequence-detail?sequence_uuid=seq-public&page=1&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.has_more', false);
        $this->getJson('/api/v1/imagery/embed/seq-public?page=1&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.entries')
            ->assertJsonPath('pagination.has_more', false);
        $this->getJson('/api/v1/geo/uploaded-roads-group?group_key=group-public&page=1&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.has_more', false);

        $this->assertSame(2, collect($sql)->filter(
            fn (string $statement): bool => str_contains($statement, 'default_mapilio_imagery')
                && str_contains($statement, 'limit 2'),
        )->count());
        $this->assertBoundedLimit($sql, 'default_mapilio_road', 2);
    }

    public function test_pagination_is_bounded_past_the_ceiling_without_a_data_query(): void
    {
        $this->assertQueryBudget(
            fn () => $this->getJson('/api/v1/imagery/sequence-detail?sequence_uuid=seq-public&page=999&per_page=1000')
                ->assertOk()
                ->assertExactJson([
                    'data' => null,
                    'pagination' => ['current_page' => 999, 'per_page' => 1000, 'has_more' => false],
                ]),
            0,
            [['connection' => 'sqlite', 'tables' => ['default_mapilio_imagery']]],
        );
    }

    public function test_invalid_and_overlarge_pagination_values_have_exact_422_contract(): void
    {
        foreach (['page=0', 'page=01', 'page=1.0', 'page[]=1', 'page=1&page=2', 'per_page=1001'] as $parameters) {
            $this->getJson('/api/v1/imagery/sequence-detail?sequence_uuid=seq-public&'.$parameters)
                ->assertStatus(422)
                ->assertExactJson([
                    'success' => false,
                    'message' => ["'page' and 'per_page' must be positive integers within the supported range."],
                    'error_code' => 422,
                ]);
        }
    }

    public function test_embed_and_roads_pages_use_their_own_order_and_wrapper(): void
    {
        $this->getJson('/api/v1/imagery/embed/seq-public?page=1&per_page=2')
            ->assertOk()
            ->assertJsonPath('data.info.sequence_uuid', 'seq-public')
            ->assertJsonPath('data.entries.0.id', 1)
            ->assertJsonPath('data.entries.1.id', 2)
            ->assertJsonPath('pagination.has_more', true);

        $this->getJson('/api/v1/geo/uploaded-roads-group?group_key=group-public&page=2&per_page=2')
            ->assertOk()
            ->assertExactJson([
                'data' => [['sequence_uuid' => 'seq-public', 'linefeature' => 'road-3']],
                'pagination' => ['current_page' => 2, 'per_page' => 2, 'has_more' => false],
            ]);
    }

    public function test_byte_overflow_is_413_and_unknown_embed_remains_404(): void
    {
        Config::set('mapilio.public_read_bounds.max_item_bytes', 10);

        $this->getJson('/api/sequence-detail?sequence_uuid=seq-public')
            ->assertStatus(413)
            ->assertExactJson($this->payloadTooLarge());

        $this->getJson('/api/embed/not-found')
            ->assertStatus(404)
            ->assertExactJson([
                'success' => false,
                'message' => ['Not Found'],
                'error_code' => 404,
            ]);
    }

    /** @return array<string, mixed> */
    private function image(int $id, string $captureTime, string $filename): array
    {
        return [
            'id' => $id,
            'photo_uuid' => 'photo-'.$id,
            'created_by_id' => 10,
            'capture_time' => $captureTime,
            'filename' => $filename,
            'latitude' => '35.'.$id,
            'longitude' => '-78.'.$id,
            'uploaded_hash' => 'hash-a',
            'sequence_uuid' => 'seq-public',
            'heading' => [1 => 90, 2 => 94, 3 => 91, 4 => 92][$id],
            'resolution' => '4160x2336',
            'fov' => '120.2',
            'vfov' => '88.7',
            'pitch' => '78.9',
            'anomaly' => false,
            'deleted_at' => null,
        ];
    }

    /** @param list<string> $sql */
    private function assertBoundedLimit(array $sql, string $table, int $limit): void
    {
        $matching = collect($sql)->filter(
            fn (string $statement): bool => str_contains($statement, $table),
        );

        $this->assertTrue($matching->contains(
            fn (string $statement): bool => str_contains($statement, 'limit '.$limit),
        ));
        $this->assertFalse($matching->contains(
            fn (string $statement): bool => str_contains($statement, 'limit '.($limit + 1)),
        ));
    }

    /** @return array{success: false, message: array{string}, error_code: 413} */
    private function payloadTooLarge(): array
    {
        return [
            'success' => false,
            'message' => ['Payload Too Large'],
            'error_code' => 413,
        ];
    }
}

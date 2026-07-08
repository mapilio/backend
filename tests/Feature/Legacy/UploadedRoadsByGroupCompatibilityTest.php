<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UploadedRoadsByGroupCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('default_mapilio_sequence_detail', function ($table): void {
            $table->id();
            $table->string('sequence_uuid');
            $table->string('group_key')->nullable();
        });

        Schema::create('default_mapilio_road', function ($table): void {
            $table->id();
            $table->string('sequence_uuid');
            $table->text('linefeature')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::getConnection()->table('default_mapilio_sequence_detail')->insert([
            ['sequence_uuid' => 'seq-road-a', 'group_key' => 'group-a'],
            ['sequence_uuid' => 'seq-road-b', 'group_key' => 'group-a'],
            ['sequence_uuid' => 'seq-road-c', 'group_key' => 'group-b'],
        ]);

        Schema::getConnection()->table('default_mapilio_road')->insert([
            [
                'sequence_uuid' => 'seq-road-a',
                'linefeature' => '{"type":"LineString","coordinates":[[1,2],[3,4]]}',
                'deleted_at' => null,
            ],
            [
                'sequence_uuid' => 'seq-road-b',
                'linefeature' => '{"type":"LineString","coordinates":[[5,6],[7,8]]}',
                'deleted_at' => null,
            ],
            [
                'sequence_uuid' => 'seq-road-c',
                'linefeature' => '{"type":"LineString","coordinates":[[9,10],[11,12]]}',
                'deleted_at' => null,
            ],
        ]);
    }

    public function test_legacy_uploaded_roads_group_path_preserves_response_shape(): void
    {
        $this->getJson('/api/get-uploaded-roads-group?group_key=group-a')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'sequence_uuid' => 'seq-road-a',
                        'linefeature' => '{"type":"LineString","coordinates":[[1,2],[3,4]]}',
                    ],
                    [
                        'sequence_uuid' => 'seq-road-b',
                        'linefeature' => '{"type":"LineString","coordinates":[[5,6],[7,8]]}',
                    ],
                ],
            ]);
    }

    public function test_versioned_uploaded_roads_group_alias_returns_same_contract(): void
    {
        $legacy = $this->getJson('/api/get-uploaded-roads-group?group_key=group-a')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/geo/uploaded-roads-group?group_key=group-a')
            ->assertOk()
            ->json();

        $this->assertSame($legacy, $versioned);
    }

    public function test_uploaded_roads_group_preserves_missing_parameter_error_shape(): void
    {
        $this->getJson('/api/get-uploaded-roads-group')
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'group_key' is required!"],
                'error_code' => 400,
            ]);
    }

    public function test_uploaded_roads_group_preserves_empty_result_shape(): void
    {
        $this->getJson('/api/get-uploaded-roads-group?group_key=not-found')
            ->assertOk()
            ->assertExactJson([
                'data' => null,
            ]);
    }
}

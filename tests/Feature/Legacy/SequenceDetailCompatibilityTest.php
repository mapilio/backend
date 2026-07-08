<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SequenceDetailCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('default_mapilio_imagery', function ($table): void {
            $table->integer('id')->primary();
            $table->integer('heading')->nullable();
            $table->string('filename')->nullable();
            $table->string('uploaded_hash')->nullable();
            $table->string('fov')->nullable();
            $table->string('vfov')->nullable();
            $table->string('pitch')->nullable();
            $table->timestamp('capture_time')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->string('resolution')->nullable();
            $table->string('sequence_uuid');
            $table->boolean('anomaly')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::getConnection()->table('default_mapilio_imagery')->insert([
            [
                'id' => 2,
                'heading' => 94,
                'filename' => 'second.jpg',
                'uploaded_hash' => 'hash-a',
                'fov' => '120.2',
                'vfov' => '88.7',
                'pitch' => '79.1',
                'capture_time' => '2026-01-01 12:00:02',
                'created_by_id' => 10,
                'resolution' => '4160x2336',
                'sequence_uuid' => 'seq-public',
                'anomaly' => false,
                'deleted_at' => null,
            ],
            [
                'id' => 1,
                'heading' => 90,
                'filename' => 'first.jpg',
                'uploaded_hash' => 'hash-a',
                'fov' => '120.2',
                'vfov' => '88.7',
                'pitch' => '78.9',
                'capture_time' => '2026-01-01 12:00:01',
                'created_by_id' => 10,
                'resolution' => '4160x2336',
                'sequence_uuid' => 'seq-public',
                'anomaly' => false,
                'deleted_at' => null,
            ],
            [
                'id' => 3,
                'heading' => 91,
                'filename' => 'anomaly.jpg',
                'uploaded_hash' => 'hash-a',
                'fov' => '120.2',
                'vfov' => '88.7',
                'pitch' => '78.9',
                'capture_time' => '2026-01-01 12:00:03',
                'created_by_id' => 10,
                'resolution' => '4160x2336',
                'sequence_uuid' => 'seq-public',
                'anomaly' => true,
                'deleted_at' => null,
            ],
        ]);
    }

    public function test_legacy_sequence_detail_path_preserves_response_shape_and_order(): void
    {
        $this->getJson('/api/sequence-detail?sequence_uuid=seq-public')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'id' => 1,
                        'heading' => 90,
                        'filename' => 'first.jpg',
                        'uploaded_hash' => 'hash-a',
                        'fov' => '120.2',
                        'vfov' => '88.7',
                        'pitch' => '78.9',
                        'capture_time' => '2026-01-01 12:00:01',
                        'created_by_id' => 10,
                        'resolution' => '4160x2336',
                    ],
                    [
                        'id' => 2,
                        'heading' => 94,
                        'filename' => 'second.jpg',
                        'uploaded_hash' => 'hash-a',
                        'fov' => '120.2',
                        'vfov' => '88.7',
                        'pitch' => '79.1',
                        'capture_time' => '2026-01-01 12:00:02',
                        'created_by_id' => 10,
                        'resolution' => '4160x2336',
                    ],
                ],
            ]);
    }

    public function test_versioned_sequence_detail_alias_returns_same_contract(): void
    {
        $legacy = $this->getJson('/api/sequence-detail?sequence_uuid=seq-public')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/imagery/sequence-detail?sequence_uuid=seq-public')
            ->assertOk()
            ->json();

        $this->assertSame($legacy, $versioned);
    }

    public function test_sequence_detail_preserves_missing_parameter_error_shape(): void
    {
        $this->getJson('/api/sequence-detail')
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'sequence_uuid' is required!"],
                'error_code' => 400,
            ]);
    }

    public function test_sequence_detail_preserves_empty_result_shape(): void
    {
        $this->getJson('/api/sequence-detail?sequence_uuid=not-found')
            ->assertOk()
            ->assertExactJson([
                'data' => null,
            ]);
    }
}

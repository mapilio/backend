<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmbedImageCompatibilityTest extends TestCase
{
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
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_mapilio_sequence_detail', function ($table): void {
            $table->id();
            $table->string('sequence_uuid');
            $table->string('start_address')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::getConnection()->table('default_mapilio_sequence_detail')->insert([
            'sequence_uuid' => 'seq-embed',
            'start_address' => 'North Road',
            'deleted_at' => null,
        ]);

        Schema::getConnection()->table('default_mapilio_imagery')->insert([
            [
                'id' => 2,
                'photo_uuid' => 'photo-two',
                'created_by_id' => 10,
                'capture_time' => '2026-01-01 12:00:02',
                'filename' => 'second.jpg',
                'latitude' => '35.2',
                'longitude' => '-78.2',
                'uploaded_hash' => 'hash-a',
                'sequence_uuid' => 'seq-embed',
                'heading' => 94,
                'resolution' => '4160x2336',
                'fov' => '120.2',
                'vfov' => '88.7',
                'deleted_at' => null,
            ],
            [
                'id' => 1,
                'photo_uuid' => 'photo-one',
                'created_by_id' => 10,
                'capture_time' => '2026-01-01 12:00:01',
                'filename' => 'first.jpg',
                'latitude' => '35.1',
                'longitude' => '-78.1',
                'uploaded_hash' => 'hash-a',
                'sequence_uuid' => 'seq-embed',
                'heading' => 90,
                'resolution' => '4160x2336',
                'fov' => '120.2',
                'vfov' => '88.7',
                'deleted_at' => null,
            ],
        ]);
    }

    public function test_legacy_embed_path_preserves_response_shape_and_id_order(): void
    {
        $this->getJson('/api/embed/seq-embed')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'info' => [
                        'sequence_uuid' => 'seq-embed',
                        'start_address' => 'North Road',
                    ],
                    'entries' => [
                        [
                            'photo_uuid' => 'photo-one',
                            'created_by_id' => 10,
                            'id' => 1,
                            'capture_time' => '2026-01-01 12:00:01',
                            'filename' => 'first.jpg',
                            'latitude' => '35.1',
                            'longitude' => '-78.1',
                            'uploaded_hash' => 'hash-a',
                            'sequence_uuid' => 'seq-embed',
                            'heading' => 90,
                            'resolution' => '4160x2336',
                            'fov' => '120.2',
                            'vfov' => '88.7',
                        ],
                        [
                            'photo_uuid' => 'photo-two',
                            'created_by_id' => 10,
                            'id' => 2,
                            'capture_time' => '2026-01-01 12:00:02',
                            'filename' => 'second.jpg',
                            'latitude' => '35.2',
                            'longitude' => '-78.2',
                            'uploaded_hash' => 'hash-a',
                            'sequence_uuid' => 'seq-embed',
                            'heading' => 94,
                            'resolution' => '4160x2336',
                            'fov' => '120.2',
                            'vfov' => '88.7',
                        ],
                    ],
                ],
            ]);
    }

    public function test_versioned_embed_alias_returns_same_contract(): void
    {
        $legacy = $this->getJson('/api/embed/seq-embed')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/imagery/embed/seq-embed')
            ->assertOk()
            ->json();

        $this->assertSame($legacy, $versioned);
    }

    public function test_embed_preserves_unknown_sequence_404_shape(): void
    {
        $this->getJson('/api/embed/not-found')
            ->assertStatus(404)
            ->assertExactJson([
                'success' => false,
                'message' => ['Not Found'],
                'error_code' => 404,
            ]);
    }
}

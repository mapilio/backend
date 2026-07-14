<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Schema;
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
            ->assertJsonPath('pagination.total', 3);
    }

    public function test_legacy_user_uploads_detail_v2_orders_by_imagery_id(): void
    {
        $this->getJson('/api/user-uploads-detail-v2?options[parameters][user_id]=10&options[parameters][group_key]=group-new&options[limit]=10&page=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', 1)
            ->assertJsonPath('data.1.id', 2)
            ->assertJsonPath('data.2.id', 4);
    }

    public function test_legacy_user_uploads_detail_v2_empty_results_preserve_data_null_without_pagination(): void
    {
        $this->getJson('/api/user-uploads-detail-v2?options[parameters][user_id]=99&options[parameters][group_key]=missing&options[limit]=10&page=1')
            ->assertOk()
            ->assertExactJson([
                'data' => null,
            ]);
    }

    public function test_legacy_user_uploads_detail_v2_missing_parameters_preserve_error_shape(): void
    {
        $this->getJson('/api/user-uploads-detail-v2')
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'user_id' is required!"],
                'error_code' => 400,
            ]);

        $this->getJson('/api/user-uploads-detail-v2?options[parameters][user_id]=10')
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => ["'group_key' is required!"],
                'error_code' => 400,
            ]);
    }

    public function test_versioned_user_upload_details_alias_returns_same_data_contract(): void
    {
        $legacy = $this->getJson('/api/user-uploads-detail-v2?options[parameters][user_id]=10&options[parameters][group_key]=group-new&options[limit]=2&page=1')
            ->assertOk()
            ->json();

        $versioned = $this->getJson('/api/v1/imagery/user-upload-details?options[parameters][user_id]=10&options[parameters][group_key]=group-new&options[limit]=2&page=1')
            ->assertOk()
            ->json();

        $this->assertSame($legacy['data'], $versioned['data']);
        $this->assertSame($legacy['pagination']['total'], $versioned['pagination']['total']);
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
        ]);
    }
}

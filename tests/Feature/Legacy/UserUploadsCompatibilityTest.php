<?php

namespace Tests\Feature\Legacy;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserUploadsCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        $this->seedData();
    }

    public function test_legacy_user_uploads_v2_preserves_mobile_feed_contract(): void
    {
        $this->getJson('/api/user-uploads-v2?options[parameters][user_id]=10&options[limit]=2&page=1')
            ->assertOk()
            ->assertJsonPath('data.0.total', 3)
            ->assertJsonPath('data.0.uploaded_hash', 'hash-new-a')
            ->assertJsonPath('data.0.capture_time', '2026-05-08 17:09:57')
            ->assertJsonPath('data.0.cover_photo', 'new-a.jpeg')
            ->assertJsonPath('data.0.group_key', 'group-new')
            ->assertJsonPath('data.0.start_address', 'Grant Street')
            ->assertJsonPath('data.0.last_status', 'completed')
            ->assertJsonPath('data.1.total', 1)
            ->assertJsonPath('data.1.group_key', 'group-old')
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.last_page', 1)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_user_uploads_v2_out_of_range_page_has_null_bounds_and_correct_total(): void
    {
        $this->seedAdditionalGroups(8);

        $this->getJson('/api/user-uploads-v2?options[parameters][user_id]=10&options[limit]=10&page=2')
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('pagination.total', 10)
            ->assertJsonPath('pagination.last_page', 1)
            ->assertJsonPath('pagination.from', null)
            ->assertJsonPath('pagination.to', null);
    }

    public function test_user_uploads_v2_empty_results_preserve_data_null(): void
    {
        $this->getJson('/api/user-uploads-v2?options[parameters][user_id]=99&options[limit]=10&page=1')
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('pagination.total', 0);
    }

    public function test_user_uploads_v2_missing_user_id_preserves_validation_error(): void
    {
        $this->getJson('/api/user-uploads-v2')
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => [
                    'user_id' => ['The user_id field is required.'],
                ],
            ]);
    }

    public function test_versioned_user_uploads_alias_matches_legacy_contract(): void
    {
        $legacy = $this->getJson('/api/user-uploads-v2?options[parameters][user_id]=10&options[limit]=2&page=1')
            ->assertOk()
            ->json();

        $this->getJson('/api/v1/imagery/user-uploads?options[parameters][user_id]=10&options[limit]=2&page=1')
            ->assertOk()
            ->assertExactJson($legacy);
    }

    public function test_versioned_user_uploads_alias_preserves_scalar_query_coercion(): void
    {
        foreach ([
            ['limit' => 'not-numeric', 'page' => '0'],
            ['limit' => '0', 'page' => 'not-numeric'],
        ] as $query) {
            $this->getJson('/api/v1/imagery/user-uploads?options[parameters][user_id]=10.9&options[limit]='.$query['limit'].'&page='.$query['page'])
                ->assertOk()
                ->assertJsonPath('data.0.group_key', 'group-new')
                ->assertJsonPath('pagination.per_page', 1)
                ->assertJsonPath('pagination.current_page', 1);
        }
    }

    public function test_versioned_user_uploads_alias_preserves_null_data_pages(): void
    {
        $this->getJson('/api/v1/imagery/user-uploads?options[parameters][user_id]=99&options[limit]=10&page=1')
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('pagination.path', '/api/user-uploads-v2')
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonPath('pagination.from', null)
            ->assertJsonPath('pagination.to', null);

        $this->seedAdditionalGroups(8);

        $this->getJson('/api/v1/imagery/user-uploads?options[parameters][user_id]=10&options[limit]=10&page=2')
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('pagination.path', '/api/user-uploads-v2')
            ->assertJsonPath('pagination.total', 10)
            ->assertJsonPath('pagination.last_page', 1)
            ->assertJsonPath('pagination.from', null)
            ->assertJsonPath('pagination.to', null);
    }

    public function test_versioned_user_uploads_alias_preserves_validation_error_shape(): void
    {
        $this->getJson('/api/v1/imagery/user-uploads')
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => [
                    'user_id' => ['The user_id field is required.'],
                ],
            ]);
    }

    private function createTables(): void
    {
        Schema::create('default_mapilio_imagery', function ($table): void {
            $table->id();
            $table->integer('created_by_id')->nullable();
            $table->string('sequence_uuid')->nullable();
            $table->string('uploaded_hash')->nullable();
            $table->string('filename')->nullable();
            $table->timestamp('capture_time')->nullable();
            $table->boolean('anomaly')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('default_mapilio_sequence_detail', function ($table): void {
            $table->id();
            $table->integer('created_by_id')->nullable();
            $table->string('sequence_uuid')->nullable();
            $table->string('group_key')->nullable();
            $table->string('start_address')->nullable();
            $table->string('last_status')->nullable();
            $table->boolean('anomaly')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });
    }

    private function seedData(): void
    {
        Schema::getConnection()->table('default_mapilio_imagery')->insert([
            [
                'id' => 1,
                'created_by_id' => 10,
                'sequence_uuid' => 'sequence-new-a',
                'uploaded_hash' => 'hash-new-a',
                'filename' => 'new-a.jpeg',
                'capture_time' => '2026-05-08 17:09:57',
                'anomaly' => false,
                'deleted_at' => null,
            ],
            [
                'id' => 2,
                'created_by_id' => 10,
                'sequence_uuid' => 'sequence-new-b',
                'uploaded_hash' => 'hash-new-b',
                'filename' => 'new-b.jpeg',
                'capture_time' => '2026-05-08 17:09:55',
                'anomaly' => false,
                'deleted_at' => null,
            ],
            [
                'id' => 3,
                'created_by_id' => 10,
                'sequence_uuid' => 'sequence-old',
                'uploaded_hash' => 'hash-old',
                'filename' => 'old.jpeg',
                'capture_time' => '2026-05-07 11:00:00',
                'anomaly' => false,
                'deleted_at' => null,
            ],
            [
                'id' => 4,
                'created_by_id' => 10,
                'sequence_uuid' => 'sequence-deleted',
                'uploaded_hash' => 'hash-deleted',
                'filename' => 'deleted.jpeg',
                'capture_time' => '2026-05-09 11:00:00',
                'anomaly' => false,
                'deleted_at' => '2026-05-10 00:00:00',
            ],
            [
                'id' => 5,
                'created_by_id' => 10,
                'sequence_uuid' => 'sequence-new-c',
                'uploaded_hash' => 'hash-new-c',
                'filename' => 'new-c.jpeg',
                'capture_time' => '2026-05-08 17:09:57',
                'anomaly' => false,
                'deleted_at' => null,
            ],
        ]);

        Schema::getConnection()->table('default_mapilio_sequence_detail')->insert([
            [
                'id' => 10,
                'created_by_id' => 10,
                'sequence_uuid' => 'sequence-new-a',
                'group_key' => 'group-new',
                'start_address' => 'Grant Street',
                'last_status' => 'uploaded',
                'anomaly' => false,
                'deleted_at' => null,
            ],
            [
                'id' => 11,
                'created_by_id' => 10,
                'sequence_uuid' => 'sequence-new-b',
                'group_key' => 'group-new',
                'start_address' => null,
                'last_status' => 'completed',
                'anomaly' => false,
                'deleted_at' => null,
            ],
            [
                'id' => 12,
                'created_by_id' => 10,
                'sequence_uuid' => 'sequence-old',
                'group_key' => 'group-old',
                'start_address' => 'Old Road',
                'last_status' => 'uploaded',
                'anomaly' => false,
                'deleted_at' => null,
            ],
            [
                'id' => 13,
                'created_by_id' => 10,
                'sequence_uuid' => 'sequence-new-c',
                'group_key' => 'group-new',
                'start_address' => 'Later Street',
                'last_status' => 'completed',
                'anomaly' => false,
                'deleted_at' => null,
            ],
        ]);
    }

    private function seedAdditionalGroups(int $count): void
    {
        $imagery = [];
        $details = [];

        for ($group = 1; $group <= $count; $group++) {
            $number = str_pad((string) $group, 2, '0', STR_PAD_LEFT);
            $imagery[] = [
                'id' => 100 + $group,
                'created_by_id' => 10,
                'sequence_uuid' => 'sequence-extra-'.$number,
                'uploaded_hash' => 'hash-extra-'.$number,
                'filename' => 'extra-'.$number.'.jpeg',
                'capture_time' => '2026-04-'.str_pad((string) (20 - $group), 2, '0', STR_PAD_LEFT).' 12:00:00',
                'anomaly' => false,
                'deleted_at' => null,
            ];
            $details[] = [
                'id' => 200 + $group,
                'created_by_id' => 10,
                'sequence_uuid' => 'sequence-extra-'.$number,
                'group_key' => 'group-extra-'.$number,
                'start_address' => 'Extra Road '.$number,
                'last_status' => 'uploaded',
                'anomaly' => false,
                'deleted_at' => null,
            ];
        }

        Schema::getConnection()->table('default_mapilio_imagery')->insert($imagery);
        Schema::getConnection()->table('default_mapilio_sequence_detail')->insert($details);
    }
}

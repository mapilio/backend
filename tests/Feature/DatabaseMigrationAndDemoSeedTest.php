<?php

namespace Tests\Feature;

use Database\Seeders\LocalDemoSeeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class DatabaseMigrationAndDemoSeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.env', 'testing');
        Config::set('mapilio.local_demo_seeding.enabled', true);
    }

    public function test_sqlite_migrations_apply_and_roll_back_as_one_clean_batch(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();

        foreach ($this->modernTables() as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected migrated table {$table}.");
        }

        $view = DB::selectOne(
            "select name from sqlite_master where type = 'view' and name = ?",
            ['mapilio_ai_features_v1'],
        );
        $this->assertNotNull($view);

        $foreignKeys = DB::select("pragma foreign_key_list('ai_detection_features')");
        $this->assertTrue(collect($foreignKeys)->contains(
            fn (object $foreignKey): bool => $foreignKey->table === 'ai_prediction_callback_receipts'
                && $foreignKey->from === 'callback_receipt_id',
        ));

        $this->artisan('migrate:rollback', ['--force' => true])->assertSuccessful();

        foreach ($this->modernTables() as $table) {
            $this->assertFalse(Schema::hasTable($table), "Expected rolled-back table {$table} to be absent.");
        }

        $view = DB::selectOne(
            "select name from sqlite_master where type = 'view' and name = ?",
            ['mapilio_ai_features_v1'],
        );
        $this->assertNull($view);
    }

    public function test_local_demo_seed_is_encrypted_idempotent_and_served_by_the_versioned_api(): void
    {
        $this->artisan('migrate:fresh', ['--seed' => true, '--force' => true])->assertSuccessful();
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertSame(1, DB::table('ai_prediction_callback_receipts')->count());
        $this->assertSame(1, DB::table('ai_detection_features')->count());
        $this->assertSame(0, DB::table('users')->count());

        $receipt = DB::table('ai_prediction_callback_receipts')
            ->where('id', LocalDemoSeeder::RECEIPT_ID)
            ->first();

        $this->assertNotNull($receipt);
        $this->assertStringNotContainsString('demo-response-0001', $receipt->encrypted_payload);
        $this->assertJson(Crypt::decryptString($receipt->encrypted_payload));

        $this->getJson('/api/v1/geo/ai-features/'.LocalDemoSeeder::FEATURE_ID)
            ->assertOk()
            ->assertJsonMissingPath('data.properties.response_id')
            ->assertJsonMissingPath('data.properties.callback_receipt_id')
            ->assertJsonPath('data.id', LocalDemoSeeder::FEATURE_ID)
            ->assertJsonPath('data.geometry.type', 'Point')
            ->assertJsonPath('data.geometry.coordinates', [29.0255, 40.9911])
            ->assertJsonPath('data.properties.class_code', 'demo-stop-sign')
            ->assertJsonPath('data.properties.attributes.demo', true)
            ->assertJsonPath('data.matches', []);
    }

    public function test_demo_seeder_does_not_write_when_the_explicit_flag_is_disabled(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        Config::set('mapilio.local_demo_seeding.enabled', false);

        try {
            (new LocalDemoSeeder)->run();
            $this->fail('Expected disabled demo seeding to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Local demo seeding must be explicitly enabled.', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('ai_prediction_callback_receipts')->count());
        $this->assertSame(0, DB::table('ai_detection_features')->count());
    }

    /**
     * @return list<string>
     */
    private function modernTables(): array
    {
        return [
            'users',
            'password_reset_tokens',
            'sessions',
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'failed_jobs',
            'ai_prediction_callback_receipts',
            'ai_prediction_callback_nonces',
            'ai_detection_features',
            'ai_detection_observations',
            'ai_detection_matches',
            'ai_prediction_status_projections',
            'geospatial_publications',
            'geospatial_publication_checks',
        ];
    }
}

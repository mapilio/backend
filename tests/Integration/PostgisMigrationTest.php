<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PostgisMigrationTest extends TestCase
{
    public function test_postgresql_14_postgis_schema_applies_operates_rolls_back_and_reapplies(): void
    {
        $this->assertDisposableConnection();

        $serverVersion = (int) DB::scalar('SHOW server_version_num');
        $this->assertGreaterThanOrEqual(140_000, $serverVersion);
        $this->assertLessThan(150_000, $serverVersion);
        $this->assertNotEmpty(DB::scalar("SELECT extversion FROM pg_extension WHERE extname = 'postgis'"));

        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();

        $column = DB::selectOne(<<<'SQL'
            SELECT data_type, udt_name, is_generated, generation_expression
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = 'ai_detection_features'
              AND column_name = 'geom'
            SQL);

        $this->assertNotNull($column);
        $this->assertSame('USER-DEFINED', $column->data_type);
        $this->assertSame('geometry', $column->udt_name);
        $this->assertSame('ALWAYS', $column->is_generated);
        $this->assertStringContainsString('st_setsrid', strtolower($column->generation_expression));
        $this->assertStringContainsString('st_makepoint', strtolower($column->generation_expression));

        $indexDefinition = DB::scalar(<<<'SQL'
            SELECT indexdef
            FROM pg_indexes
            WHERE schemaname = 'public'
              AND tablename = 'ai_detection_features'
              AND indexname = 'ai_detection_features_geom_gist'
            SQL);

        $this->assertIsString($indexDefinition);
        $this->assertStringContainsString('USING gist (geom)', $indexDefinition);
        $this->assertSame('v', DB::scalar(<<<'SQL'
            SELECT relkind
            FROM pg_class
            JOIN pg_namespace ON pg_namespace.oid = pg_class.relnamespace
            WHERE pg_namespace.nspname = 'public'
              AND pg_class.relname = 'mapilio_ai_features_v1'
            SQL));

        $this->insertCanonicalFeature();

        $spatial = DB::selectOne(<<<'SQL'
            SELECT
                ST_SRID(geom) AS srid,
                ST_GeometryType(geom) AS geometry_type,
                ST_X(geom) AS longitude,
                ST_Y(geom) AS latitude,
                ST_IsValid(geom) AS is_valid
            FROM ai_detection_features
            WHERE id = 910000001
            SQL);

        $this->assertNotNull($spatial);
        $this->assertSame(4326, (int) $spatial->srid);
        $this->assertSame('ST_Point', $spatial->geometry_type);
        $this->assertEqualsWithDelta(29.0255, (float) $spatial->longitude, 0.000_000_1);
        $this->assertEqualsWithDelta(40.9911, (float) $spatial->latitude, 0.000_000_1);
        $this->assertTrue((bool) $spatial->is_valid);
        $this->assertSame(
            1,
            DB::table('mapilio_ai_features_v1')->where('id', 910_000_001)->count(),
        );

        $this->artisan('migrate:rollback', ['--force' => true])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('ai_detection_features'));
        $this->assertNull(DB::scalar("SELECT to_regclass('public.mapilio_ai_features_v1')"));

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('ai_detection_features'));
        $this->assertSame(
            'mapilio_ai_features_v1',
            DB::scalar("SELECT to_regclass('public.mapilio_ai_features_v1')::text"),
        );
    }

    private function assertDisposableConnection(): void
    {
        $this->assertSame('true', getenv('MAPILIO_DISPOSABLE_DB_CONFIRMED'));
        $this->assertSame('testing', app()->environment());
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertSame('', config('database.connections.pgsql.url'));
        $this->assertSame('127.0.0.1', config('database.connections.pgsql.host'));
        $this->assertSame('5432', config('database.connections.pgsql.port'));
        $this->assertSame('mapilio_ci', DB::scalar('SELECT current_database()'));
        $this->assertSame('mapilio_ci', DB::scalar('SELECT current_user'));
        $this->assertSame('127.0.0.1', DB::scalar('SELECT host(inet_server_addr())'));
    }

    private function insertCanonicalFeature(): void
    {
        $timestamp = '2026-01-01 00:00:00';

        DB::table('ai_prediction_callback_receipts')->insert([
            'id' => 910_000_001,
            'response_id' => 'postgis-integration-response',
            'response_status' => 'SUCCESS',
            'payload_hash' => hash('sha256', 'postgis-integration-payload'),
            'fingerprint' => hash('sha256', 'postgis-integration-fingerprint'),
            'encrypted_payload' => 'disposable-integration-fixture',
            'result_feature_count' => 1,
            'processing_status' => 'processed',
            'processing_error' => null,
            'received_at' => $timestamp,
            'validated_at' => $timestamp,
            'processed_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('ai_detection_features')->insert([
            'id' => 910_000_001,
            'callback_receipt_id' => 910_000_001,
            'response_id' => 'postgis-integration-response',
            'sequence_uuid' => 'postgis-integration-sequence',
            'created_by_id' => null,
            'organization_key' => null,
            'project_key' => null,
            'source_index' => 0,
            'class_code' => 'integration-point',
            'confidence' => 1,
            'longitude' => 29.0255,
            'latitude' => 40.9911,
            'geometry' => json_encode([
                'type' => 'Point',
                'coordinates' => [29.0255, 40.9911],
            ], JSON_THROW_ON_ERROR),
            'width' => 1,
            'height' => 1,
            'area' => 1,
            'verified' => true,
            'attributes' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }
}

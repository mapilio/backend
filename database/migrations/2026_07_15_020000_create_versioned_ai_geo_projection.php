<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geospatial_publications', function (Blueprint $table): void {
            $table->timestamp('prepared_at')->nullable()->after('attempts');
            $table->timestamp('reconciled_at')->nullable()->after('prepared_at');
        });

        Schema::create('geospatial_publication_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('geospatial_publication_id')
                ->constrained('geospatial_publications')
                ->cascadeOnDelete();
            $table->string('check_status', 32);
            $table->unsignedInteger('expected_feature_count');
            $table->unsignedInteger('actual_feature_count');
            $table->unsignedInteger('missing_view_feature_count')->default(0);
            $table->unsignedInteger('invalid_geometry_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['geospatial_publication_id', 'checked_at'], 'geo_publication_check_history');
        });

        if (DB::getDriverName() === 'pgsql') {
            $postgisInstalled = (bool) DB::scalar(
                "select exists(select 1 from pg_extension where extname = 'postgis')",
            );

            if (! $postgisInstalled) {
                throw new RuntimeException('PostGIS must be installed before the AI geo projection migration runs.');
            }

            DB::statement(<<<'SQL'
                ALTER TABLE ai_detection_features
                ADD COLUMN geom geometry(Point, 4326)
                GENERATED ALWAYS AS (ST_SetSRID(ST_MakePoint(longitude, latitude), 4326)) STORED
                SQL);
            DB::statement('CREATE INDEX ai_detection_features_geom_gist ON ai_detection_features USING GIST (geom)');
            DB::statement(<<<'SQL'
                CREATE VIEW mapilio_ai_features_v1 AS
                SELECT
                    id,
                    geom,
                    class_code,
                    sequence_uuid,
                    project_key,
                    organization_key,
                    created_by_id,
                    confidence,
                    width,
                    height,
                    area,
                    verified,
                    attributes,
                    created_at,
                    updated_at
                FROM ai_detection_features
                SQL);

            return;
        }

        DB::statement(<<<'SQL'
            CREATE VIEW mapilio_ai_features_v1 AS
            SELECT
                id,
                geometry AS geom,
                class_code,
                sequence_uuid,
                project_key,
                organization_key,
                created_by_id,
                confidence,
                width,
                height,
                area,
                verified,
                attributes,
                created_at,
                updated_at
            FROM ai_detection_features
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS mapilio_ai_features_v1');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS ai_detection_features_geom_gist');
            DB::statement('ALTER TABLE ai_detection_features DROP COLUMN IF EXISTS geom');
        }

        Schema::dropIfExists('geospatial_publication_checks');

        Schema::table('geospatial_publications', function (Blueprint $table): void {
            $table->dropColumn(['prepared_at', 'reconciled_at']);
        });
    }
};

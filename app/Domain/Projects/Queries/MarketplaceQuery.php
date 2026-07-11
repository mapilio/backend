<?php

namespace App\Domain\Projects\Queries;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

class MarketplaceQuery
{
    public function geojson(?float $lat = null, ?float $lon = null): string
    {
        $connection = DB::connection(config('mapilio.legacy_database_connection'));

        if ($connection->getDriverName() === 'pgsql') {
            return $this->postgresGeojson($connection, $lat, $lon);
        }

        return $this->portableGeojson($connection, $lat, $lon);
    }

    private function postgresGeojson(ConnectionInterface $connection, ?float $lat, ?float $lon): string
    {
        $distanceField = "'0' as DISTANCE_KM";
        $distanceOrder = '';
        $bindings = [];

        if ($lat !== null && $lon !== null) {
            $distanceField = 'ST_DISTANCE(SHAPE.POLYGON,ST_SETSRID(ST_MAKEPOINT(?,?),4326)) / 1000 AS DISTANCE_KM';
            $distanceOrder = 'ORDER BY DISTANCE_KM ASC';
            $bindings = [$lon, $lat];
        }

        $query = "SELECT ROW_TO_JSON(FC) AS GEOJSON
FROM
	(SELECT 'FeatureCollection' AS TYPE,
			ARRAY_TO_JSON(ARRAY_AGG(F)) AS FEATURES
		FROM
			(SELECT 'Feature' AS TYPE,
					JSON_BUILD_OBJECT(
						'marketplace_name',ENTRIES.MARKETPLACE_NAME,
						'marketplace_description',ENTRIES.MARKETPLACE_DESCRIPTION,
						'id',ENTRIES.ID,
						'project_key',ENTRIES.PROJECT_KEY,
						'organization_key',ENTRIES.PROJECT_ORGANIZATION_KEY,
						'created_at',ENTRIES.CREATED_AT,
						'owner',ENTRIES.ORGANIZATION_NAME,
						'project_camera_type',ENTRIES.PROJECT_CAMERA_TYPE,
						'distance_km',ENTRIES.DISTANCE_KM) AS PROPERTIES,
					ENTRIES.GEOMETRY
				FROM
					(SELECT PROJECT.MARKETPLACE_NAME AS MARKETPLACE_NAME,
							PROJECT.MARKETPLACE_DESCRIPTION AS MARKETPLACE_DESCRIPTION,
							PROJECT.ID AS ID,
							PROJECT.PROJECT_KEY AS PROJECT_KEY,
							PROJECT.PROJECT_ORGANIZATION_KEY AS PROJECT_ORGANIZATION_KEY,
							PROJECT.CREATED_AT AS CREATED_AT,
							ORG.ORGANIZATION_NAME AS ORGANIZATION_NAME,
							ENTRY.PROJECT_CAMERA_TYPE AS PROJECT_CAMERA_TYPE,
							$distanceField,
							ST_ASGEOJSON(SHAPE.POLYGON,15,0):: JSON AS GEOMETRY
						FROM DEFAULT_PROJECTS_PROJECT AS PROJECT
						LEFT JOIN DEFAULT_SHAPES_SHAPE AS SHAPE ON PROJECT.PROJECT_SHAPE_ID = SHAPE.ID
						LEFT JOIN DEFAULT_ORGANIZATIONS_ORGANIZATION AS ORG ON PROJECT.PROJECT_ORGANIZATION_KEY = ORG.ORGANIZATION_KEY
						LEFT JOIN DEFAULT_PROJECTS_IMAGERY_CAPTURE AS ENTRY ON PROJECT.PROJECT_ENTRY_ID = ENTRY.ID
						WHERE PROJECT.IS_MARKETPLACE IS TRUE AND PROJECT.deleted_at is NULL AND SHAPE.deleted_at is NULL AND
						      ORG.deleted_at is NULL
						$distanceOrder) AS ENTRIES) AS F) AS FC;";

        $rows = $connection->select($query, $bindings);

        return (string) ($rows[0]->geojson ?? '{"type":"FeatureCollection","features":null}');
    }

    private function portableGeojson(ConnectionInterface $connection, ?float $lat, ?float $lon): string
    {
        $hasPolygonGeojson = $connection->getSchemaBuilder()->hasColumn('default_shapes_shape', 'polygon_geojson');
        $geometryColumn = $hasPolygonGeojson ? 'shape.polygon_geojson' : 'shape.polygon';

        $rows = $connection
            ->table('default_projects_project as project')
            ->leftJoin('default_shapes_shape as shape', 'project.project_shape_id', '=', 'shape.id')
            ->leftJoin('default_organizations_organization as org', 'project.project_organization_key', '=', 'org.organization_key')
            ->leftJoin('default_projects_imagery_capture as entry', 'project.project_entry_id', '=', 'entry.id')
            ->where('project.is_marketplace', true)
            ->whereNull('project.deleted_at')
            ->whereNull('shape.deleted_at')
            ->whereNull('org.deleted_at')
            ->select([
                'project.marketplace_name',
                'project.marketplace_description',
                'project.id',
                'project.project_key',
                'project.project_organization_key',
                'project.created_at',
                'org.organization_name',
                'entry.project_camera_type',
                DB::raw($geometryColumn.' as geometry_json'),
                'shape.centroid_lon',
                'shape.centroid_lat',
            ])
            ->orderBy('project.rowid')
            ->get()
            ->map(fn (object $row): object => $this->withDistance($row, $lat, $lon))
            ->when(
                $lat !== null && $lon !== null,
                fn ($rows) => $rows->sortBy('distance_km')->values(),
            );

        $features = $rows
            ->map(fn (object $row): array => [
                'type' => 'Feature',
                'properties' => [
                    'marketplace_name' => $row->marketplace_name,
                    'marketplace_description' => $row->marketplace_description,
                    'id' => (int) $row->id,
                    'project_key' => $row->project_key,
                    'organization_key' => $row->project_organization_key,
                    'created_at' => $this->timestamp($row->created_at),
                    'owner' => $row->organization_name,
                    'project_camera_type' => $row->project_camera_type,
                    'distance_km' => $row->distance_km,
                ],
                'geometry' => $this->geometry($row->geometry_json),
            ])
            ->all();

        return json_encode([
            'type' => 'FeatureCollection',
            'features' => $features === [] ? null : $features,
        ], JSON_UNESCAPED_SLASHES);
    }

    private function withDistance(object $row, ?float $lat, ?float $lon): object
    {
        $row->distance_km = '0';

        if ($lat !== null && $lon !== null && $row->centroid_lat !== null && $row->centroid_lon !== null) {
            $row->distance_km = $this->distanceKm($lat, $lon, (float) $row->centroid_lat, (float) $row->centroid_lon);
        }

        return $row;
    }

    private function distanceKm(float $fromLat, float $fromLon, float $toLat, float $toLon): float
    {
        $earthRadiusKm = 6371.0088;
        $deltaLat = deg2rad($toLat - $fromLat);
        $deltaLon = deg2rad($toLon - $fromLon);
        $a = sin($deltaLat / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($deltaLon / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return date('Y-m-d\TH:i:s', strtotime((string) $value));
    }

    private function geometry(?string $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return json_decode($value, true);
    }
}

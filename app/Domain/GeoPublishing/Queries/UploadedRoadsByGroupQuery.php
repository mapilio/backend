<?php

namespace App\Domain\GeoPublishing\Queries;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

class UploadedRoadsByGroupQuery
{
    public function get(string $groupKey): array
    {
        $connection = DB::connection(config('mapilio.legacy_database_connection'));

        return $connection
            ->table('default_mapilio_road as roads')
            ->select([
                'roads.sequence_uuid',
                DB::raw($this->lineFeatureExpression($connection)),
            ])
            ->whereNull('roads.deleted_at')
            ->whereIn('roads.sequence_uuid', function ($query) use ($groupKey): void {
                $query
                    ->select('sequence_uuid')
                    ->from('default_mapilio_sequence_detail')
                    ->where('group_key', $groupKey);
            })
            ->orderBy('roads.id')
            ->get()
            ->map(fn (object $row): array => [
                'sequence_uuid' => $row->sequence_uuid === null ? null : (string) $row->sequence_uuid,
                'linefeature' => $row->linefeature === null ? null : (string) $row->linefeature,
            ])
            ->all();
    }

    private function lineFeatureExpression(ConnectionInterface $connection): string
    {
        if ($connection->getDriverName() === 'pgsql') {
            return 'ST_AsGeoJSON(roads.geom) as linefeature';
        }

        return 'roads.linefeature as linefeature';
    }
}

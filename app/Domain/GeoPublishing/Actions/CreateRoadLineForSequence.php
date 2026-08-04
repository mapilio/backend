<?php

namespace App\Domain\GeoPublishing\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class CreateRoadLineForSequence
{
    private const STATUS_ERROR = 1;

    private const STATUS_PENDING = 2;

    private const STATUS_COMPLETED = 3;

    public function create(string $sequenceUuid, bool $regenerate = false): int
    {
        $connection = DB::connection(config('mapilio.legacy_database_connection'));

        $detail = $connection->table('default_mapilio_sequence_detail')
            ->where('sequence_uuid', $sequenceUuid)
            ->whereNull('deleted_at')
            ->first();

        if ($detail === null) {
            return 0;
        }

        try {
            $connection->table('default_mapilio_sequence_detail')
                ->where('sequence_uuid', $sequenceUuid)
                ->update([
                    'road_line_status' => self::STATUS_PENDING,
                    'road_line_status_message' => null,
                    'updated_at' => Carbon::now(),
                ]);

            if ($regenerate) {
                $connection->table('default_mapilio_road')
                    ->where('sequence_uuid', $sequenceUuid)
                    ->delete();
            } elseif ($connection->table('default_mapilio_road')->where('sequence_uuid', $sequenceUuid)->exists()) {
                $this->markCompleted($sequenceUuid);

                return 0;
            }

            $points = $connection->table('default_mapilio_imagery')
                ->where('sequence_uuid', $sequenceUuid)
                ->whereNull('deleted_at')
                ->where('anomaly', false)
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->orderBy('id')
                ->get(['id', 'latitude', 'longitude', 'device_model', 'capture_time'])
                ->all();

            if (count($points) < 3) {
                $this->markCompleted($sequenceUuid);

                return 0;
            }

            $inserted = 0;

            foreach ($this->roadGroups($points) as $group) {
                if (count($group) > 2) {
                    $this->insertRoad($sequenceUuid, $detail, $group);
                    $inserted++;
                }
            }

            $this->markCompleted($sequenceUuid);

            return $inserted;
        } catch (Throwable $exception) {
            $connection->table('default_mapilio_sequence_detail')
                ->where('sequence_uuid', $sequenceUuid)
                ->update([
                    'road_line_status' => self::STATUS_ERROR,
                    'road_line_status_message' => $exception->getMessage(),
                    'updated_at' => Carbon::now(),
                ]);

            throw $exception;
        }
    }

    /**
     * @param  array<int, object>  $points
     * @return array<int, array<int, object>>
     */
    private function roadGroups(array $points): array
    {
        $groups = [];
        $visited = [];
        $start = 0;

        while (isset($points[$start])) {
            $current = $points[$start];
            $group = [$current];
            $visited[(int) $current->id] = true;

            while ($next = $this->nearestNextPoint($current, $points, $visited)) {
                $group[] = $next;
                $visited[(int) $next->id] = true;
                $current = $next;
            }

            $groups[] = $group;
            $start = $this->nextStartIndex($points, (int) $current->id, $visited);

            if ($start === null) {
                break;
            }
        }

        return $groups;
    }

    /**
     * @param  array<int, object>  $points
     * @param  array<int, bool>  $visited
     */
    private function nearestNextPoint(object $current, array $points, array $visited): ?object
    {
        $thresholdMeters = (string) $current->device_model === 'Ladybug' ? 15.0 : 40.0;
        $nearest = null;
        $nearestDistance = null;

        foreach ($points as $candidate) {
            if ((int) $candidate->id <= (int) $current->id || isset($visited[(int) $candidate->id])) {
                continue;
            }

            $distance = $this->distanceMeters($current, $candidate);

            if ($distance <= $thresholdMeters && ($nearestDistance === null || $distance < $nearestDistance)) {
                $nearest = $candidate;
                $nearestDistance = $distance;
            }
        }

        return $nearest;
    }

    /**
     * @param  array<int, object>  $points
     * @param  array<int, bool>  $visited
     */
    private function nextStartIndex(array $points, int $currentId, array $visited): ?int
    {
        foreach ($points as $index => $point) {
            if ((int) $point->id > $currentId && ! isset($visited[(int) $point->id])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<int, object>  $group
     */
    private function insertRoad(string $sequenceUuid, object $detail, array $group): void
    {
        $connection = DB::connection(config('mapilio.legacy_database_connection'));
        $captureTime = $group[0]->capture_time ?? $detail->created_at;

        if ($connection->getDriverName() === 'pgsql') {
            $ids = array_map(static fn (object $point): int => (int) $point->id, $group);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $connection->insert(
                "insert into default_mapilio_road
                    (sequence_uuid, geom, anomaly, created_at, created_by_id, organization_key, project_key, capture_time)
                 values
                    (?, (
                        select ST_MakeLine(ST_SetSRID(ST_MakePoint(longitude, latitude), 4326) order by id)
                        from default_mapilio_imagery
                        where sequence_uuid = ? and id in ({$placeholders})
                        group by sequence_uuid
                        limit 1
                    ), ?, ?, ?, ?, ?, ?)",
                array_merge([
                    $sequenceUuid,
                    $sequenceUuid,
                ], $ids, [
                    (bool) ($detail->anomaly ?? false),
                    $detail->created_at,
                    $detail->created_by_id,
                    $detail->organization_key,
                    $detail->project_key,
                    $captureTime,
                ]),
            );

            return;
        }

        $connection->table('default_mapilio_road')->insert([
            'created_at' => $detail->created_at,
            'created_by_id' => $detail->created_by_id,
            'updated_at' => Carbon::now(),
            'updated_by_id' => $detail->created_by_id,
            'deleted_at' => null,
            'geom' => $this->lineString($group),
            'sequence_uuid' => $sequenceUuid,
            'anomaly' => (bool) ($detail->anomaly ?? false),
            'organization_key' => $detail->organization_key,
            'project_key' => $detail->project_key,
            'capture_time' => (string) $captureTime,
        ]);
    }

    /**
     * @param  array<int, object>  $group
     */
    private function lineString(array $group): string
    {
        return 'LINESTRING('.implode(', ', array_map(
            static fn (object $point): string => (float) $point->longitude.' '.(float) $point->latitude,
            $group,
        )).')';
    }

    private function markCompleted(string $sequenceUuid): void
    {
        DB::connection(config('mapilio.legacy_database_connection'))
            ->table('default_mapilio_sequence_detail')
            ->where('sequence_uuid', $sequenceUuid)
            ->update([
                'road_line_status' => self::STATUS_COMPLETED,
                'road_line_status_message' => null,
                'updated_at' => Carbon::now(),
            ]);
    }

    private function distanceMeters(object $from, object $to): float
    {
        $earthRadius = 6371000.0;
        $lat1 = deg2rad((float) $from->latitude);
        $lat2 = deg2rad((float) $to->latitude);
        $deltaLat = deg2rad((float) $to->latitude - (float) $from->latitude);
        $deltaLon = deg2rad((float) $to->longitude - (float) $from->longitude);

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}

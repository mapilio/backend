<?php

namespace App\Domain\ImageryUploads\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CalculateSequenceQualityScores
{
    public function calculate(string $sequenceUuid): int
    {
        $connectionName = config('mapilio.legacy_database_connection');
        $schema = Schema::connection($connectionName);

        $scoreColumns = array_filter([
            'gps_score' => $schema->hasColumn('default_mapilio_imagery', 'gps_score'),
            'time_score' => $schema->hasColumn('default_mapilio_imagery', 'time_score'),
            'distance_score' => $schema->hasColumn('default_mapilio_imagery', 'distance_score'),
        ]);

        if ($scoreColumns === []) {
            return 0;
        }

        $connection = DB::connection($connectionName);
        $points = $connection->table('default_mapilio_imagery')
            ->where('sequence_uuid', $sequenceUuid)
            ->whereNull('deleted_at')
            ->where('anomaly', false)
            ->orderBy('id')
            ->get()
            ->all();

        $updated = 0;

        foreach ($points as $point) {
            $values = [];

            if (isset($scoreColumns['gps_score']) && $point->gps_score === null) {
                $values['gps_score'] = $this->gpsScore($point->accuracy_level);
            }

            if (isset($scoreColumns['time_score']) && $point->time_score === null) {
                $values['time_score'] = $this->timeScore($point->capture_time);
            }

            if (isset($scoreColumns['distance_score']) && $point->distance_score === null) {
                $nearest = $this->nearestFollowingPoint($point, $points);

                if ($nearest !== null) {
                    $distance = $this->distanceMeters($point, $nearest);
                    $values['distance_score'] = $this->distanceScore($distance);

                    if ($schema->hasColumn('default_mapilio_imagery', 'nearest_point_id')) {
                        $values['nearest_point_id'] = (int) $nearest->id;
                    }

                    if ($schema->hasColumn('default_mapilio_imagery', 'nearest_distance_on_sequence')) {
                        $values['nearest_distance_on_sequence'] = $distance;
                    }
                }
            }

            if ($values === []) {
                continue;
            }

            $connection->table('default_mapilio_imagery')
                ->where('id', $point->id)
                ->update($values);

            $updated++;
        }

        return $updated;
    }

    private function gpsScore(mixed $accuracyLevel): int
    {
        if ($accuracyLevel === null || $accuracyLevel === '') {
            return 2;
        }

        $accuracy = (float) $accuracyLevel;

        if ($accuracy <= 5) {
            return 3;
        }

        return $accuracy <= 10 ? 2 : 1;
    }

    private function timeScore(mixed $captureTime): int
    {
        $hour = Carbon::parse((string) $captureTime)->hour;

        return $hour >= 7 && $hour <= 20 ? 1 : 0;
    }

    /**
     * @param  array<int, object>  $points
     */
    private function nearestFollowingPoint(object $point, array $points): ?object
    {
        $nearest = null;
        $nearestDistance = null;

        foreach ($points as $candidate) {
            if ((int) $candidate->id <= (int) $point->id) {
                continue;
            }

            $headingDifference = abs((float) $point->heading - (float) $candidate->heading);

            if ($headingDifference > 90 && $headingDifference < 270) {
                continue;
            }

            $distance = $this->distanceMeters($point, $candidate);

            if ($nearestDistance === null || $distance < $nearestDistance) {
                $nearest = $candidate;
                $nearestDistance = $distance;
            }
        }

        return $nearest;
    }

    private function distanceScore(float $distance): float
    {
        return match (true) {
            $distance < 10 => 1.0,
            $distance < 20 => 0.8,
            $distance < 30 => 0.6,
            $distance < 45 => 0.4,
            default => 0.2,
        };
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

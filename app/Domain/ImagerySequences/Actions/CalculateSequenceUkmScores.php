<?php

namespace App\Domain\ImagerySequences\Actions;

use App\Support\Database\LegacyDatabase;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class CalculateSequenceUkmScores
{
    private const STATUS_ERROR = 1;

    private const STATUS_COMPLETED = 2;

    /**
     * @return array{status: string, processed: int, no_neighbor: int}
     */
    public function calculate(string $sequenceUuid): array
    {
        if (! config('mapilio.ukm_scoring.enabled')) {
            return [
                'status' => 'disabled',
                'processed' => 0,
                'no_neighbor' => 0,
            ];
        }

        $connection = $this->legacyConnection();

        try {
            $this->assertSchema($connection);
            $this->assertSequence($connection, $sequenceUuid);

            if ($connection->getDriverName() === 'pgsql') {
                $this->assertSpatialIndex($connection);
            }

            $pending = $this->pendingCount($connection, $sequenceUuid);

            if ($pending === 0) {
                return [
                    'status' => 'completed',
                    'processed' => 0,
                    'no_neighbor' => 0,
                ];
            }

            $maximumPoints = min(50_000, max(1, (int) config('mapilio.ukm_scoring.max_points_per_sequence', 10_000)));

            if ($pending > $maximumPoints) {
                throw new UkmScoringException('UKM scoring point limit exceeded for sequence.');
            }

            $rows = $connection->getDriverName() === 'pgsql'
                ? $this->postgresDistances($connection, $sequenceUuid)
                : $this->portableDistances($connection, $sequenceUuid);

            if ($rows->count() !== $pending) {
                throw new UkmScoringException('UKM scoring requires valid coordinates, heading, geometry, and capture time for every pending image.');
            }

            $now = Carbon::now();
            $maximumDistance = $this->maximumDistance();
            $updates = $rows->map(function (object $row) use ($maximumDistance, $now): array {
                $distance = $row->distance === null
                    ? $maximumDistance
                    : min($maximumDistance, max(0.0, (float) $row->distance));

                return [
                    'id' => (int) $row->id,
                    'distance' => $distance,
                    'score' => $this->score($distance, Carbon::parse((string) $row->capture_time), $now),
                ];
            });

            $this->persist($connection, $updates, $now);

            return [
                'status' => 'completed',
                'processed' => $updates->count(),
                'no_neighbor' => $rows->whereNull('distance')->count(),
            ];
        } catch (Throwable $exception) {
            $message = $exception instanceof UkmScoringException
                ? Str::limit($exception->getMessage(), 1000, '')
                : 'UKM scoring could not be completed.';

            try {
                $this->markFailed($connection, $sequenceUuid, $message);
            } catch (Throwable) {
                // Keep the original scoring failure when status persistence also fails.
            }

            if ($exception instanceof UkmScoringException) {
                throw $exception;
            }

            throw new UkmScoringException($message, previous: $exception);
        }
    }

    private function assertSchema(Connection $connection): void
    {
        $connectionName = config('mapilio.legacy_database_connection');
        $schema = Schema::connection($connectionName);

        if (! $schema->hasTable('default_mapilio_sequence_detail') || ! $schema->hasTable('default_mapilio_imagery')) {
            throw new UkmScoringException('UKM scoring tables are not available.');
        }

        foreach (['sequence_uuid', 'deleted_at', 'anomaly'] as $column) {
            if (! $schema->hasColumn('default_mapilio_sequence_detail', $column)) {
                throw new UkmScoringException("UKM sequence column {$column} is not available.");
            }
        }

        $required = [
            'id',
            'sequence_uuid',
            'capture_time',
            'heading',
            'anomaly',
            'deleted_at',
            'ukm_closest_distance',
            'ukm_score',
            'ukm_status',
            'ukm_status_message',
            'updated_at',
        ];

        if ($connection->getDriverName() === 'pgsql') {
            $required[] = 'geom';
        } else {
            $required[] = 'latitude';
            $required[] = 'longitude';
        }

        foreach ($required as $column) {
            if (! $schema->hasColumn('default_mapilio_imagery', $column)) {
                throw new UkmScoringException("UKM scoring column {$column} is not available.");
            }
        }
    }

    private function assertSequence(Connection $connection, string $sequenceUuid): void
    {
        $count = $connection->table('default_mapilio_sequence_detail')
            ->where('sequence_uuid', $sequenceUuid)
            ->whereNull('deleted_at')
            ->where('anomaly', false)
            ->limit(2)
            ->count();

        if ($count !== 1) {
            throw new UkmScoringException('UKM scoring requires exactly one active sequence.');
        }
    }

    private function assertSpatialIndex(Connection $connection): void
    {
        if (! config('mapilio.ukm_scoring.require_spatial_index', true)) {
            return;
        }

        $indexName = (string) config('mapilio.ukm_scoring.spatial_index', 'ix_imagery_ukm_geography_active');
        $exists = $connection->table('pg_indexes')
            ->whereRaw('schemaname = current_schema()')
            ->where('tablename', 'default_mapilio_imagery')
            ->where('indexname', $indexName)
            ->exists();

        if (! $exists) {
            throw new UkmScoringException("Required UKM spatial index {$indexName} is not installed.");
        }
    }

    private function pendingCount(Connection $connection, string $sequenceUuid): int
    {
        return $connection->table('default_mapilio_imagery')
            ->where('sequence_uuid', $sequenceUuid)
            ->whereNull('deleted_at')
            ->where('anomaly', false)
            ->whereNull('ukm_score')
            ->count();
    }

    /**
     * @return Collection<int, object>
     */
    private function postgresDistances(Connection $connection, string $sequenceUuid): Collection
    {
        $historyMonths = min(120, max(1, (int) config('mapilio.ukm_scoring.history_months', 6)));
        $headingTolerance = min(180.0, max(0.0, (float) config('mapilio.ukm_scoring.heading_tolerance_degrees', 45)));
        $maximumDistance = $this->maximumDistance();

        $rows = $connection->select(<<<'SQL'
            select
                source.id,
                source.capture_time,
                nearest.distance
            from default_mapilio_imagery as source
            left join lateral (
                select ST_DistanceSphere(candidate.geom, source.geom) as distance
                from default_mapilio_imagery as candidate
                where candidate.id <> source.id
                  and candidate.deleted_at is null
                  and candidate.anomaly is false
                  and candidate.geom is not null
                  and candidate.sequence_uuid <> source.sequence_uuid
                  and candidate.capture_time between source.capture_time - (? * interval '1 month') and source.capture_time
                  and (
                      abs(source.heading - candidate.heading) <= ?
                      or abs(source.heading - candidate.heading) >= ?
                  )
                  and ST_DWithin(candidate.geom::geography, source.geom::geography, ?)
                order by candidate.geom::geography <-> source.geom::geography
                limit 1
            ) as nearest on true
            where source.sequence_uuid = ?
              and source.deleted_at is null
              and source.anomaly is false
              and source.ukm_score is null
              and source.geom is not null
              and source.capture_time is not null
              and source.heading between 0 and 360
            order by source.id
            SQL, [
            $historyMonths,
            $headingTolerance,
            360 - $headingTolerance,
            $maximumDistance,
            $sequenceUuid,
        ]);

        return collect($rows);
    }

    private function portableDistances(Connection $connection, string $sequenceUuid): Collection
    {
        $historyMonths = min(120, max(1, (int) config('mapilio.ukm_scoring.history_months', 6)));
        $headingTolerance = min(180.0, max(0.0, (float) config('mapilio.ukm_scoring.heading_tolerance_degrees', 45)));
        $maximumDistance = $this->maximumDistance();

        $sources = $connection->table('default_mapilio_imagery')
            ->where('sequence_uuid', $sequenceUuid)
            ->whereNull('deleted_at')
            ->where('anomaly', false)
            ->whereNull('ukm_score')
            ->whereNotNull('capture_time')
            ->whereBetween('heading', [0, 360])
            ->whereBetween('latitude', [-90, 90])
            ->whereBetween('longitude', [-180, 180])
            ->orderBy('id')
            ->get(['id', 'sequence_uuid', 'capture_time', 'heading', 'latitude', 'longitude']);

        return $sources->map(function (object $source) use ($connection, $historyMonths, $headingTolerance, $maximumDistance) {
            $captureTime = Carbon::parse((string) $source->capture_time);
            $candidates = $connection->table('default_mapilio_imagery')
                ->where('id', '!=', $source->id)
                ->where('sequence_uuid', '!=', $source->sequence_uuid)
                ->whereNull('deleted_at')
                ->where('anomaly', false)
                ->whereBetween('capture_time', [
                    $captureTime->copy()->subMonthsNoOverflow($historyMonths),
                    $captureTime,
                ])
                ->whereBetween('heading', [0, 360])
                ->whereBetween('latitude', [-90, 90])
                ->whereBetween('longitude', [-180, 180])
                ->get(['heading', 'latitude', 'longitude']);

            $nearest = null;

            foreach ($candidates as $candidate) {
                if ($this->headingDifference((float) $source->heading, (float) $candidate->heading) > $headingTolerance) {
                    continue;
                }

                $distance = $this->haversineMeters(
                    (float) $source->latitude,
                    (float) $source->longitude,
                    (float) $candidate->latitude,
                    (float) $candidate->longitude,
                );

                if ($distance <= $maximumDistance && ($nearest === null || $distance < $nearest)) {
                    $nearest = $distance;
                }
            }

            return (object) [
                'id' => $source->id,
                'capture_time' => $source->capture_time,
                'distance' => $nearest,
            ];
        });
    }

    private function score(float $distance, Carbon $captureTime, Carbon $now): float
    {
        $minimumDistance = $this->minimumDistance();
        $maximumDistance = $this->maximumDistance();
        $maximumScore = min(100.0, max(0.1, (float) config('mapilio.ukm_scoring.max_score', 5)));
        $distance = min($maximumDistance, max($minimumDistance, $distance));
        $baseScore = round(($distance / $maximumDistance) * $maximumScore, 1);
        $ageYears = max(1, (int) floor($captureTime->diffInYears($now, true)));

        return $baseScore / $ageYears;
    }

    private function minimumDistance(): float
    {
        return min(1000.0, max(0.1, (float) config('mapilio.ukm_scoring.min_distance_meters', 1)));
    }

    private function maximumDistance(): float
    {
        return min(
            1000.0,
            max($this->minimumDistance(), (float) config('mapilio.ukm_scoring.max_distance_meters', 40)),
        );
    }

    /**
     * @param  Collection<int, array{id: int, distance: float, score: float}>  $updates
     */
    private function persist(Connection $connection, Collection $updates, Carbon $now): void
    {
        if ($connection->getDriverName() === 'pgsql') {
            $this->persistPostgres($connection, $updates, $now);

            return;
        }

        $connection->transaction(function () use ($connection, $updates, $now): void {
            foreach ($updates as $update) {
                $connection->table('default_mapilio_imagery')
                    ->where('id', $update['id'])
                    ->whereNull('ukm_score')
                    ->update([
                        'ukm_closest_distance' => $update['distance'],
                        'ukm_score' => $update['score'],
                        'ukm_status' => self::STATUS_COMPLETED,
                        'ukm_status_message' => null,
                        'updated_at' => $now,
                    ]);
            }
        });
    }

    /**
     * @param  Collection<int, array{id: int, distance: float, score: float}>  $updates
     */
    private function persistPostgres(Connection $connection, Collection $updates, Carbon $now): void
    {
        $connection->transaction(function () use ($connection, $updates, $now): void {
            $updates->chunk(500)->each(function (Collection $chunk) use ($connection, $now): void {
                $rows = array_fill(0, $chunk->count(), '(?::integer, ?::double precision, ?::double precision, ?::integer, ?::timestamp)');
                $bindings = [];

                foreach ($chunk as $update) {
                    array_push(
                        $bindings,
                        $update['id'],
                        $update['distance'],
                        $update['score'],
                        self::STATUS_COMPLETED,
                        $now->toDateTimeString(),
                    );
                }

                $connection->update(
                    'update default_mapilio_imagery as imagery
                     set ukm_closest_distance = scored.distance,
                         ukm_score = scored.score,
                         ukm_status = scored.status,
                         ukm_status_message = null,
                         updated_at = scored.updated_at
                     from (values '.implode(', ', $rows).') as scored(id, distance, score, status, updated_at)
                     where imagery.id = scored.id
                       and imagery.ukm_score is null',
                    $bindings,
                );
            });
        });
    }

    private function markFailed(Connection $connection, string $sequenceUuid, string $message): void
    {
        $schema = Schema::connection(config('mapilio.legacy_database_connection'));

        if (
            ! $schema->hasTable('default_mapilio_imagery')
            || ! $schema->hasColumn('default_mapilio_imagery', 'ukm_status')
            || ! $schema->hasColumn('default_mapilio_imagery', 'ukm_status_message')
        ) {
            return;
        }

        $connection->table('default_mapilio_imagery')
            ->where('sequence_uuid', $sequenceUuid)
            ->whereNull('deleted_at')
            ->where('anomaly', false)
            ->whereNull('ukm_score')
            ->update([
                'ukm_status' => self::STATUS_ERROR,
                'ukm_status_message' => $message,
                'updated_at' => now(),
            ]);
    }

    private function headingDifference(float $first, float $second): float
    {
        $difference = abs($first - $second);

        return min($difference, 360 - $difference);
    }

    private function haversineMeters(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
    {
        $earthRadius = 6_371_000.0;
        $latitudeDelta = deg2rad($latitudeB - $latitudeA);
        $longitudeDelta = deg2rad($longitudeB - $longitudeA);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($longitudeDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function legacyConnection(): Connection
    {
        return LegacyDatabase::connection();
    }
}

<?php

namespace App\Domain\InventoryFeatures\Queries;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;
use stdClass;
use Throwable;

/**
 * @phpstan-type JsonObject array<int|string, mixed>
 * @phpstan-type PointGeometry array{type: 'Point', coordinates: array{float, float}}
 * @phpstan-type ImageUrls array{original: string, preview_480: string}
 * @phpstan-type FeatureImage array{id: int, sequence_uuid: string, uploaded_hash: string|null, filename: string|null, resolution: string|null, heading: float|null, capture_time: string|null, created_by_id: int|null, geometry: PointGeometry|null, urls: ImageUrls|null}
 * @phpstan-type FeatureObservation array{id: int, object_key: string, imagery_id: int, bbox: array{float, float, float, float}, score: float, segmentation: JsonObject|null, image: FeatureImage|null}
 * @phpstan-type FeatureMatch array{id: int, source_index: int, score: float, geometry: PointGeometry, observation_1: FeatureObservation, observation_2: FeatureObservation}
 * @phpstan-type FeatureProperties array{class_code: string, confidence: float, verified: bool, dimensions: array{width: float, height: float, area: float}, attributes: JsonObject|null, sequence_uuid: string, project_key: string|null, organization_key: string|null, created_by_id: int|null, created_at: string|null, updated_at: string|null}
 * @phpstan-type FeatureDetail array{type: 'Feature', id: int, geometry: PointGeometry, properties: FeatureProperties, matches: list<FeatureMatch>}
 */
class AiFeatureDetailQuery
{
    /** @return FeatureDetail|null */
    public function find(int $featureId): ?array
    {
        try {
            $feature = DB::table('ai_detection_features')
                ->select([
                    'id',
                    'sequence_uuid',
                    'created_by_id',
                    'organization_key',
                    'project_key',
                    'class_code',
                    'confidence',
                    'geometry',
                    'width',
                    'height',
                    'area',
                    'verified',
                    'attributes',
                    'created_at',
                    'updated_at',
                ])
                ->where('id', $featureId)
                ->first();

            if ($feature === null) {
                return null;
            }

            $matches = $this->matchesFor($featureId);
            $observations = $this->observationsFor($matches);
            $images = $this->imagesFor($observations);

            return $this->mapFeature($feature, $matches, $observations, $images);
        } catch (AiFeatureDetailException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new AiFeatureDetailException('AI feature detail could not be read.', previous: $exception);
        }
    }

    /** @return Collection<int, stdClass> */
    private function matchesFor(int $featureId): Collection
    {
        $maximum = max(1, (int) config('mapilio.ai_result_persistence.max_matches_per_feature', 1000));

        $matches = DB::table('ai_detection_matches')
            ->select([
                'id',
                'observation_1_id',
                'observation_2_id',
                'source_index',
                'geometry',
                'score',
            ])
            ->where('detection_feature_id', $featureId)
            ->orderBy('source_index')
            ->orderBy('id')
            ->limit($maximum + 1)
            ->get();

        if ($matches->count() > $maximum) {
            throw new AiFeatureDetailException('AI feature match limit exceeded.');
        }

        return $matches;
    }

    /**
     * @param  Collection<int, stdClass>  $matches
     * @return Collection<int, stdClass>
     */
    private function observationsFor(Collection $matches): Collection
    {
        $ids = $matches
            ->flatMap(fn (object $match): array => [
                (int) $match->observation_1_id,
                (int) $match->observation_2_id,
            ])
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $observations = DB::table('ai_detection_observations')
            ->select([
                'id',
                'sequence_uuid',
                'object_key',
                'imagery_id',
                'x_min',
                'y_min',
                'x_max',
                'y_max',
                'score',
                'segmentation',
            ])
            ->whereIn('id', $ids->all())
            ->get()
            ->keyBy(fn (object $observation): int => (int) $observation->id);

        if ($observations->count() !== $ids->count()) {
            throw new AiFeatureDetailException('AI feature observation graph is incomplete.');
        }

        return $observations;
    }

    /**
     * @param  Collection<int, stdClass>  $observations
     * @return Collection<int, stdClass>
     */
    private function imagesFor(Collection $observations): Collection
    {
        $ids = $observations
            ->pluck('imagery_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return DB::connection((string) config('mapilio.legacy_database_connection'))
            ->table('default_mapilio_imagery')
            ->select([
                'id',
                'sequence_uuid',
                'uploaded_hash',
                'filename',
                'resolution',
                'heading',
                'capture_time',
                'created_by_id',
                'longitude',
                'latitude',
            ])
            ->whereIn('id', $ids->all())
            ->where('anomaly', false)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy(fn (object $image): int => (int) $image->id);
    }

    /**
     * @param  Collection<int, stdClass>  $matches
     * @param  Collection<int, stdClass>  $observations
     * @param  Collection<int, stdClass>  $images
     * @return FeatureDetail
     */
    private function mapFeature(object $feature, Collection $matches, Collection $observations, Collection $images): array
    {
        return [
            'type' => 'Feature',
            'id' => (int) $feature->id,
            'geometry' => $this->decodePointGeometry($feature->geometry, 'feature geometry'),
            'properties' => [
                'class_code' => (string) $feature->class_code,
                'confidence' => (float) $feature->confidence,
                'verified' => (bool) $feature->verified,
                'dimensions' => [
                    'width' => (float) $feature->width,
                    'height' => (float) $feature->height,
                    'area' => (float) $feature->area,
                ],
                'attributes' => $feature->attributes === null
                    ? null
                    : $this->decodeJsonObject($feature->attributes, 'feature attributes'),
                'sequence_uuid' => (string) $feature->sequence_uuid,
                'project_key' => $this->nullableString($feature->project_key),
                'organization_key' => $this->nullableString($feature->organization_key),
                'created_by_id' => $feature->created_by_id === null ? null : (int) $feature->created_by_id,
                'created_at' => $this->formatTimestamp($feature->created_at),
                'updated_at' => $this->formatTimestamp($feature->updated_at),
            ],
            'matches' => $matches
                ->map(fn (object $match): array => $this->mapMatch($match, $observations, $images))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, stdClass>  $observations
     * @param  Collection<int, stdClass>  $images
     * @return FeatureMatch
     */
    private function mapMatch(object $match, Collection $observations, Collection $images): array
    {
        return [
            'id' => (int) $match->id,
            'source_index' => (int) $match->source_index,
            'score' => (float) $match->score,
            'geometry' => $this->decodePointGeometry($match->geometry, 'match geometry'),
            'observation_1' => $this->mapObservation(
                $observations->get((int) $match->observation_1_id),
                $images,
            ),
            'observation_2' => $this->mapObservation(
                $observations->get((int) $match->observation_2_id),
                $images,
            ),
        ];
    }

    /**
     * @param  Collection<int, stdClass>  $images
     * @return FeatureObservation
     */
    private function mapObservation(?object $observation, Collection $images): array
    {
        if ($observation === null) {
            throw new AiFeatureDetailException('AI feature observation graph is incomplete.');
        }

        $image = $images->get((int) $observation->imagery_id);

        if ($image !== null && (string) $image->sequence_uuid !== (string) $observation->sequence_uuid) {
            $image = null;
        }

        return [
            'id' => (int) $observation->id,
            'object_key' => (string) $observation->object_key,
            'imagery_id' => (int) $observation->imagery_id,
            'bbox' => [
                (float) $observation->x_min,
                (float) $observation->y_min,
                (float) $observation->x_max,
                (float) $observation->y_max,
            ],
            'score' => (float) $observation->score,
            'segmentation' => $observation->segmentation === null
                ? null
                : $this->decodeJsonObject($observation->segmentation, 'observation segmentation'),
            'image' => $image === null ? null : $this->mapImage($image),
        ];
    }

    /** @return FeatureImage */
    private function mapImage(object $image): array
    {
        $uploadedHash = $this->nullableString($image->uploaded_hash);
        $filename = $this->nullableString($image->filename);

        return [
            'id' => (int) $image->id,
            'sequence_uuid' => (string) $image->sequence_uuid,
            'uploaded_hash' => $uploadedHash,
            'filename' => $filename,
            'resolution' => $this->nullableString($image->resolution),
            'heading' => $image->heading === null ? null : (float) $image->heading,
            'capture_time' => $this->formatTimestamp($image->capture_time),
            'created_by_id' => $image->created_by_id === null ? null : (int) $image->created_by_id,
            'geometry' => $image->longitude === null || $image->latitude === null
                ? null
                : [
                    'type' => 'Point',
                    'coordinates' => [(float) $image->longitude, (float) $image->latitude],
                ],
            'urls' => $uploadedHash === null || $filename === null
                ? null
                : $this->imageUrls($uploadedHash, $filename),
        ];
    }

    /** @return ImageUrls */
    private function imageUrls(string $uploadedHash, string $filename): array
    {
        $segments = array_filter([
            rtrim((string) config('mapilio.image_server.cdn_base_url'), '/'),
            trim((string) config('mapilio.image_server.image_path_prefix'), '/'),
            rawurlencode($uploadedHash),
            rawurlencode($filename),
        ], fn (string $segment): bool => $segment !== '');

        $original = implode('/', $segments);

        return [
            'original' => $original,
            'preview_480' => $original.'/480',
        ];
    }

    /** @return JsonObject */
    private function decodeJsonObject(mixed $value, string $field): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            throw new AiFeatureDetailException("Stored {$field} is invalid.");
        }

        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AiFeatureDetailException("Stored {$field} is invalid.", previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new AiFeatureDetailException("Stored {$field} is invalid.");
        }

        return $decoded;
    }

    /** @return PointGeometry */
    private function decodePointGeometry(mixed $value, string $field): array
    {
        $geometry = $this->decodeJsonObject($value, $field);
        $coordinates = $geometry['coordinates'] ?? null;

        if (($geometry['type'] ?? null) !== 'Point'
            || ! is_array($coordinates)
            || count($coordinates) !== 2
            || ! is_numeric($coordinates[0])
            || ! is_numeric($coordinates[1])) {
            throw new AiFeatureDetailException("Stored {$field} is invalid.");
        }

        $longitude = (float) $coordinates[0];
        $latitude = (float) $coordinates[1];

        if (! is_finite($longitude)
            || ! is_finite($latitude)
            || $longitude < -180
            || $longitude > 180
            || $latitude < -90
            || $latitude > 90) {
            throw new AiFeatureDetailException("Stored {$field} is invalid.");
        }

        return [
            'type' => 'Point',
            'coordinates' => [$longitude, $latitude],
        ];
    }

    private function formatTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->toIso8601String();
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}

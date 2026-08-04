<?php

namespace App\Domain\AiJobsPredictions\Actions;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use JsonException;

class NormalizePredictionResult
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    public function normalize(array $payload, object $processing): array
    {
        if (strtoupper((string) ($payload['status'] ?? '')) !== 'SUCCESS') {
            return [];
        }

        $features = data_get($payload, 'result.features');

        if (! is_array($features) || ! array_is_list($features)) {
            throw $this->invalid('features must be a list');
        }

        $normalized = [];
        $observations = [];
        $classCodes = [];
        $imageryIds = [];

        foreach ($features as $featureIndex => $feature) {
            if (! is_array($feature) || ($feature['type'] ?? null) !== 'Feature') {
                throw $this->invalid("feature {$featureIndex} is invalid");
            }

            $geometry = $this->pointGeometry($feature['geometry'] ?? null, "feature {$featureIndex}");
            $properties = $this->object($feature['properties'] ?? null, "feature {$featureIndex} properties");
            $classCode = $this->string($properties['class_code'] ?? null, 100, "feature {$featureIndex} class_code");
            $matches = $properties['matchedPoints'] ?? [];

            if (
                ! is_array($matches)
                || ! array_is_list($matches)
                || count($matches) > (int) config('mapilio.ai_result_persistence.max_matches_per_feature', 1000)
            ) {
                throw $this->invalid("feature {$featureIndex} matchedPoints is invalid");
            }

            $normalizedMatches = [];

            foreach ($matches as $matchIndex => $match) {
                $normalizedMatch = $this->match($match, $featureIndex, $matchIndex);
                $normalizedMatches[] = $normalizedMatch;

                foreach (['observation_1', 'observation_2'] as $side) {
                    $observation = $normalizedMatch[$side];
                    $objectKey = $observation['object_key'];
                    $hash = hash('sha256', $this->json($observation));

                    if (isset($observations[$objectKey]) && $observations[$objectKey] !== $hash) {
                        throw $this->invalid("observation {$objectKey} has conflicting values");
                    }

                    $observations[$objectKey] = $hash;
                    $imageryIds[] = $observation['imagery_id'];
                }
            }

            $attributes = $properties['feature'] ?? null;

            if ($attributes !== null && ! is_array($attributes)) {
                throw $this->invalid("feature {$featureIndex} attributes is invalid");
            }

            if ($attributes !== null && strlen($this->json($attributes)) > (int) config('mapilio.ai_result_persistence.max_attributes_bytes', 131_072)) {
                throw $this->invalid("feature {$featureIndex} attributes is too large");
            }

            $classCodes[] = $classCode;
            $normalized[] = [
                'source_index' => $featureIndex,
                'class_code' => $classCode,
                'confidence' => $this->boundedNumber($properties['confidence'] ?? null, 0, 1, "feature {$featureIndex} confidence"),
                'longitude' => $geometry['coordinates'][0],
                'latitude' => $geometry['coordinates'][1],
                'geometry' => $geometry,
                'width' => $this->measurement($properties['width'] ?? null, "feature {$featureIndex} width"),
                'height' => $this->measurement($properties['height'] ?? null, "feature {$featureIndex} height"),
                'area' => $this->measurement($properties['area'] ?? null, "feature {$featureIndex} area"),
                'verified' => (float) $properties['confidence'] > 0.60,
                'attributes' => $attributes,
                'matches' => $normalizedMatches,
            ];
        }

        $this->assertClassCodes($classCodes);
        $this->assertImageryOwnership($imageryIds, (string) $processing->sequence_uuid);

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function match(mixed $match, int $featureIndex, int $matchIndex): array
    {
        $label = "feature {$featureIndex} match {$matchIndex}";

        if (! is_array($match) || ($match['type'] ?? null) !== 'Feature') {
            throw $this->invalid("{$label} is invalid");
        }

        $geometry = $this->pointGeometry($match['geometry'] ?? null, $label);
        $properties = $this->object($match['properties'] ?? null, "{$label} properties");
        $observation1 = $this->observation($properties, 1, $label);
        $observation2 = $this->observation($properties, 2, $label);

        if ($observation1['object_key'] === $observation2['object_key']) {
            throw $this->invalid("{$label} observation keys must be different");
        }

        return [
            'source_index' => $matchIndex,
            'longitude' => $geometry['coordinates'][0],
            'latitude' => $geometry['coordinates'][1],
            'geometry' => $geometry,
            'score' => ($observation1['score'] + $observation2['score']) / 2,
            'observation_1' => $observation1,
            'observation_2' => $observation2,
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function observation(array $properties, int $side, string $label): array
    {
        $bbox = $properties["bbox_{$side}"] ?? null;

        if (! is_array($bbox) || count($bbox) !== 4 || ! array_is_list($bbox)) {
            throw $this->invalid("{$label} bbox_{$side} is invalid");
        }

        $bbox = array_map(
            fn (mixed $value): float => $this->boundedNumber($value, -1_000_000, 1_000_000, "{$label} bbox_{$side}"),
            $bbox,
        );

        if ($bbox[0] > $bbox[2] || $bbox[1] > $bbox[3]) {
            throw $this->invalid("{$label} bbox_{$side} bounds are invalid");
        }

        $segmentation = $properties["segmentation_{$side}"] ?? null;

        if ($segmentation !== null && ! is_array($segmentation)) {
            throw $this->invalid("{$label} segmentation_{$side} is invalid");
        }

        if ($segmentation !== null && strlen($this->json($segmentation)) > (int) config('mapilio.ai_result_persistence.max_segmentation_bytes', 1_048_576)) {
            throw $this->invalid("{$label} segmentation_{$side} is too large");
        }

        $imageryId = filter_var($properties["panoId_{$side}"] ?? null, FILTER_VALIDATE_INT);

        if ($imageryId === false || $imageryId < 1) {
            throw $this->invalid("{$label} panoId_{$side} is invalid");
        }

        return [
            'object_key' => $this->string($properties["objId_{$side}"] ?? null, 255, "{$label} objId_{$side}"),
            'imagery_id' => $imageryId,
            'x_min' => $bbox[0],
            'y_min' => $bbox[1],
            'x_max' => $bbox[2],
            'y_max' => $bbox[3],
            'score' => $this->boundedNumber($properties["score_{$side}"] ?? null, 0, 1, "{$label} score_{$side}"),
            'segmentation' => $segmentation,
        ];
    }

    /**
     * @return array{type: string, coordinates: array{0: float, 1: float}}
     */
    private function pointGeometry(mixed $geometry, string $label): array
    {
        if (! is_array($geometry) || ($geometry['type'] ?? null) !== 'Point') {
            throw $this->invalid("{$label} geometry is invalid");
        }

        $coordinates = $geometry['coordinates'] ?? null;

        if (! is_array($coordinates) || count($coordinates) !== 2 || ! array_is_list($coordinates)) {
            throw $this->invalid("{$label} coordinates are invalid");
        }

        return [
            'type' => 'Point',
            'coordinates' => [
                $this->boundedNumber($coordinates[0], -180, 180, "{$label} longitude"),
                $this->boundedNumber($coordinates[1], -90, 90, "{$label} latitude"),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function object(mixed $value, string $label): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw $this->invalid("{$label} is invalid");
        }

        return $value;
    }

    private function string(mixed $value, int $maxLength, string $label): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $maxLength) {
            throw $this->invalid("{$label} is invalid");
        }

        return trim($value);
    }

    private function measurement(mixed $value, string $label): float
    {
        return $this->boundedNumber($value, 0, 1_000_000_000, $label);
    }

    private function boundedNumber(mixed $value, float $minimum, float $maximum, string $label): float
    {
        if (! is_int($value) && ! is_float($value)) {
            throw $this->invalid("{$label} is invalid");
        }

        $value = (float) $value;

        if (! is_finite($value) || $value < $minimum || $value > $maximum) {
            throw $this->invalid("{$label} is out of range");
        }

        return $value;
    }

    /**
     * @param  array<int, string>  $classCodes
     */
    private function assertClassCodes(array $classCodes): void
    {
        $classCodes = array_values(array_unique($classCodes));

        if ($classCodes === []) {
            return;
        }

        $allowed = $this->legacyConnection()
            ->table('default_types_type')
            ->whereNull('deleted_at')
            ->whereIn('code', $classCodes)
            ->pluck('code')
            ->all();

        $unknown = array_values(array_diff($classCodes, $allowed));

        if ($unknown !== []) {
            throw $this->invalid('unknown class codes: '.implode(', ', $unknown));
        }
    }

    /**
     * @param  array<int, int>  $imageryIds
     */
    private function assertImageryOwnership(array $imageryIds, string $sequenceUuid): void
    {
        $imageryIds = array_values(array_unique($imageryIds));

        if ($imageryIds === []) {
            return;
        }

        $owned = $this->legacyConnection()
            ->table('default_mapilio_imagery')
            ->where('sequence_uuid', $sequenceUuid)
            ->whereNull('deleted_at')
            ->where('anomaly', false)
            ->whereIn('id', $imageryIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $unknown = array_values(array_diff($imageryIds, $owned));

        if ($unknown !== []) {
            throw $this->invalid('imagery does not belong to the processing sequence');
        }
    }

    private function legacyConnection(): ConnectionInterface
    {
        return DB::connection(config('mapilio.legacy_database_connection'));
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PredictionResultPersistenceException('AI result contains invalid JSON values.', previous: $exception);
        }
    }

    private function invalid(string $reason): PredictionResultPersistenceException
    {
        return new PredictionResultPersistenceException("Invalid AI result: {$reason}.");
    }
}

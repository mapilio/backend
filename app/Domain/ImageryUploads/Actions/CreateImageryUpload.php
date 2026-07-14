<?php

namespace App\Domain\ImageryUploads\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateImageryUpload
{
    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function create(array $parameters, object $user): array
    {
        $jsonData = $parameters['json_data'] ?? null;
        $summary = $parameters['summary']['Information'] ?? null;

        if (! is_array($jsonData) || $jsonData === []) {
            throw ImageryUploadException::missing('json_data');
        }

        if (! is_array($summary)) {
            throw ImageryUploadException::missing('summary');
        }

        $sequenceUuid = (string) ($summary['sequence_uuid'] ?? $jsonData[0]['sequenceUuid'] ?? '');
        $uploadedHash = (string) ($summary['hash'] ?? '');

        if ($sequenceUuid === '') {
            throw ImageryUploadException::missing('summary.Information.sequence_uuid');
        }

        if ($uploadedHash === '') {
            throw ImageryUploadException::missing('summary.Information.hash');
        }

        foreach ($jsonData as $index => $point) {
            if (! is_array($point)) {
                throw ImageryUploadException::missing("json_data.{$index}");
            }

            $this->assertPoint($point);
        }

        $now = Carbon::now();
        $createdById = (int) $user->id;
        $organizationKey = $this->blankToNull($parameters['organization_key'] ?? null);
        $projectKey = $this->blankToNull($parameters['project_key'] ?? null);

        $imageryRows = array_map(
            fn (array $point): array => $this->imageryRow(
                point: $point,
                summary: $summary,
                uploadedHash: $uploadedHash,
                createdById: $createdById,
                organizationKey: $organizationKey,
                projectKey: $projectKey,
                now: $now,
            ),
            $jsonData,
        );

        DB::connection(config('mapilio.legacy_database_connection'))->transaction(function () use (
            $imageryRows,
            $summary,
            $sequenceUuid,
            $createdById,
            $organizationKey,
            $projectKey,
            $now,
        ): void {
            DB::connection(config('mapilio.legacy_database_connection'))
                ->table('default_mapilio_imagery')
                ->upsert(
                    $imageryRows,
                    ['latitude', 'longitude', 'capture_time'],
                    array_values(array_diff(array_keys($imageryRows[0]), ['created_at'])),
                );

            $exists = DB::connection(config('mapilio.legacy_database_connection'))
                ->table('default_mapilio_sequence_detail')
                ->where('sequence_uuid', $sequenceUuid)
                ->exists();

            if (! $exists) {
                DB::connection(config('mapilio.legacy_database_connection'))
                    ->table('default_mapilio_sequence_detail')
                    ->insert($this->sequenceDetailRow(
                        summary: $summary,
                        sequenceUuid: $sequenceUuid,
                        createdById: $createdById,
                        organizationKey: $organizationKey,
                        projectKey: $projectKey,
                        now: $now,
                    ));
            }
        });

        return [
            'status' => true,
            'data' => true,
            'sequence_uuid' => $sequenceUuid,
            'count' => count($imageryRows),
        ];
    }

    /**
     * @param  array<string, mixed>  $point
     */
    private function assertPoint(array $point): void
    {
        foreach ([
            'latitude',
            'longitude',
            'heading',
            'altitude',
            'orientation',
            'captureTime',
            'filename',
            'deviceMake',
            'deviceModel',
            'imageSize',
            'fov',
            'sequenceUuid',
            'anomaly',
            'roll',
            'pitch',
            'yaw',
        ] as $field) {
            if (! array_key_exists($field, $point) || $point[$field] === null || $point[$field] === '') {
                throw ImageryUploadException::missing($field);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $point
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function imageryRow(
        array $point,
        array $summary,
        string $uploadedHash,
        int $createdById,
        ?string $organizationKey,
        ?string $projectKey,
        Carbon $now,
    ): array {
        [$width, $height] = $this->parseImageSize($point['imageSize'] ?? null);

        return [
            'created_at' => $now,
            'created_by_id' => $createdById,
            'updated_at' => $now,
            'updated_by_id' => $createdById,
            'deleted_at' => null,
            'latitude' => (float) $point['latitude'],
            'longitude' => (float) $point['longitude'],
            'heading' => (float) $point['heading'],
            'altitude' => (float) $point['altitude'],
            'orientation' => (string) $point['orientation'],
            'capture_time' => Carbon::parse((string) $point['captureTime']),
            'filename' => (string) $point['filename'],
            'device_make' => (string) $point['deviceMake'],
            'device_model' => (string) $point['deviceModel'],
            'resolution' => (string) $point['imageSize'],
            'fov' => (float) $point['fov'],
            'sequence_uuid' => (string) $point['sequenceUuid'],
            'uploaded_hash' => $uploadedHash,
            'photo_uuid' => Str::random(22).time(),
            'organization_key' => $organizationKey,
            'project_key' => $projectKey,
            'anomaly' => (bool) $point['anomaly'],
            'roll' => (float) $point['roll'],
            'pitch' => (float) $point['pitch'],
            'yaw' => (float) $point['yaw'],
            'car_speed' => $this->nullableFloat($point['car_speed'] ?? $point['carSpeed'] ?? null),
            'vfov' => $this->nullableFloat($point['vfov'] ?? null),
            'focalLength' => $this->nullableFloat($point['focalLength'] ?? null),
            'focalLength35' => $this->nullableFloat($point['focalLength35'] ?? null),
            'gyroscope' => $this->nullableJson($point['gyroscope'] ?? null),
            'acceleration' => $this->nullableJson($point['acceleration'] ?? $point['accelerometer'] ?? null),
            'velocity' => $this->nullableJson($point['velocity'] ?? null),
            'accuracy_level' => $this->nullableFloat($point['accuracy_level'] ?? null),
            'capture_address' => $this->blankToNull($point['capture_address'] ?? null),
            'source' => $this->blankToNull($point['source'] ?? null),
            'sourceUser' => $this->blankToNull($point['sourceUser'] ?? null),
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function sequenceDetailRow(
        array $summary,
        string $sequenceUuid,
        int $createdById,
        ?string $organizationKey,
        ?string $projectKey,
        Carbon $now,
    ): array {
        return [
            'created_at' => $now,
            'created_by_id' => $createdById,
            'updated_at' => $now,
            'updated_by_id' => $createdById,
            'deleted_at' => null,
            'sequence_uuid' => $sequenceUuid,
            'count' => (int) ($summary['count'] ?? $summary['total_images'] ?? 0),
            'size' => round((float) ($summary['size'] ?? 0), 2),
            'anomaly' => false,
            'organization_key' => $organizationKey,
            'project_key' => $projectKey,
            'last_status' => 'uploaded',
            'status' => null,
            'message' => null,
            'start_address' => $this->blankToNull($summary['start_address'] ?? $summary['capture_address'] ?? null),
            'group_key' => $this->blankToNull($summary['group_key'] ?? null) ?? $sequenceUuid,
            'device_type' => $this->blankToNull($summary['device_type'] ?? null),
        ];
    }

    private function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function nullableJson(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function parseImageSize(mixed $imageSize): array
    {
        if (! is_string($imageSize) || ! str_contains($imageSize, 'x')) {
            return [null, null];
        }

        [$width, $height] = array_map('intval', explode('x', $imageSize, 2));

        return [$width > 0 ? $width : null, $height > 0 ? $height : null];
    }
}

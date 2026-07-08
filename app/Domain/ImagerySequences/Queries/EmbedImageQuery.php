<?php

namespace App\Domain\ImagerySequences\Queries;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EmbedImageQuery
{
    public function get(string $sequenceUuid): ?array
    {
        $connection = DB::connection(config('mapilio.legacy_database_connection'));

        $entries = $connection
            ->table('default_mapilio_imagery')
            ->select([
                'photo_uuid',
                'created_by_id',
                'id',
                'capture_time',
                'filename',
                'latitude',
                'longitude',
                'uploaded_hash',
                'sequence_uuid',
                'heading',
                'resolution',
                'fov',
                'vfov',
            ])
            ->where('sequence_uuid', $sequenceUuid)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => $this->mapEntry($row))
            ->all();

        if ($entries === []) {
            return null;
        }

        $info = $connection
            ->table('default_mapilio_sequence_detail')
            ->select(['sequence_uuid', 'start_address'])
            ->where('sequence_uuid', $sequenceUuid)
            ->whereNull('deleted_at')
            ->first();

        return [
            'info' => $info === null ? null : [
                'sequence_uuid' => $info->sequence_uuid === null ? null : (string) $info->sequence_uuid,
                'start_address' => $info->start_address === null ? null : (string) $info->start_address,
            ],
            'entries' => $entries,
        ];
    }

    private function mapEntry(object $row): array
    {
        return [
            'photo_uuid' => $row->photo_uuid === null ? null : (string) $row->photo_uuid,
            'created_by_id' => $row->created_by_id === null ? null : (int) $row->created_by_id,
            'id' => (int) $row->id,
            'capture_time' => $this->formatTimestamp($row->capture_time),
            'filename' => $row->filename === null ? null : (string) $row->filename,
            'latitude' => $row->latitude === null ? null : (string) $row->latitude,
            'longitude' => $row->longitude === null ? null : (string) $row->longitude,
            'uploaded_hash' => $row->uploaded_hash === null ? null : (string) $row->uploaded_hash,
            'sequence_uuid' => $row->sequence_uuid === null ? null : (string) $row->sequence_uuid,
            'heading' => $row->heading === null ? null : (int) $row->heading,
            'resolution' => $row->resolution === null ? null : (string) $row->resolution,
            'fov' => $row->fov === null ? null : (string) $row->fov,
            'vfov' => $row->vfov === null ? null : (string) $row->vfov,
        ];
    }

    private function formatTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }
}

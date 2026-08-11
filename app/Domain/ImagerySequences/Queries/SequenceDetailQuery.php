<?php

namespace App\Domain\ImagerySequences\Queries;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** @phpstan-type SequenceImage array{id: int, heading: int|null, filename: string|null, uploaded_hash: string|null, fov: string|null, vfov: string|null, pitch: string|null, capture_time: string|null, created_by_id: int|null, resolution: string|null} */
class SequenceDetailQuery
{
    /** @return list<SequenceImage> */
    public function get(string $sequenceUuid): array
    {
        return DB::connection(config('mapilio.legacy_database_connection'))
            ->table('default_mapilio_imagery')
            ->select([
                'id',
                'heading',
                'filename',
                'uploaded_hash',
                'fov',
                'vfov',
                'pitch',
                'capture_time',
                'created_by_id',
                'resolution',
            ])
            ->where('sequence_uuid', $sequenceUuid)
            ->where('anomaly', false)
            ->whereNull('deleted_at')
            ->orderBy('capture_time')
            ->get()
            ->map(fn (object $row): array => $this->mapRow($row))
            ->all();
    }

    /** @return SequenceImage */
    private function mapRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'heading' => $row->heading === null ? null : (int) $row->heading,
            'filename' => $row->filename === null ? null : (string) $row->filename,
            'uploaded_hash' => $row->uploaded_hash === null ? null : (string) $row->uploaded_hash,
            'fov' => $row->fov === null ? null : (string) $row->fov,
            'vfov' => $row->vfov === null ? null : (string) $row->vfov,
            'pitch' => $row->pitch === null ? null : (string) $row->pitch,
            'capture_time' => $this->formatTimestamp($row->capture_time),
            'created_by_id' => $row->created_by_id === null ? null : (int) $row->created_by_id,
            'resolution' => $row->resolution === null ? null : (string) $row->resolution,
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

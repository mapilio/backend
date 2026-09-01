<?php

namespace App\Domain\ImagerySequences\Queries;

use App\Support\Http\BoundedRead\PayloadTooLargeException;
use App\Support\Http\BoundedRead\PublicReadBounds;
use App\Support\Http\Pagination\PaginationParameters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/** @phpstan-type SequenceImage array{id: int, heading: int|null, filename: string|null, uploaded_hash: string|null, fov: string|null, vfov: string|null, pitch: string|null, capture_time: string|null, created_by_id: int|null, resolution: string|null} */
class SequenceDetailQuery
{
    /** @return list<SequenceImage> */
    public function get(string $sequenceUuid): array
    {
        $query = DB::connection(config('mapilio.legacy_database_connection'))
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
            ->whereNull('deleted_at');

        if (PublicReadBounds::enforced()) {
            $query->limit(PublicReadBounds::maxRows(PublicReadBounds::SEQUENCE) + 1);
        }

        $rows = $query->orderBy('capture_time')->get();

        if (PublicReadBounds::enforced() && $rows->count() > PublicReadBounds::maxRows(PublicReadBounds::SEQUENCE)) {
            throw new PayloadTooLargeException('Sequence detail row limit exceeded.');
        }

        return $this->mapRows($rows, PublicReadBounds::enforced());
    }

    /** @return array{rows: list<SequenceImage>, has_more: bool} */
    public function getPage(string $sequenceUuid, PaginationParameters $pagination): array
    {
        $offset = $pagination->offset;

        if ($offset === null) {
            return ['rows' => [], 'has_more' => false];
        }

        $maxRows = PublicReadBounds::maxRows(PublicReadBounds::SEQUENCE);
        $remainingRows = $maxRows - $offset;
        $query = DB::connection(config('mapilio.legacy_database_connection'))
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
            ->orderBy('id')
            ->offset($offset)
            ->limit(min($pagination->perPage + 1, $remainingRows));

        $rows = $query->get();
        $hasMore = $offset + $pagination->perPage < $maxRows
            && $rows->count() > $pagination->perPage;
        $pageRows = $rows->take($pagination->perPage);

        return [
            'rows' => $this->mapRows($pageRows, true),
            'has_more' => $hasMore,
        ];
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return list<SequenceImage>
     */
    private function mapRows(Collection $rows, bool $bounded): array
    {
        $mapped = [];
        $encodedBytes = 0;

        foreach ($rows as $row) {
            $item = $this->mapRow($row);

            if ($bounded) {
                $encodedBytes = PublicReadBounds::nextEncodedBytes($item, $encodedBytes);
            }

            $mapped[] = $item;
        }

        return $mapped;
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

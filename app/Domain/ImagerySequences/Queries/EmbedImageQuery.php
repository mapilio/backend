<?php

namespace App\Domain\ImagerySequences\Queries;

use App\Support\Http\BoundedRead\PayloadTooLargeException;
use App\Support\Http\BoundedRead\PublicReadBounds;
use App\Support\Http\Pagination\PaginationParameters;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * @phpstan-type EmbedInfo array{sequence_uuid: string|null, start_address: string|null}
 * @phpstan-type EmbedEntry array{photo_uuid: string|null, created_by_id: int|null, id: int, capture_time: string|null, filename: string|null, latitude: string|null, longitude: string|null, uploaded_hash: string|null, sequence_uuid: string|null, heading: int|null, resolution: string|null, fov: string|null, vfov: string|null}
 * @phpstan-type EmbedPayload array{info: EmbedInfo|null, entries: list<EmbedEntry>}
 */
class EmbedImageQuery
{
    /** @return EmbedPayload|null */
    public function get(string $sequenceUuid): ?array
    {
        $connection = DB::connection(config('mapilio.legacy_database_connection'));

        $query = $connection
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
            ->whereNull('deleted_at');

        if (PublicReadBounds::enforced()) {
            $query->limit(PublicReadBounds::maxRows(PublicReadBounds::EMBED) + 1);
        }

        $rawEntries = $query->orderBy('id')->get();

        if ($rawEntries->isEmpty()) {
            return null;
        }

        if (PublicReadBounds::enforced() && $rawEntries->count() > PublicReadBounds::maxRows(PublicReadBounds::EMBED)) {
            throw new PayloadTooLargeException('Embed row limit exceeded.');
        }

        $entries = $this->mapEntries($rawEntries, PublicReadBounds::enforced());

        return $this->payload($connection, $sequenceUuid, $entries);
    }

    /** @return array{payload: EmbedPayload|null, has_more: bool} */
    public function getPage(string $sequenceUuid, PaginationParameters $pagination): array
    {
        $connection = DB::connection(config('mapilio.legacy_database_connection'));
        $maxRows = PublicReadBounds::maxRows(PublicReadBounds::EMBED);
        $offset = $pagination->offset;

        if ($offset === null) {
            $hasEntries = $connection
                ->table('default_mapilio_imagery')
                ->where('sequence_uuid', $sequenceUuid)
                ->whereNull('deleted_at')
                ->exists();

            if (! $hasEntries) {
                return ['payload' => null, 'has_more' => false];
            }

            return [
                'payload' => [
                    'info' => $this->info($connection, $sequenceUuid),
                    'entries' => [],
                ],
                'has_more' => false,
            ];
        }

        $remainingRows = $maxRows - $offset;
        $rawEntries = $connection
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
            ->offset($offset)
            ->limit(min($pagination->perPage + 1, $remainingRows))
            ->get();

        if ($rawEntries->isEmpty()) {
            $hasEntries = $connection
                ->table('default_mapilio_imagery')
                ->where('sequence_uuid', $sequenceUuid)
                ->whereNull('deleted_at')
                ->exists();

            if (! $hasEntries) {
                return ['payload' => null, 'has_more' => false];
            }
        }

        $info = $this->info($connection, $sequenceUuid);
        $hasMore = $remainingRows > $pagination->perPage
            && $rawEntries->count() > $pagination->perPage;

        return [
            'payload' => [
                'info' => $info,
                'entries' => $this->mapEntries($rawEntries->take($pagination->perPage), true),
            ],
            'has_more' => $hasMore,
        ];
    }

    /**
     * @param  Collection<int, stdClass>  $rawEntries
     * @return list<EmbedEntry>
     */
    private function mapEntries(Collection $rawEntries, bool $bounded): array
    {
        $entries = [];
        $encodedBytes = 0;

        foreach ($rawEntries as $row) {
            $entry = $this->mapEntry($row);

            if ($bounded) {
                $encodedBytes = PublicReadBounds::nextEncodedBytes($entry, $encodedBytes);
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param  list<EmbedEntry>  $entries
     * @return EmbedPayload
     */
    private function payload(Connection $connection, string $sequenceUuid, array $entries): array
    {
        return [
            'info' => $this->info($connection, $sequenceUuid),
            'entries' => $entries,
        ];
    }

    /** @return EmbedInfo|null */
    private function info(Connection $connection, string $sequenceUuid): ?array
    {
        $info = $connection
            ->table('default_mapilio_sequence_detail')
            ->select(['sequence_uuid', 'start_address'])
            ->where('sequence_uuid', $sequenceUuid)
            ->whereNull('deleted_at')
            ->first();

        return $info === null ? null : [
            'sequence_uuid' => $info->sequence_uuid === null ? null : (string) $info->sequence_uuid,
            'start_address' => $info->start_address === null ? null : (string) $info->start_address,
        ];
    }

    /** @return EmbedEntry */
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

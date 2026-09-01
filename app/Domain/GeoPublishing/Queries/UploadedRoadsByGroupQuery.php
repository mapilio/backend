<?php

namespace App\Domain\GeoPublishing\Queries;

use App\Support\Database\LegacyDatabase;
use App\Support\Http\BoundedRead\PayloadTooLargeException;
use App\Support\Http\BoundedRead\PublicReadBounds;
use App\Support\Http\Pagination\PaginationParameters;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/** @phpstan-type UploadedRoad array{sequence_uuid: string|null, linefeature: string|null} */
class UploadedRoadsByGroupQuery
{
    /** @return list<UploadedRoad> */
    public function get(string $groupKey): array
    {
        $connection = LegacyDatabase::connection();

        $query = $connection
            ->table('default_mapilio_road as roads')
            ->select([
                'roads.sequence_uuid',
                DB::raw($this->lineFeatureExpression($connection)),
            ])
            ->whereNull('roads.deleted_at')
            ->whereIn('roads.sequence_uuid', function ($query) use ($groupKey): void {
                $query
                    ->select('sequence_uuid')
                    ->from('default_mapilio_sequence_detail')
                    ->where('group_key', $groupKey);
            });

        if (PublicReadBounds::enforced()) {
            $query->limit(PublicReadBounds::maxRows(PublicReadBounds::ROADS) + 1);
        }

        $rows = $query->orderBy('roads.id')->get();

        if (PublicReadBounds::enforced() && $rows->count() > PublicReadBounds::maxRows(PublicReadBounds::ROADS)) {
            throw new PayloadTooLargeException('Uploaded roads row limit exceeded.');
        }

        return $this->mapRows($rows, PublicReadBounds::enforced());
    }

    /** @return array{rows: list<UploadedRoad>, has_more: bool} */
    public function getPage(string $groupKey, PaginationParameters $pagination): array
    {
        $offset = $pagination->offset;

        if ($offset === null) {
            return ['rows' => [], 'has_more' => false];
        }

        $maxRows = PublicReadBounds::maxRows(PublicReadBounds::ROADS);
        $remainingRows = $maxRows - $offset;
        $connection = LegacyDatabase::connection();
        $rows = $connection
            ->table('default_mapilio_road as roads')
            ->select([
                'roads.sequence_uuid',
                DB::raw($this->lineFeatureExpression($connection)),
            ])
            ->whereNull('roads.deleted_at')
            ->whereIn('roads.sequence_uuid', function ($query) use ($groupKey): void {
                $query
                    ->select('sequence_uuid')
                    ->from('default_mapilio_sequence_detail')
                    ->where('group_key', $groupKey);
            })
            ->orderBy('roads.id')
            ->offset($offset)
            ->limit(min($pagination->perPage + 1, $remainingRows))
            ->get();

        return [
            'rows' => $this->mapRows($rows->take($pagination->perPage), true),
            'has_more' => $offset + $pagination->perPage < $maxRows
                && $rows->count() > $pagination->perPage,
        ];
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return list<UploadedRoad>
     */
    private function mapRows(Collection $rows, bool $bounded): array
    {
        $mapped = [];
        $encodedBytes = 0;

        foreach ($rows as $row) {
            $item = [
                'sequence_uuid' => $row->sequence_uuid === null ? null : (string) $row->sequence_uuid,
                'linefeature' => $row->linefeature === null ? null : (string) $row->linefeature,
            ];

            if ($bounded) {
                $encodedBytes = PublicReadBounds::nextEncodedBytes($item, $encodedBytes);
            }

            $mapped[] = $item;
        }

        return $mapped;
    }

    private function lineFeatureExpression(Connection $connection): string
    {
        if ($connection->getDriverName() === 'pgsql') {
            return 'ST_AsGeoJSON(roads.geom) as linefeature';
        }

        return 'roads.linefeature as linefeature';
    }
}

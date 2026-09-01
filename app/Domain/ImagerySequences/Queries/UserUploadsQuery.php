<?php

namespace App\Domain\ImagerySequences\Queries;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type UploadSummaryRow array{total: int, uploaded_hash: mixed, capture_time: mixed, cover_photo: mixed, group_key: mixed, start_address: mixed, last_status: mixed}
 * @phpstan-type PaginationLink array{url: string|null, label: string, active: bool}
 * @phpstan-type UploadPagination array{current_page: int, first_page_url: string, from: int|null, last_page: int, last_page_url: string, links: list<PaginationLink>, next_page_url: string|null, path: string, per_page: int, prev_page_url: string|null, to: int|null, total: int}
 * @phpstan-type UploadSummaryEnvelope array{data: list<UploadSummaryRow>|null, pagination: UploadPagination}
 */
class UserUploadsQuery
{
    /** @return UploadSummaryEnvelope */
    public function get(int $userId, Request $request): array
    {
        $connection = DB::connection(config('mapilio.legacy_database_connection'));
        $limit = $this->limit($request);
        $page = max(1, (int) $request->query('page', 1));
        $offset = ($page - 1) * $limit;

        if ($connection->getDriverName() === 'pgsql') {
            [$rows, $total] = $this->postgresRows($connection, $userId, $limit, $offset);
        } else {
            [$rows, $total] = $this->portableRows($connection, $userId, $limit, $offset);
        }

        return [
            'data' => $rows === [] ? null : $rows,
            'pagination' => $this->pagination($request, '/api/user-uploads-v2', $page, $limit, $total, count($rows)),
        ];
    }

    /**
     * @return array{0: list<UploadSummaryRow>, 1: int}
     */
    private function postgresRows(ConnectionInterface $connection, int $userId, int $limit, int $offset): array
    {
        $entriesSql = 'select imagery.id as imagery_id,
                imagery.uploaded_hash,
                imagery.capture_time,
                imagery.filename,
                group_table.group_key
            from default_mapilio_imagery as imagery
            left join (
                select sequence_uuid, group_key
                from default_mapilio_sequence_detail
                where anomaly is false
                  and created_by_id = ?
                  and deleted_at is null
            ) as group_table on group_table.sequence_uuid = imagery.sequence_uuid
            where imagery.anomaly is false
              and imagery.created_by_id = ?
              and imagery.deleted_at is null';

        $groupedSql = 'select count(*) as total,
                (array_agg(uploaded_hash order by capture_time desc nulls last, imagery_id asc))[1] as uploaded_hash,
                (array_agg(capture_time order by capture_time desc nulls last, imagery_id asc))[1] as capture_time,
                (array_agg(filename order by capture_time desc nulls last, imagery_id asc))[1] as cover_photo,
                (array_agg(imagery_id order by capture_time desc nulls last, imagery_id asc))[1] as representative_id,
                group_key
            from entries
            group by group_key';

        $selectSql = 'with entries as ('.$entriesSql.'),
            grouped as ('.$groupedSql.'),
            paged as (
                select grouped.*, count(*) over() as pagination_total
                from grouped
                order by capture_time desc nulls last, representative_id asc
                limit ? offset ?
            ),
            page_group_keys as (
                select group_key
                from paged
                group by group_key
            ),
            page_metadata as (
                select detail.group_key,
                    (array_agg(detail.start_address order by detail.id asc) filter (where detail.start_address is not null))[1] as start_address,
                    (array_agg(detail.last_status order by detail.id desc))[1] as last_status
                from default_mapilio_sequence_detail as detail
                inner join page_group_keys on page_group_keys.group_key = detail.group_key
                group by detail.group_key
            )
            select paged.total,
                paged.uploaded_hash,
                paged.capture_time,
                paged.cover_photo,
                paged.group_key,
                page_metadata.start_address,
                page_metadata.last_status,
                paged.pagination_total
            from paged
            left join page_metadata on page_metadata.group_key = paged.group_key
            order by paged.capture_time desc nulls last, paged.representative_id asc';

        $rows = $connection->select($selectSql, [$userId, $userId, $limit, $offset]);

        if ($rows === []) {
            $fallbackSql = 'with entries as ('.$entriesSql.'),
                grouped as ('.$groupedSql.')
                select pagination_total as count
                from (select count(*) over() as pagination_total
                    from grouped
                    limit 1) as counted';
            $fallback = $connection->select($fallbackSql, [$userId, $userId]);

            return [[], (int) ($fallback[0]->count ?? 0)];
        }

        return [
            array_map(fn (object $row): array => $this->row($row), $rows),
            (int) ($rows[0]->pagination_total ?? 0),
        ];
    }

    /**
     * @return array{0: list<UploadSummaryRow>, 1: int}
     */
    private function portableRows(ConnectionInterface $connection, int $userId, int $limit, int $offset): array
    {
        $allRows = $connection->table('default_mapilio_imagery as imagery')
            ->leftJoin('default_mapilio_sequence_detail as detail', function ($join): void {
                $join->on('detail.sequence_uuid', '=', 'imagery.sequence_uuid')
                    ->where('detail.anomaly', false)
                    ->whereNull('detail.deleted_at');
            })
            ->where('imagery.anomaly', false)
            ->where('imagery.created_by_id', $userId)
            ->whereNull('imagery.deleted_at')
            ->select([
                'imagery.uploaded_hash',
                'imagery.capture_time',
                'imagery.filename',
                'detail.group_key',
                'detail.start_address',
                'detail.last_status',
                'detail.id as detail_id',
            ])
            ->orderByDesc('imagery.capture_time')
            ->orderBy('imagery.id')
            ->get()
            ->groupBy('group_key')
            ->map(function ($group): array {
                $first = $group->first();
                $latestDetail = $group
                    ->filter(fn (object $row): bool => $row->last_status !== null)
                    ->sortByDesc('detail_id')
                    ->first();
                $startAddress = $group
                    ->first(fn (object $row): bool => $row->start_address !== null)
                    ?->start_address;

                return [
                    'total' => $group->count(),
                    'uploaded_hash' => $first->uploaded_hash,
                    'capture_time' => $first->capture_time,
                    'cover_photo' => $first->filename,
                    'group_key' => $first->group_key,
                    'start_address' => $startAddress,
                    'last_status' => $latestDetail?->last_status,
                ];
            })
            ->sortByDesc('capture_time')
            ->values();

        return [
            $allRows->slice($offset, $limit)->values()->all(),
            $allRows->count(),
        ];
    }

    /** @return UploadSummaryRow */
    private function row(object $row): array
    {
        return [
            'total' => (int) $row->total,
            'uploaded_hash' => $row->uploaded_hash,
            'capture_time' => $row->capture_time,
            'cover_photo' => $row->cover_photo,
            'group_key' => $row->group_key,
            'start_address' => $row->start_address,
            'last_status' => $row->last_status,
        ];
    }

    private function limit(Request $request): int
    {
        return max(1, min(1000, (int) data_get($request->query(), 'options.limit', 15)));
    }

    /** @return UploadPagination */
    private function pagination(Request $request, string $path, int $page, int $limit, int $total, int $rowCount): array
    {
        $lastPage = max(1, (int) ceil($total / $limit));
        $from = $total === 0 || $rowCount === 0 ? null : (($page - 1) * $limit) + 1;

        return [
            'current_page' => $page,
            'first_page_url' => $this->pageUrl($path, $request, 1),
            'from' => $from,
            'last_page' => $lastPage,
            'last_page_url' => $this->pageUrl($path, $request, $lastPage),
            'links' => $this->links($path, $request, $page, $lastPage),
            'next_page_url' => $page < $lastPage ? $this->pageUrl($path, $request, $page + 1) : null,
            'path' => $path,
            'per_page' => $limit,
            'prev_page_url' => $page > 1 ? $this->pageUrl($path, $request, $page - 1) : null,
            'to' => $from === null ? null : $from + $rowCount - 1,
            'total' => $total,
        ];
    }

    /** @return list<PaginationLink> */
    private function links(string $path, Request $request, int $page, int $lastPage): array
    {
        $links = [
            [
                'url' => $page > 1 ? $this->pageUrl($path, $request, $page - 1) : null,
                'label' => '&laquo; Previous',
                'active' => false,
            ],
        ];

        foreach ($this->pageWindow($page, $lastPage) as $item) {
            $links[] = is_int($item)
                ? [
                    'url' => $this->pageUrl($path, $request, $item),
                    'label' => (string) $item,
                    'active' => $item === $page,
                ]
                : [
                    'url' => null,
                    'label' => '...',
                    'active' => false,
                ];
        }

        $links[] = [
            'url' => $page < $lastPage ? $this->pageUrl($path, $request, $page + 1) : null,
            'label' => 'Next &raquo;',
            'active' => false,
        ];

        return $links;
    }

    /**
     * @return list<int|string>
     */
    private function pageWindow(int $page, int $lastPage): array
    {
        if ($lastPage <= 13) {
            return range(1, $lastPage);
        }

        if ($page <= 7) {
            return [...range(1, 10), '...', $lastPage - 1, $lastPage];
        }

        if ($page >= $lastPage - 6) {
            return [1, 2, '...', ...range($lastPage - 9, $lastPage)];
        }

        return [1, 2, '...', ...range($page - 2, $page + 2), '...', $lastPage - 1, $lastPage];
    }

    private function pageUrl(string $path, Request $request, int $page): string
    {
        $query = $request->query();
        $query['page'] = $page;

        return $path.'?'.http_build_query($query);
    }
}

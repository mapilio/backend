<?php

namespace App\Domain\ImagerySequences\Queries;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class UserUploadDetailsQuery
{
    public function get(int $userId, string $groupKey, Request $request): array
    {
        $connection = DB::connection(config('mapilio.legacy_database_connection'));
        $limit = $this->limit($request);
        $page = max(1, (int) $request->query('page', 1));
        $offset = ($page - 1) * $limit;

        $baseQuery = $this->baseQuery($connection, $userId, $groupKey);
        $total = (clone $baseQuery)->count();

        $rows = (clone $baseQuery)
            ->select([
                'imagery.filename',
                'detail.last_status',
                'imagery.sequence_uuid',
                'imagery.id',
                'imagery.uploaded_hash as img_code',
                'imagery.latitude',
                'imagery.longitude',
                'imagery.heading',
                'imagery.created_by_id',
                'imagery.created_at',
                'imagery.capture_time',
            ])
            ->orderBy('imagery.id')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(fn (object $row): array => $this->row($row))
            ->all();

        if ($rows === []) {
            return ['data' => null];
        }

        return [
            'data' => $rows,
            'pagination' => $this->pagination($request, '/api/user-uploads-detail-v2', $page, $limit, $total, count($rows)),
        ];
    }

    private function baseQuery(ConnectionInterface $connection, int $userId, string $groupKey)
    {
        return $connection
            ->table('default_mapilio_imagery as imagery')
            ->leftJoin('default_mapilio_sequence_detail as detail', function ($join): void {
                $join->on('detail.sequence_uuid', '=', 'imagery.sequence_uuid');
            })
            ->where('detail.group_key', $groupKey)
            ->where('imagery.anomaly', false)
            ->where('imagery.created_by_id', $userId);
    }

    private function row(object $row): array
    {
        return [
            'filename' => $row->filename === null ? null : (string) $row->filename,
            'last_status' => $row->last_status === null ? null : (string) $row->last_status,
            'sequence_uuid' => $row->sequence_uuid === null ? null : (string) $row->sequence_uuid,
            'id' => (int) $row->id,
            'img_code' => $row->img_code === null ? null : (string) $row->img_code,
            'latitude' => $row->latitude === null ? null : (string) $row->latitude,
            'longitude' => $row->longitude === null ? null : (string) $row->longitude,
            'heading' => $row->heading === null ? null : (float) $row->heading,
            'created_by_id' => $row->created_by_id === null ? null : (int) $row->created_by_id,
            'created_at' => $this->formatIsoTimestamp($row->created_at),
            'capture_time' => $this->formatTimestamp($row->capture_time),
        ];
    }

    private function formatIsoTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private function formatTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    private function limit(Request $request): int
    {
        return max(1, (int) data_get($request->query(), 'options.limit', 15));
    }

    private function pagination(Request $request, string $path, int $page, int $limit, int $total, int $rowCount): array
    {
        $lastPage = max(1, (int) ceil($total / $limit));
        $from = (($page - 1) * $limit) + 1;

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
            'to' => $from + $rowCount - 1,
            'total' => $total,
        ];
    }

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

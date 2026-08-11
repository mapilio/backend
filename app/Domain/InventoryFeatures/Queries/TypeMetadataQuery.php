<?php

namespace App\Domain\InventoryFeatures\Queries;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type MetadataRow array<string, int|string|null>
 * @phpstan-type PaginationLink array{url: string|null, label: string, active: bool}
 * @phpstan-type Pagination array{current_page: int, first_page_url: string, from: int, last_page: int, last_page_url: string, links: list<PaginationLink>, next_page_url: string|null, path: string, per_page: int, prev_page_url: string|null, to: int, total: int}
 * @phpstan-type MetadataEnvelope array{data: list<MetadataRow>|null, pagination?: Pagination}
 */
class TypeMetadataQuery
{
    private const DATA_PAGE_SIZE = 100;

    private const LEGACY_PAGINATION_SIZE = 15;

    /** @return MetadataEnvelope */
    public function types(Request $request): array
    {
        return $this->paginated(
            $request,
            'default_types_type',
            'default_types_type_translations',
            ['code', 'group_id', 'icon'],
            '/api/get-types',
            true,
        );
    }

    /** @return MetadataEnvelope */
    public function groups(Request $request): array
    {
        return $this->paginated(
            $request,
            'default_types_groups',
            'default_types_groups_translations',
            ['slug'],
            '/api/get-groups',
        );
    }

    /**
     * @param  list<string>  $extraColumns
     * @return MetadataEnvelope
     */
    private function paginated(
        Request $request,
        string $table,
        string $translationTable,
        array $extraColumns,
        string $path,
        bool $legacyTypeOrdering = false,
    ): array {
        $connection = DB::connection(config('mapilio.legacy_database_connection'));
        $page = max(1, (int) $request->query('page', 1));
        $offset = ($page - 1) * self::DATA_PAGE_SIZE;
        $locale = $this->locale($request);

        $total = $connection->table($table)
            ->whereNull('deleted_at')
            ->count();

        $columns = collect([
            "$table.id",
            "$table.sort_order",
            "$table.created_at",
            "$table.created_by_id",
            "$table.updated_at",
            "$table.updated_by_id",
            "$table.deleted_at",
        ])
            ->merge(array_map(fn (string $column): string => "$table.$column", $extraColumns))
            ->push("$translationTable.name")
            ->all();

        $query = $connection->table($table)
            ->leftJoin($translationTable, function ($join) use ($table, $translationTable, $locale): void {
                $join->on("$translationTable.entry_id", '=', "$table.id")
                    ->where("$translationTable.locale", '=', $locale);
            })
            ->select($columns)
            ->whereNull("$table.deleted_at");

        if ($legacyTypeOrdering) {
            $query->orderByRaw("CASE WHEN $table.code = ? THEN 464.5 ELSE $table.id END ASC", ['end-of-pedestrians']);
        } else {
            $query->orderBy("$table.sort_order");
        }

        $rows = $query
            ->limit(self::DATA_PAGE_SIZE)
            ->offset($offset)
            ->get()
            ->map(fn (object $row): array => $this->mapRow($row, $extraColumns))
            ->all();

        if ($rows === []) {
            return ['data' => null];
        }

        return [
            'data' => $rows,
            'pagination' => $this->pagination($request, $path, $page, $total, count($rows)),
        ];
    }

    private function locale(Request $request): string
    {
        $locale = $request->query('locale', app()->getLocale());

        return is_string($locale) && $locale !== '' ? $locale : 'en';
    }

    /**
     * @param  list<string>  $extraColumns
     * @return MetadataRow
     */
    private function mapRow(object $row, array $extraColumns): array
    {
        $mapped = [
            'id' => (int) $row->id,
            'sort_order' => $row->sort_order === null ? null : (int) $row->sort_order,
            'created_at' => $this->timestamp($row->created_at),
            'created_by_id' => $row->created_by_id === null ? null : (int) $row->created_by_id,
            'updated_at' => $this->timestamp($row->updated_at),
            'updated_by_id' => $row->updated_by_id === null ? null : (int) $row->updated_by_id,
            'deleted_at' => $this->timestamp($row->deleted_at),
        ];

        foreach ($extraColumns as $column) {
            $mapped[$column] = $column === 'group_id' && $row->{$column} !== null
                ? (int) $row->{$column}
                : $row->{$column};
        }

        $mapped['name'] = $row->name;

        return $mapped;
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return date('Y-m-d\TH:i:s.000000\Z', strtotime((string) $value));
    }

    /** @return Pagination */
    private function pagination(Request $request, string $path, int $page, int $total, int $rowCount): array
    {
        $lastPage = (int) ceil($total / self::LEGACY_PAGINATION_SIZE);
        $from = (($page - 1) * self::LEGACY_PAGINATION_SIZE) + 1;

        return [
            'current_page' => $page,
            'first_page_url' => $this->pageUrl($path, $request, 1),
            'from' => $from,
            'last_page' => $lastPage,
            'last_page_url' => $this->pageUrl($path, $request, $lastPage),
            'links' => $this->links($path, $request, $page, $lastPage),
            'next_page_url' => $page < $lastPage ? $this->pageUrl($path, $request, $page + 1) : null,
            'path' => $path,
            'per_page' => self::LEGACY_PAGINATION_SIZE,
            'prev_page_url' => $page > 1 ? $this->pageUrl($path, $request, $page - 1) : null,
            'to' => $from + $rowCount - 1,
            'total' => $total,
        ];
    }

    /**
     * @return list<array{url: string|null, label: string, active: bool}>
     */
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
            if ($item === '...') {
                $links[] = [
                    'url' => null,
                    'label' => '...',
                    'active' => false,
                ];

                continue;
            }

            $links[] = [
                'url' => $this->pageUrl($path, $request, $item),
                'label' => (string) $item,
                'active' => $item === $page,
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
        if ($lastPage <= 12) {
            return range(1, $lastPage);
        }

        if ($page <= 10) {
            return array_merge(range(1, 10), ['...'], [$lastPage - 1, $lastPage]);
        }

        if ($page >= $lastPage - 9) {
            return array_merge([1, 2], ['...'], range($lastPage - 9, $lastPage));
        }

        return [1, 2, '...', $page - 1, $page, $page + 1, '...', $lastPage - 1, $lastPage];
    }

    private function pageUrl(string $path, Request $request, int $page): string
    {
        $query = $request->query();
        $query['page'] = $page;

        return $path.'?'.http_build_query($query);
    }
}

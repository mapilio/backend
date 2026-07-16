<?php

namespace App\Domain\BillingCatalog\Queries;

use App\Support\Database\LegacyDatabase;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

class BillingPlanQuery
{
    private const DATA_PAGE_SIZE = 100;

    private const LEGACY_PAGINATION_SIZE = 15;

    public function packages(Request $request): array
    {
        return $this->paginated(
            $request,
            'default_billing_package',
            'default_billing_package_translations',
            '/api/package-list',
            fn (object $row): array => $this->mapPackage($row, $request->getSchemeAndHttpHost()),
            [
                'base.km_price',
                'base.currency',
                'base.interval_period',
                'base.image_id',
                'base.hover_image_id',
            ],
        );
    }

    public function hosting(Request $request): array
    {
        return $this->paginated(
            $request,
            'default_billing_hosting',
            'default_billing_hosting_translations',
            '/api/hosting-list',
            fn (object $row): array => $this->mapHosting($row),
            [
                'base.price',
                'base.currency',
                'base.image_count',
            ],
        );
    }

    /**
     * @param  callable(object): array<string, mixed>  $mapper
     * @param  list<string>  $columns
     */
    private function paginated(
        Request $request,
        string $table,
        string $translationTable,
        string $path,
        callable $mapper,
        array $columns,
    ): array {
        $connection = LegacyDatabase::connection();
        $page = max(1, (int) $request->query('page', 1));
        $locale = $this->locale($request);

        $baseQuery = $connection->table($table.' as base')
            ->leftJoin($translationTable.' as translations', function ($join) use ($locale): void {
                $join->on('translations.entry_id', '=', 'base.id')
                    ->where('translations.locale', '=', $locale);
            })
            ->whereNull('base.deleted_at');

        $total = (clone $baseQuery)->count();

        $rows = $baseQuery
            ->select(array_merge([
                'base.id',
                'base.sort_order',
                'base.created_at',
                'base.created_by_id',
                'base.updated_at',
                'base.updated_by_id',
                'base.deleted_at',
                'translations.name',
            ], $columns))
            ->when($this->isSqlite($baseQuery), fn (Builder $query): Builder => $query->orderBy('base.rowid'))
            ->limit(self::DATA_PAGE_SIZE)
            ->offset(($page - 1) * self::DATA_PAGE_SIZE)
            ->get()
            ->map($mapper)
            ->all();

        if ($rows === []) {
            return ['data' => null];
        }

        return [
            'data' => $rows,
            'pagination' => $this->pagination($request, $path, $page, $total, count($rows)),
        ];
    }

    private function isSqlite(Builder $query): bool
    {
        $connection = $query->getConnection();

        return $connection instanceof Connection && $connection->getDriverName() === 'sqlite';
    }

    private function mapPackage(object $row, string $assetRoot): array
    {
        return [
            'id' => (int) $row->id,
            'sort_order' => $row->sort_order === null ? null : (int) $row->sort_order,
            'created_at' => $this->timestamp($row->created_at),
            'created_by_id' => $row->created_by_id === null ? null : (int) $row->created_by_id,
            'updated_at' => $this->timestamp($row->updated_at),
            'updated_by_id' => $row->updated_by_id === null ? null : (int) $row->updated_by_id,
            'deleted_at' => $this->timestamp($row->deleted_at),
            'km_price' => $this->numericString($row->km_price),
            'currency' => $row->currency,
            'interval_period' => $row->interval_period,
            'image_id' => $row->image_id === null ? null : (int) $row->image_id,
            'hover_image_id' => $row->hover_image_id === null ? null : (int) $row->hover_image_id,
            'image_url' => $row->image_id === null ? null : $assetRoot,
            'hover_image_url' => $row->hover_image_id === null ? null : $assetRoot,
            'name' => $row->name,
        ];
    }

    private function mapHosting(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'sort_order' => $row->sort_order === null ? null : (int) $row->sort_order,
            'created_at' => $this->timestamp($row->created_at),
            'created_by_id' => $row->created_by_id === null ? null : (int) $row->created_by_id,
            'updated_at' => $this->timestamp($row->updated_at),
            'updated_by_id' => $row->updated_by_id === null ? null : (int) $row->updated_by_id,
            'deleted_at' => $this->timestamp($row->deleted_at),
            'price' => $this->numericString($row->price),
            'currency' => $row->currency,
            'image_count' => $row->image_count === null ? null : (int) $row->image_count,
            'name' => $row->name,
        ];
    }

    private function locale(Request $request): string
    {
        $locale = $request->query('locale', app()->getLocale());

        return is_string($locale) && $locale !== '' ? $locale : 'en';
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return date('Y-m-d\TH:i:s.000000\Z', strtotime((string) $value));
    }

    private function numericString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $formatted = rtrim(rtrim(sprintf('%.10F', (float) $value), '0'), '.');

        return $formatted === '-0' ? '0' : $formatted;
    }

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
     * @return list<int>
     */
    private function pageWindow(int $page, int $lastPage): array
    {
        if ($lastPage <= 0) {
            return [];
        }

        return range(1, $lastPage);
    }

    private function pageUrl(string $path, Request $request, int $page): string
    {
        $query = $request->query();
        $query['page'] = $page;

        return $path.'?'.http_build_query($query);
    }
}

<?php

namespace App\Domain\PublicContent\Queries;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type CatalogEntry array{properties: array{name: string|null, year: string|null}, thumbnails: non-empty-list<string|null>, images: non-empty-list<string|null>}
 * @phpstan-type CatalogResponse array{status: true, data: array<int, CatalogEntry>}
 */
class CatalogQuery
{
    /** @return CatalogResponse */
    public function get(Request $request): array
    {
        $connection = DB::connection(config('mapilio.legacy_database_connection'));
        $locale = $this->locale($request);
        $assetRoot = $request->getSchemeAndHttpHost();

        $entries = $connection->table('default_catalog_catalog as catalog')
            ->leftJoin('default_catalog_catalog_translations as translations', function ($join) use ($locale): void {
                $join->on('translations.entry_id', '=', 'catalog.id')
                    ->where('translations.locale', '=', $locale);
            })
            ->select([
                'catalog.id',
                'catalog.sort_order',
                'catalog.catalog_year',
                'translations.catalog_name',
            ])
            ->orderBy('catalog.sort_order')
            ->orderBy('catalog.id')
            ->get();

        $fileCounts = $connection->table('default_catalog_catalog_catalog_images')
            ->selectRaw('entry_id, count(*) as total')
            ->whereIn('entry_id', $entries->pluck('id')->all())
            ->groupBy('entry_id')
            ->pluck('total', 'entry_id');

        /** @var array<int, CatalogEntry> $data */
        $data = [];

        foreach ($entries as $entry) {
            $fileCount = (int) ($fileCounts[(int) $entry->id] ?? 0);

            $data[(int) $entry->id] = [
                'properties' => [
                    'name' => $entry->catalog_name,
                    'year' => $entry->catalog_year,
                ],
                'thumbnails' => array_merge([null], array_fill(0, $fileCount, $assetRoot)),
                'images' => array_merge([null], array_fill(0, $fileCount, $assetRoot)),
            ];
        }

        return [
            'status' => true,
            'data' => $data,
        ];
    }

    private function locale(Request $request): string
    {
        $locale = $request->query('locale', app()->getLocale());

        return is_string($locale) && $locale !== '' ? $locale : 'en';
    }
}

<?php

namespace App\Domain\ImagerySequences\Queries;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** @phpstan-type CountryImageCount array{name: string, lon: string, lat: string, iso3: string, image_count: int} */
class CountryImageCountQuery
{
    /** @return Collection<int, CountryImageCount> */
    public function get(): Collection
    {
        return DB::connection(config('mapilio.legacy_database_connection'))
            ->table('country_image_count')
            ->select(['name', 'lon', 'lat', 'iso3', 'image_count'])
            ->get()
            ->map(fn (object $row): array => [
                'name' => (string) $row->name,
                'lon' => (string) $row->lon,
                'lat' => (string) $row->lat,
                'iso3' => (string) $row->iso3,
                'image_count' => (int) $row->image_count,
            ]);
    }
}

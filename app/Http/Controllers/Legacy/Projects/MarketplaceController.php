<?php

namespace App\Http\Controllers\Legacy\Projects;

use App\Domain\Projects\Queries\MarketplaceQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplaceController extends Controller
{
    public function __invoke(Request $request, MarketplaceQuery $query): JsonResponse
    {
        $coordinates = $this->coordinates($request);

        if ($coordinates['error'] !== null) {
            return response()->json([
                'success' => false,
                'message' => [$coordinates['error']],
                'error_code' => 400,
            ], 400);
        }

        return response()->json([
            'data' => [
                'geojson' => $query->geojson($coordinates['lat'], $coordinates['lon']),
            ],
        ]);
    }

    /**
     * @return array{lat: float|null, lon: float|null, error: string|null}
     */
    private function coordinates(Request $request): array
    {
        $lat = $request->query('lat');
        $lon = $request->query('lon');

        if (! $this->legacyFilled($lat) || ! $this->legacyFilled($lon)) {
            return ['lat' => null, 'lon' => null, 'error' => null];
        }

        if (! is_scalar($lat) || ! is_scalar($lon) || ! is_numeric($lat) || ! is_numeric($lon)) {
            return ['lat' => null, 'lon' => null, 'error' => "'lat' and 'lon' must be numeric coordinates."];
        }

        $lat = (float) $lat;
        $lon = (float) $lon;

        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return ['lat' => null, 'lon' => null, 'error' => "'lat' and 'lon' must be valid coordinates."];
        }

        return ['lat' => $lat, 'lon' => $lon, 'error' => null];
    }

    private function legacyFilled(mixed $value): bool
    {
        return ! ($value === null || $value === '' || $value === '0' || $value === 0 || $value === 0.0 || $value === false || $value === []);
    }
}

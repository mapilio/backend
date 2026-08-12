<?php

namespace App\Http\Controllers\Api\V1\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Readiness probe for the load balancer.
 *
 * The liveness endpoint answers "is PHP running", which stays true while the
 * database is unreachable — so an instance that cannot serve a single real
 * request keeps receiving traffic. This answers "can this instance actually
 * do its job" by touching each dependency.
 *
 * Every probe is bounded and trivial. A readiness check that hangs is worse
 * than no readiness check: it turns a slow dependency into a stuck balancer.
 */
class ReadinessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->probe(fn () => DB::connection()->select('select 1')),
            'legacy_database' => $this->probe(
                fn () => DB::connection(config('mapilio.legacy_database_connection'))->select('select 1'),
            ),
            'cache' => $this->probe(function (): void {
                $key = 'mapilio:readiness';
                Cache::put($key, 1, 10);
                Cache::get($key);
            }),
        ];

        $ready = ! in_array(false, array_column($checks, 'ok'), true);

        return response()->json([
            'status' => $ready ? 'ready' : 'unavailable',
            'service' => config('mapilio.service_name'),
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $ready ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }

    /**
     * @return array{ok: bool, duration_ms: int}
     */
    private function probe(callable $check): array
    {
        $startedAt = hrtime(true);
        $ok = true;

        try {
            $check();
        } catch (Throwable) {
            // The reason is deliberately not returned. This endpoint is for a
            // load balancer, and echoing driver errors to whoever can reach it
            // would leak connection details.
            $ok = false;
        }

        return [
            'ok' => $ok,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ];
    }
}

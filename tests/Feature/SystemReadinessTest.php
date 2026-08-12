<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class SystemReadinessTest extends TestCase
{
    public function test_readiness_reports_every_dependency_when_all_are_reachable(): void
    {
        $this->getJson('/api/v1/system/readiness')
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('service', 'mapilio-modern-backend')
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.legacy_database.ok', true)
            ->assertJsonPath('checks.cache.ok', true)
            ->assertJsonStructure([
                'status',
                'service',
                'timestamp',
                'checks' => [
                    'database' => ['ok', 'duration_ms'],
                    'legacy_database' => ['ok', 'duration_ms'],
                    'cache' => ['ok', 'duration_ms'],
                ],
            ]);
    }

    public function test_readiness_returns_503_when_a_database_is_unreachable(): void
    {
        // The whole point: an instance that cannot reach its database must
        // leave the load balancer rotation instead of serving errors.
        Config::set('mapilio.legacy_database_connection', 'missing_connection');

        $this->getJson('/api/v1/system/readiness')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unavailable')
            ->assertJsonPath('checks.legacy_database.ok', false)
            ->assertJsonPath('checks.database.ok', true);
    }

    public function test_readiness_returns_503_when_the_cache_is_unreachable(): void
    {
        Cache::shouldReceive('put')->andThrow(new RuntimeException('cache down'));

        $this->getJson('/api/v1/system/readiness')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unavailable')
            ->assertJsonPath('checks.cache.ok', false);
    }

    public function test_readiness_does_not_leak_the_failure_reason(): void
    {
        // This endpoint is reachable by whoever can reach the load balancer,
        // so driver errors must not be echoed back.
        Config::set('mapilio.legacy_database_connection', 'missing_connection');

        $body = $this->getJson('/api/v1/system/readiness')->assertStatus(503)->json();

        $encoded = json_encode($body);

        $this->assertStringNotContainsString('missing_connection', (string) $encoded);
        $this->assertStringNotContainsString('SQLSTATE', (string) $encoded);
        $this->assertStringNotContainsString('Database', (string) $encoded);
    }

    public function test_liveness_endpoint_is_unchanged(): void
    {
        // Anything already polling health must keep seeing exactly what it saw
        // before; readiness is a separate path.
        DB::shouldReceive('connection')->never();

        $this->getJson('/api/v1/system/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('api_version', 'v1');
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    public function test_system_health_endpoint_returns_stable_v1_contract(): void
    {
        $this->getJson('/api/v1/system/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'mapilio-modern-backend')
            ->assertJsonPath('api_version', 'v1')
            ->assertJsonPath('compatibility', 'legacy-v1-behavior')
            ->assertJsonStructure([
                'status',
                'service',
                'api_version',
                'compatibility',
                'timestamp',
            ]);
    }
}

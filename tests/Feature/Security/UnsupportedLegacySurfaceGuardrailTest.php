<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class UnsupportedLegacySurfaceGuardrailTest extends TestCase
{
    public function test_unknown_dynamic_dispatch_path_returns_stable_404_without_stack_trace(): void
    {
        $this->getJson('/api/unknown-class/unknown-method')
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Not Found',
            ]);
    }

    public function test_generic_entry_read_is_not_exposed_without_explicit_allowlist(): void
    {
        $this->getJson('/api/entries/mapilio/imagery/1')
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Not Found',
            ]);
    }

    public function test_public_label_write_route_is_not_exposed_without_auth_design(): void
    {
        $this->postJson('/api/mapilio/labels/save-features', [])
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Not Found',
            ]);
    }

    public function test_ai_callback_route_is_not_exposed_without_signature_design(): void
    {
        $this->postJson('/webhook/response-prediction', [])
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Not Found',
            ]);
    }
}

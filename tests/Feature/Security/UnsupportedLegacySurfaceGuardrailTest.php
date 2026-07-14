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

    public function test_dynamic_auth_login_is_not_exposed_without_compatibility_design(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Not Found',
            ]);
    }

    public function test_dynamic_auth_register_is_not_exposed_without_registration_design(): void
    {
        $this->postJson('/api/auth/register', [])
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Not Found',
            ]);
    }

    public function test_token_protected_dynamic_endpoint_is_not_exposed_without_allowlist(): void
    {
        $this->getJson('/api/Imagery/getPointByUser?token=invalid-token')
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

    public function test_reference_routes_are_not_exposed_without_privacy_and_auth_design(): void
    {
        $referenceReadPaths = [
            '/api/get-references?user_id=1',
            '/api/v2/references',
            '/api/v2/references/1',
            '/api/v2/references/codes',
            '/api/v2/references/codes/1',
            '/api/v2/references/get-references-by-user/1',
            '/api/v2/references/codes/get-reference-code-by-user/1',
        ];

        foreach ($referenceReadPaths as $path) {
            $this->getJson($path)
                ->assertNotFound()
                ->assertExactJson([
                    'message' => 'Not Found',
                ]);
        }

        $this->postJson('/api/register-reference', [])
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Not Found',
            ]);
    }

    public function test_public_user_search_is_not_exposed_without_account_discovery_design(): void
    {
        $this->getJson('/api/search-user?id=1')
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Not Found',
            ]);

        $this->postJson('/api/search-user', [
            'keywords' => 'mapper',
        ])
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Not Found',
            ]);
    }
}

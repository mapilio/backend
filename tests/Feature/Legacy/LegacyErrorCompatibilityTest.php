<?php

namespace Tests\Feature\Legacy;

use Tests\TestCase;

class LegacyErrorCompatibilityTest extends TestCase
{
    public function test_legacy_error_endpoint_preserves_known_status_contract(): void
    {
        $this->getJson('/api/error/404')
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => ['Not Found'],
                'error_code' => '404',
            ]);
    }

    public function test_legacy_error_endpoint_preserves_untranslated_valid_status_contract(): void
    {
        $this->getJson('/api/error/418')
            ->assertStatus(418)
            ->assertExactJson([
                'success' => false,
                'message' => ['streams::error.418.name'],
                'error_code' => '418',
            ]);
    }

    public function test_legacy_error_endpoint_preserves_invalid_code_fallback_contract(): void
    {
        $this->getJson('/api/error/999')
            ->assertInternalServerError()
            ->assertExactJson([
                'success' => false,
                'message' => ['Internal Server Error'],
                'error_code' => 500,
            ]);

        $this->getJson('/api/error/abc')
            ->assertInternalServerError()
            ->assertExactJson([
                'success' => false,
                'message' => ['Internal Server Error'],
                'error_code' => 500,
            ]);
    }

    public function test_versioned_error_alias_returns_same_contract(): void
    {
        $legacy = $this->getJson('/api/error/422')
            ->assertStatus(422)
            ->json();

        $versioned = $this->getJson('/api/v1/system/errors/422')
            ->assertStatus(422)
            ->json();

        $this->assertSame($legacy, $versioned);
    }
}

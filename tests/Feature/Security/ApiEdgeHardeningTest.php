<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Log;
use Psr\Log\AbstractLogger;
use Stringable;
use Tests\TestCase;

class ApiEdgeHardeningTest extends TestCase
{
    public function test_api_responses_receive_security_headers_and_a_server_generated_request_id(): void
    {
        $response = $this
            ->withHeader('X-Request-ID', 'attacker-controlled')
            ->getJson('/api/v1/system/health')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=()')
            ->assertHeader(
                'Content-Security-Policy',
                "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            )
            ->assertHeaderMissing('Strict-Transport-Security');

        $requestId = (string) $response->headers->get('X-Request-ID');

        $this->assertNotSame('attacker-controlled', $requestId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $requestId,
        );
    }

    public function test_api_exception_responses_keep_the_same_edge_headers(): void
    {
        $this->getJson('/api/unsupported-path')
            ->assertNotFound()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Request-ID');
    }

    public function test_hsts_is_emitted_only_for_secure_production_requests(): void
    {
        app()->detectEnvironment(fn (): string => 'production');

        try {
            $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])
                ->withHeader('X-Forwarded-Proto', 'https')
                ->getJson('/api/v1/system/health')
                ->assertOk()
                ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

            $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])
                ->withHeader('X-Forwarded-Proto', 'http')
                ->getJson('/api/v1/system/health')
                ->assertOk()
                ->assertHeaderMissing('Strict-Transport-Security');
        } finally {
            app()->detectEnvironment(fn (): string => 'testing');
        }
    }

    public function test_request_logging_uses_a_bounded_metadata_allowlist(): void
    {
        $logger = new class extends AbstractLogger
        {
            /** @var list<array{level: mixed, message: string|Stringable, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = compact('level', 'message', 'context');
            }
        };

        Log::swap($logger);
        config()->set('mapilio.observability.api_request_logging_enabled', true);

        $this
            ->withHeader('Authorization', 'Bearer must-not-be-logged')
            ->withCookie('session', 'must-not-be-logged')
            ->getJson('/api/v1/system/health?token=must-not-be-logged')
            ->assertOk();

        $this->postJson('/api/v1/web/auth/token?token=must-not-be-logged', [])
            ->assertUnprocessable();

        $this->getJson('/api/must-not-be-logged')
            ->assertNotFound();

        $this->assertCount(3, $logger->records);
        [$health, $validation, $notFound] = $logger->records;

        $this->assertSame('info', $health['level']);
        $this->assertSame('api.request', $health['message']);
        $this->assertSame(
            ['request_id', 'method', 'path', 'route', 'status', 'duration_ms'],
            array_keys($health['context']),
        );
        $this->assertSame('/api/v1/system/health', $health['context']['path']);
        $this->assertSame('api.v1.system.health', $health['context']['route']);
        $this->assertSame(200, $health['context']['status']);
        $this->assertSame(422, $validation['context']['status']);
        $this->assertSame('/api/v1/web/auth/token', $validation['context']['path']);
        $this->assertSame(404, $notFound['context']['status']);
        $this->assertSame('/api/(unmatched)', $notFound['context']['path']);
        $this->assertStringNotContainsString('must-not-be-logged', json_encode($logger->records));
    }
}

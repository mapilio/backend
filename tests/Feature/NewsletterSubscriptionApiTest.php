<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NewsletterSubscriptionApiTest extends TestCase
{
    private const BASE_URL = 'https://mailcoach.example.test';

    private const LIST_ID = '60b5d70d-e039-4360-bb42-60869dbedc2c';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.mailcoach', [
            'base_url' => self::BASE_URL,
            'token' => 'server-only-token',
            'list_id' => self::LIST_ID,
            'skip_confirmation' => true,
            'connect_timeout' => 3,
            'timeout' => 8,
        ]);
    }

    public function test_it_proxies_a_normalized_subscription_without_exposing_provider_credentials(): void
    {
        Http::fake([
            self::BASE_URL.'/*' => Http::response(['message' => 'created'], 201),
        ]);

        $response = $this->postJson('/api/v1/content/newsletter-subscriptions', [
            'email' => '  Person@Example.COM  ',
            'website' => '',
        ])
            ->assertStatus(202)
            ->assertExactJson([
                'message' => 'Subscription has been received.',
            ]);

        $this->assertStringNotContainsString('server-only-token', (string) $response->getContent());

        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::BASE_URL.'/api/email-lists/'.self::LIST_ID.'/subscribers'
                && $request->hasHeader('Authorization', 'Bearer server-only-token')
                && $request->hasHeader('Idempotency-Key', hash('sha256', 'person@example.com'))
                && $request['email'] === 'person@example.com'
                && $request['skip_confirmation'] === true;
        });
    }

    public function test_invalid_email_is_rejected_before_calling_the_provider(): void
    {
        Http::fake();

        $this->postJson('/api/v1/content/newsletter-subscriptions', [
            'email' => 'not-an-email',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        Http::assertNothingSent();
    }

    public function test_honeypot_submission_gets_the_generic_response_without_calling_the_provider(): void
    {
        Http::fake();

        $this->postJson('/api/v1/content/newsletter-subscriptions', [
            'email' => 'person@example.com',
            'website' => 'https://spam.example',
        ])
            ->assertStatus(202)
            ->assertExactJson([
                'message' => 'Subscription has been received.',
            ]);

        Http::assertNothingSent();
    }

    public function test_missing_or_invalid_provider_configuration_returns_a_safe_unavailable_response(): void
    {
        Http::fake();
        Config::set('services.mailcoach.token', '');

        $this->postJson('/api/v1/content/newsletter-subscriptions', [
            'email' => 'person@example.com',
        ])
            ->assertStatus(503)
            ->assertExactJson([
                'message' => 'Subscription service is temporarily unavailable.',
            ]);

        Http::assertNothingSent();
    }

    public function test_provider_failure_is_not_leaked_to_the_client(): void
    {
        Http::fake([
            self::BASE_URL.'/*' => Http::response([
                'message' => 'internal provider detail',
                'token' => 'provider-secret',
            ], 500),
        ]);

        $response = $this->postJson('/api/v1/content/newsletter-subscriptions', [
            'email' => 'person@example.com',
        ])
            ->assertStatus(503)
            ->assertExactJson([
                'message' => 'Subscription service is temporarily unavailable.',
            ]);

        $this->assertStringNotContainsString('internal provider detail', (string) $response->getContent());
        $this->assertStringNotContainsString('provider-secret', (string) $response->getContent());
    }

    public function test_provider_duplicate_response_is_idempotently_accepted(): void
    {
        Http::fake([
            self::BASE_URL.'/*' => Http::response(['message' => 'already subscribed'], 422),
        ]);

        $this->postJson('/api/v1/content/newsletter-subscriptions', [
            'email' => 'person@example.com',
        ])
            ->assertStatus(202)
            ->assertExactJson([
                'message' => 'Subscription has been received.',
            ]);
    }

    public function test_plain_http_provider_is_blocked_in_production(): void
    {
        Http::fake();
        Config::set('services.mailcoach.base_url', 'http://mailcoach.example.test');
        app()->detectEnvironment(fn (): string => 'production');

        try {
            $this->postJson('/api/v1/content/newsletter-subscriptions', [
                'email' => 'person@example.com',
            ])
                ->assertStatus(503)
                ->assertExactJson([
                    'message' => 'Subscription service is temporarily unavailable.',
                ]);
        } finally {
            app()->detectEnvironment(fn (): string => 'testing');
        }

        Http::assertNothingSent();
    }

    public function test_public_subscription_route_is_rate_limited_per_ip(): void
    {
        Http::fake([
            self::BASE_URL.'/*' => Http::response([], 201),
        ]);

        for ($request = 1; $request <= 5; $request++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.42'])
                ->postJson('/api/v1/content/newsletter-subscriptions', [
                    'email' => "person{$request}@example.com",
                ])
                ->assertStatus(202);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.42'])
            ->postJson('/api/v1/content/newsletter-subscriptions', [
                'email' => 'blocked@example.com',
            ])
            ->assertTooManyRequests();

        Http::assertSentCount(5);
    }
}

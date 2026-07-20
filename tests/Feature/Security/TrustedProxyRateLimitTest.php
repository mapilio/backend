<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TrustedProxyRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mapilio.mobile_auth.signing_key', 'test-signing-key');

        Schema::create('default_users_users', function ($table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->boolean('activated')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::getConnection()->table('default_users_users')->insert([
            'id' => 10,
            'email' => 'alice@example.test',
            'username' => 'alice',
            'password' => Hash::make('correct-password'),
            'activated' => true,
            'enabled' => true,
            'deleted_at' => null,
        ]);
    }

    public function test_untrusted_callers_cannot_spoof_forwarded_ips_to_bypass_auth_throttling(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->failedLogin('203.0.113.10', "198.51.100.{$attempt}")
                ->assertStatus(400);
        }

        $this->failedLogin('203.0.113.10', '198.51.100.200')
            ->assertTooManyRequests();
    }

    public function test_explicitly_trusted_proxy_uses_the_forwarded_client_ip_for_auth_throttling(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $response = $this->failedLogin('192.0.2.40', '198.51.100.10')
                ->assertStatus(400);

            $this->assertSame('198.51.100.10', $response->baseRequest->ip());
        }

        $this->failedLogin('192.0.2.40', '198.51.100.11')
            ->assertStatus(400);

        $this->failedLogin('192.0.2.40', '198.51.100.10')
            ->assertTooManyRequests();
    }

    private function failedLogin(string $remoteAddress, string $forwardedFor)
    {
        return $this
            ->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
            ->withHeader('X-Forwarded-For', $forwardedFor)
            ->postJson('/api/v1/web/auth/token', [
                'grant_type' => 'password',
                'email' => 'alice@example.test',
                'password' => 'wrong-password',
            ]);
    }
}

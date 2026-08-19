<?php

namespace App\Domain\IdentityAccess;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

class AppleAccountRevoker
{
    public function __construct(private readonly HttpFactory $http) {}

    public function revoke(string $authorizationCode): void
    {
        $teamId = $this->requiredConfig('team_id');
        $clientId = $this->requiredConfig('client_id');
        $keyId = $this->requiredConfig('key_id');
        $privateKey = $this->privateKey();
        $now = time();

        $clientSecret = JWT::encode([
            'iss' => $teamId,
            'iat' => $now,
            'exp' => $now + 300,
            'aud' => 'https://appleid.apple.com',
            'sub' => $clientId,
        ], $privateKey, 'ES256', $keyId);

        $tokenResponse = $this->http->asForm()
            ->connectTimeout($this->timeout('connect_timeout', 3))
            ->timeout($this->timeout('timeout', 8))
            ->post('https://appleid.apple.com/auth/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $authorizationCode,
                'grant_type' => 'authorization_code',
            ]);

        $accessToken = $tokenResponse->successful()
            ? $tokenResponse->json('access_token')
            : null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('Apple authorization could not be exchanged.');
        }

        $revokeResponse = $this->http->asForm()
            ->connectTimeout($this->timeout('connect_timeout', 3))
            ->timeout($this->timeout('timeout', 8))
            ->post('https://appleid.apple.com/auth/revoke', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'token' => $accessToken,
                'token_type_hint' => 'access_token',
            ]);

        if (! $revokeResponse->successful()) {
            throw new RuntimeException('Apple authorization could not be revoked.');
        }
    }

    private function requiredConfig(string $key): string
    {
        $value = config("mapilio.mobile_accounts.apple.{$key}");

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('Apple account revocation is not configured.');
        }

        return trim($value);
    }

    private function privateKey(): string
    {
        $path = $this->requiredConfig('private_key_path');
        $contents = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($contents) || trim($contents) === '') {
            throw new RuntimeException('Apple account revocation key is unavailable.');
        }

        return $contents;
    }

    private function timeout(string $key, int $fallback): int
    {
        return max(1, min(20, (int) config("mapilio.mobile_accounts.apple.{$key}", $fallback)));
    }
}

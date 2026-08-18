<?php

namespace App\Domain\IdentityAccess;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SocialTokenBridge
{
    private const PROVIDERS = ['google', 'facebook', 'apple', 'openstreetmap'];

    public function authenticate(string $provider, string $token): int
    {
        if (! in_array($provider, self::PROVIDERS, true) || $token === '') {
            throw SocialTokenBridgeException::invalidCredentials();
        }

        $credentials = $this->clientCredentials();
        $baseUrl = $this->baseUrl();

        try {
            /** @var Response $response */
            $response = $this->request()->post($this->endpoint($baseUrl, $provider), [
                'client_id' => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
                'token' => $token,
                'is_mobile' => true,
            ]);
        } catch (Throwable $exception) {
            throw SocialTokenBridgeException::unavailable($exception);
        }

        if ($response->status() >= 400 && $response->status() < 500) {
            throw SocialTokenBridgeException::invalidCredentials();
        }

        if ($response->status() >= 500 || ! $response->successful()) {
            throw SocialTokenBridgeException::unavailable();
        }

        $payload = $response->json();
        $accessToken = is_array($payload) ? ($payload['access_token'] ?? null) : null;

        if (! is_string($accessToken) || trim($accessToken) === '') {
            throw SocialTokenBridgeException::unavailable();
        }

        $accessToken = trim($accessToken);

        try {
            /** @var Response $profile */
            $profile = $this->request()->withToken($accessToken)->get(
                $this->profileEndpoint($baseUrl),
            );
        } catch (Throwable $exception) {
            throw SocialTokenBridgeException::unavailable($exception);
        }

        if ($profile->status() >= 400 && $profile->status() < 500) {
            throw SocialTokenBridgeException::invalidCredentials();
        }

        if ($profile->status() >= 500 || ! $profile->successful()) {
            throw SocialTokenBridgeException::unavailable();
        }

        $profilePayload = $profile->json();
        $profileData = is_array($profilePayload) ? ($profilePayload['data'] ?? null) : null;
        $userId = is_array($profileData) && isset($profileData[0]) && is_array($profileData[0])
            ? ($profileData[0]['id'] ?? null)
            : null;

        if (! is_array($profilePayload) || ! is_array($profileData) || ! array_is_list($profileData)
            || ! array_key_exists(0, $profileData) || ! is_int($userId) || $userId < 1) {
            throw SocialTokenBridgeException::unavailable();
        }

        return $userId;
    }

    private function request(): PendingRequest
    {
        return Http::asJson()
            ->withoutRedirecting()
            ->connectTimeout((int) config('mapilio.mobile_social_auth.connect_timeout', 3))
            ->timeout((int) config('mapilio.mobile_social_auth.timeout', 8));
    }

    private function baseUrl(): string
    {
        $baseUrl = trim((string) config('mapilio.mobile_social_auth.base_url', ''));
        $parts = $baseUrl === '' ? false : parse_url($baseUrl);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])
            || ($parts['user'] ?? null) !== null || ($parts['pass'] ?? null) !== null
            || ($parts['query'] ?? null) !== null || ($parts['fragment'] ?? null) !== null
            || preg_match('/[\x00-\x20\x7f]/', $baseUrl) === 1
            || ! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || (app()->environment('production') && strtolower((string) $parts['scheme']) !== 'https')) {
            throw SocialTokenBridgeException::unavailable();
        }

        return rtrim($baseUrl, '/');
    }

    /**
     * These credentials authenticate this backend to the legacy extension.
     * They must never be accepted from or returned to a mobile client.
     *
     * @return array{client_id: string, client_secret: string}
     */
    private function clientCredentials(): array
    {
        $clientId = trim((string) config('mapilio.mobile_social_auth.client_id', ''));
        $clientSecret = trim((string) config('mapilio.mobile_social_auth.client_secret', ''));

        if ($clientId === '' || $clientSecret === '') {
            throw SocialTokenBridgeException::unavailable();
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];
    }

    private function endpoint(string $baseUrl, string $provider): string
    {
        return $baseUrl.'/oauth-api/'.$provider.'/authenticate';
    }

    private function profileEndpoint(string $baseUrl): string
    {
        return $baseUrl.'/api/function/user_profile/profile/getProfile';
    }
}

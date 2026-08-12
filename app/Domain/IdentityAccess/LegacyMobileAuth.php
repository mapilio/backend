<?php

namespace App\Domain\IdentityAccess;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class LegacyMobileAuth
{
    private const ACCESS_TOKEN_TYPE = 'access';

    private const REFRESH_TOKEN_TYPE = 'refresh';

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{id: int, success: true, token_type: string, expires_in: int, access_token: string, refresh_token: string}
     */
    public function login(array $parameters): array
    {
        $this->assertClient($parameters);

        return $this->issueFirstPartyToken($parameters);
    }

    /**
     * Issue a token to a first-party public client that cannot keep a client secret.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{id: int, success: true, token_type: string, expires_in: int, access_token: string, refresh_token: string}
     */
    public function issueFirstPartyToken(array $parameters): array
    {
        $grantType = (string) ($parameters['grant_type'] ?? '');

        if ($grantType === 'refresh_token') {
            return $this->refresh((string) ($parameters['refresh_token'] ?? ''));
        }

        if ($grantType !== 'password') {
            throw LegacyMobileAuthException::validation([
                'grant_type' => ['The grant_type field is invalid.'],
            ]);
        }

        $login = (string) ($parameters['email'] ?? $parameters['username'] ?? '');
        $password = (string) ($parameters['password'] ?? '');

        if ($login === '') {
            throw LegacyMobileAuthException::validation([
                'username' => ['username or email parameter is required!'],
                'email' => ['username or email parameter is required!'],
            ]);
        }

        if ($password === '') {
            throw LegacyMobileAuthException::validation([
                'password' => ['The password field is required.'],
            ]);
        }

        $user = $this->findUserForLogin($login);

        if ($user === null || ! Hash::check($password, (string) $user->password)) {
            throw LegacyMobileAuthException::invalidCredentials();
        }

        $this->assertActiveUser($user);

        return $this->tokenPair((int) $user->id);
    }

    public function userFromBearer(?string $authorizationHeader): ?object
    {
        if ($authorizationHeader === null || ! Str::startsWith($authorizationHeader, 'Bearer ')) {
            return null;
        }

        $payload = $this->decodeToken(trim(Str::after($authorizationHeader, 'Bearer ')), self::ACCESS_TOKEN_TYPE);

        if ($payload === null) {
            return null;
        }

        return $this->activeUserById((int) $payload['sub']);
    }

    /**
     * @return array{id: int, success: true, token_type: string, expires_in: int, access_token: string, refresh_token: string}
     */
    private function refresh(string $refreshToken): array
    {
        if ($refreshToken === '') {
            throw LegacyMobileAuthException::validation([
                'refresh_token' => ['The refresh_token field is required.'],
            ]);
        }

        $payload = $this->decodeToken($refreshToken, self::REFRESH_TOKEN_TYPE);

        if ($payload === null || $this->activeUserById((int) $payload['sub']) === null) {
            throw LegacyMobileAuthException::invalidCredentials();
        }

        return $this->tokenPair((int) $payload['sub']);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function assertClient(array $parameters): void
    {
        $errors = [];

        foreach (['client_id', 'client_secret', 'grant_type'] as $field) {
            if (empty($parameters[$field])) {
                $errors[$field] = ["The {$field} field is required."];
            }
        }

        if ($errors !== []) {
            throw LegacyMobileAuthException::validation($errors);
        }

        $expectedClientId = config('mapilio.mobile_auth.client_id');
        $expectedClientSecret = config('mapilio.mobile_auth.client_secret');

        if (is_string($expectedClientId) && $expectedClientId !== ''
            && ! hash_equals($expectedClientId, (string) $parameters['client_id'])) {
            throw LegacyMobileAuthException::invalidCredentials();
        }

        if (is_string($expectedClientSecret) && $expectedClientSecret !== ''
            && ! hash_equals($expectedClientSecret, (string) $parameters['client_secret'])) {
            throw LegacyMobileAuthException::invalidCredentials();
        }
    }

    private function assertActiveUser(object $user): void
    {
        if (! (bool) $user->activated || ! (bool) $user->enabled) {
            throw LegacyMobileAuthException::inactiveAccount();
        }
    }

    private function findUserForLogin(string $login): ?object
    {
        $column = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        return DB::connection(config('mapilio.legacy_database_connection'))
            ->table('default_users_users')
            ->where($column, $login)
            ->whereNull('deleted_at')
            ->first();
    }

    public function findUserById(int $userId): ?object
    {
        return DB::connection(config('mapilio.legacy_database_connection'))
            ->table('default_users_users')
            ->where('id', $userId)
            ->whereNull('deleted_at')
            ->first();
    }

    private function activeUserById(int $userId): ?object
    {
        $user = $this->findUserById($userId);

        if ($user === null || ! (bool) $user->activated || ! (bool) $user->enabled) {
            return null;
        }

        return $user;
    }

    /**
     * @return array{id: int, success: true, token_type: string, expires_in: int, access_token: string, refresh_token: string}
     */
    private function tokenPair(int $userId): array
    {
        $accessTtl = max(60, (int) config('mapilio.mobile_auth.access_token_ttl', 3600));
        $refreshTtl = max($accessTtl, (int) config('mapilio.mobile_auth.refresh_token_ttl', 36000));

        return [
            'id' => $userId,
            'success' => true,
            'token_type' => 'Bearer',
            'expires_in' => $accessTtl,
            'access_token' => $this->encodeToken($userId, self::ACCESS_TOKEN_TYPE, $accessTtl),
            'refresh_token' => $this->encodeToken($userId, self::REFRESH_TOKEN_TYPE, $refreshTtl),
        ];
    }

    private function encodeToken(int $userId, string $type, int $ttl): string
    {
        $payload = $this->base64UrlEncode(json_encode([
            'sub' => $userId,
            'typ' => $type,
            'iat' => time(),
            'exp' => time() + $ttl,
            'jti' => Str::random(32),
        ], JSON_THROW_ON_ERROR));

        return $payload.'.'.$this->signature($payload);
    }

    /**
     * @return array{sub: int, typ: string, iat: int, exp: int, jti: string}|null
     */
    private function decodeToken(string $token, string $expectedType): ?array
    {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2 || ! $this->signatureIsValid($parts[0], $parts[1])) {
            return null;
        }

        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);

        if ($json === false) {
            return null;
        }

        $payload = json_decode($json, true);

        if (! is_array($payload)
            || ($payload['typ'] ?? null) !== $expectedType
            || ! isset($payload['sub'], $payload['exp'])
            || (int) $payload['exp'] < time()) {
            return null;
        }

        if ($this->isRevoked($payload)) {
            return null;
        }

        return $payload;
    }

    /**
     * Revoke a token so it stops being accepted before its natural expiry.
     *
     * Returns false when the token cannot be decoded, so a caller cannot use
     * this to probe which arbitrary strings happen to be valid tokens.
     */
    public function revokeToken(string $token, string $expectedType, ?string $reason = null): bool
    {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2 || ! $this->signatureIsValid($parts[0], $parts[1])) {
            return false;
        }

        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $payload = $json === false ? null : json_decode($json, true);

        if (! is_array($payload)
            || ($payload['typ'] ?? null) !== $expectedType
            || ! isset($payload['sub'], $payload['exp'], $payload['jti'])) {
            return false;
        }

        DB::table('revoked_auth_tokens')->insertOrIgnore([
            'jti' => (string) $payload['jti'],
            'subject' => (int) $payload['sub'],
            'token_type' => (string) $payload['typ'],
            'reason' => $reason,
            'expires_at' => date('Y-m-d H:i:s', (int) $payload['exp']),
            'revoked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    /**
     * Drop denylist rows for tokens that have expired on their own.
     */
    public function pruneRevokedTokens(): int
    {
        return DB::table('revoked_auth_tokens')
            ->where('expires_at', '<', now())
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isRevoked(array $payload): bool
    {
        if (! config('mapilio.mobile_auth.revocation.enabled', false)) {
            return false;
        }

        $jti = $payload['jti'] ?? null;

        if (! is_string($jti) || $jti === '') {
            return false;
        }

        return DB::table('revoked_auth_tokens')->where('jti', $jti)->exists();
    }

    /**
     * Accepts the current signing key, and the previous one while a rotation is
     * in progress, so rotating the key does not invalidate live tokens.
     */
    private function signatureIsValid(string $payload, string $providedSignature): bool
    {
        foreach ($this->signingKeys() as $key) {
            if (hash_equals(hash_hmac('sha256', $payload, $key), $providedSignature)) {
                return true;
            }
        }

        return false;
    }

    private function signature(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->signingKey());
    }

    /**
     * @return list<string>
     */
    private function signingKeys(): array
    {
        $keys = [$this->signingKey()];
        $previous = (string) config('mapilio.mobile_auth.previous_signing_key', '');

        if ($previous !== '' && $previous !== $keys[0]) {
            $keys[] = $previous;
        }

        return $keys;
    }

    private function signingKey(): string
    {
        $key = (string) config('mapilio.mobile_auth.signing_key', '');

        if ($key === '') {
            throw new RuntimeException('Mobile auth signing key is not configured.');
        }

        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

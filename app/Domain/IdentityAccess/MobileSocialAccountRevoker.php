<?php

namespace App\Domain\IdentityAccess;

use App\Support\Database\LegacyDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MobileSocialAccountRevoker
{
    /**
     * @return array{id: int, provider: string, uid: string}
     */
    public function revoke(int $userId, string $provider, ?string $token): array
    {
        try {
            $providerKey = trim((string) config("mapilio.mobile_accounts.social.{$provider}.legacy_provider"));

            if ($providerKey === '') {
                throw new MobileAccountException('Provider account deletion is not configured.', 503);
            }

            $links = LegacyDatabase::connection()->table('default_social_authentications')
                ->where('user_id', $userId)
                ->where('provider', $providerKey)
                ->where('application', false)
                ->limit(2)
                ->get(['id', 'uid']);

            if ($links->count() !== 1 || ! is_string($links[0]->uid)
                || preg_match('/\A[0-9]{1,255}\z/D', $links[0]->uid) !== 1) {
                throw new MobileAccountException('The linked provider account could not be confirmed.', 409);
            }

            $uid = $links[0]->uid;

            if ($provider === 'google') {
                $this->revokeGoogle($uid, (string) $token);
            } elseif ($provider === 'facebook') {
                $this->revokeFacebook($uid);
            } else {
                throw new MobileAccountException('Unsupported account provider.', 400);
            }

            return ['id' => (int) $links[0]->id, 'provider' => $providerKey, 'uid' => $uid];
        } catch (MobileAccountException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Do not log request/response bodies or exception messages containing tokens.
            Log::warning('Social account revocation failed before account deletion.', [
                'user_id' => $userId,
                'provider' => $provider,
                'exception' => $exception::class,
            ]);

            throw new MobileAccountException('The provider authorization could not be revoked.', 503);
        }
    }

    private function revokeGoogle(string $uid, string $token): void
    {
        $profile = $this->request()->withToken($token)
            ->get('https://openidconnect.googleapis.com/v1/userinfo');

        if (in_array($profile->status(), [400, 401, 403], true)) {
            throw new MobileAccountException('Please authenticate with Google again before deleting the account.', 403);
        }

        if (! $profile->ok() || ! is_string($profile->json('sub'))) {
            throw new MobileAccountException('The Google account could not be verified.', 503);
        }

        if (! hash_equals($uid, $profile->json('sub'))) {
            throw new MobileAccountException('The Google account does not match the signed-in account.', 403);
        }

        $response = $this->request()->asForm()->post('https://oauth2.googleapis.com/revoke', ['token' => $token]);

        if (! $response->ok()) {
            throw new MobileAccountException('The Google authorization could not be revoked.', 503);
        }
    }

    private function revokeFacebook(string $uid): void
    {
        $appToken = trim((string) config('mapilio.mobile_accounts.social.facebook.app_access_token'));
        $version = trim((string) config('mapilio.mobile_accounts.social.facebook.graph_version'));

        if ($appToken === '' || preg_match('/\Av[1-9][0-9]*\.0\z/D', $version) !== 1) {
            throw new MobileAccountException('Facebook account deletion is not configured.', 503);
        }

        // Limited Login has no Graph user token. Only the backend app token is used.
        $response = $this->request()->withToken($appToken)
            ->delete("https://graph.facebook.com/{$version}/{$uid}/permissions");

        if (! $response->ok() || ($response->json() !== true && $response->json('success') !== true)) {
            throw new MobileAccountException('The Facebook authorization could not be revoked.', 503);
        }
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(8);
    }
}

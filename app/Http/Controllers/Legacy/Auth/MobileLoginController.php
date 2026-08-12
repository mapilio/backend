<?php

namespace App\Http\Controllers\Legacy\Auth;

use App\Domain\IdentityAccess\LegacyMobileAuth;
use App\Domain\IdentityAccess\LegacyMobileAuthException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileLoginController extends Controller
{
    /**
     * Fields the auth service reads. Anything else in the body is already
     * ignored downstream, but forwarding the whole request into a
     * security-critical service means any field read there in future would be
     * attacker-influenced by default. Whitelisting keeps that from happening.
     *
     * No validation is applied here on purpose: the legacy error messages come
     * from the auth service itself, and adding rules would change the response
     * shape for clients that depend on it.
     */
    private const FORWARDED_FIELDS = [
        'grant_type',
        'client_id',
        'client_secret',
        'email',
        'username',
        'password',
        'refresh_token',
    ];

    public function __invoke(Request $request, LegacyMobileAuth $auth): JsonResponse
    {
        try {
            return response()->json($auth->login($this->credentials($request)));
        } catch (LegacyMobileAuthException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->legacyMessage(),
            ], $exception->getCode() ?: 400);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function credentials(Request $request): array
    {
        return array_intersect_key(
            $request->all(),
            array_flip(self::FORWARDED_FIELDS),
        );
    }
}

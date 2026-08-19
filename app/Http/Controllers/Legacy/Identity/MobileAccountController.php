<?php

namespace App\Http\Controllers\Legacy\Identity;

use App\Domain\IdentityAccess\MobileAccountException;
use App\Domain\IdentityAccess\MobileAccountService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MobileAccountController extends Controller
{
    public function register(Request $request, MobileAccountService $accounts): JsonResponse
    {
        try {
            return response()->json($accounts->register($request->only([
                'name',
                'username',
                'email',
                'password',
                'callback',
                'success-params',
                'error-params',
                'referrer',
            ]), $request->routeIs('api.v1.*')
                ? 'api.v1.mobile.accounts.verify'
                : 'api.legacy.mobile-accounts.verify'));
        } catch (MobileAccountException $exception) {
            $message = $exception->publicMessage();

            return response()->json(
                is_array($message) ? $message : ['message' => $message],
                $exception->getCode() ?: 400,
            );
        }
    }

    public function verifyRegistration(Request $request, MobileAccountService $accounts): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return redirect()->away($this->fallbackCallback(false, $accounts));
        }

        $activated = $accounts->activate(
            (int) $request->query('user'),
            (string) $request->query('code'),
        );

        return redirect()->away($accounts->appendCallback(
            (string) $request->query('callback'),
            (string) $request->query($activated ? 'success' : 'error'),
        ));
    }

    public function forgotPassword(Request $request, MobileAccountService $accounts): JsonResponse
    {
        return $this->accountResponse(fn (): array => $accounts->requestPasswordReset(
            $request->only([
                'email',
                'callback',
                'success-params',
                'error-params',
            ]),
            $request->routeIs('api.v1.*')
                ? 'api.v1.mobile.password.verify'
                : 'api.legacy.mobile-password.verify',
        ));
    }

    public function verifyPasswordReset(Request $request, MobileAccountService $accounts): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return redirect()->away($this->fallbackCallback(false, $accounts));
        }

        return redirect()->away($accounts->passwordResetRedirect(
            (int) $request->query('user'),
            (string) $request->query('code'),
            (string) $request->query('callback'),
            (string) $request->query('success'),
            (string) $request->query('error'),
        ));
    }

    public function resetPassword(Request $request, MobileAccountService $accounts): JsonResponse
    {
        return $this->accountResponse(fn (): array => $accounts->resetPassword($request->only([
            'code',
            'password',
            're-password',
        ])));
    }

    public function updateProfile(Request $request, MobileAccountService $accounts): JsonResponse
    {
        return $this->accountResponse(fn (): array => $accounts->updateProfile(
            $this->userId($request),
            $this->parameters($request),
            $request->file('options.parameters.user_profile_photo') ?? $request->file('user_profile_photo'),
        ));
    }

    public function updateEmail(Request $request, MobileAccountService $accounts): JsonResponse
    {
        return $this->accountResponse(fn (): array => $accounts->requestEmailChange(
            $this->userId($request),
            $this->parameters($request),
            $request->routeIs('api.v1.*')
                ? 'api.v1.mobile.profile.email.verify'
                : 'api.legacy.mobile-email.verify',
        ));
    }

    public function verifyEmail(Request $request, MobileAccountService $accounts): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return redirect()->away($this->fallbackCallback(false, $accounts));
        }

        $confirmed = $accounts->confirmEmailChange(
            (int) $request->query('user'),
            (string) $request->query('current'),
            (string) $request->query('email'),
        );

        return redirect()->away($accounts->appendCallback(
            (string) $request->query('callback'),
            'tverification='.($confirmed ? 'true' : 'false'),
        ));
    }

    public function deleteAccount(Request $request, MobileAccountService $accounts): JsonResponse
    {
        return $this->accountResponse(fn (): array => $accounts->deleteAccount(
            $this->userId($request),
            $this->parameters($request),
        ));
    }

    /**
     * @param  callable(): array<string, mixed>  $operation
     */
    private function accountResponse(callable $operation): JsonResponse
    {
        try {
            return response()->json($operation());
        } catch (MobileAccountException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->publicMessage(),
            ], $exception->getCode() ?: 400);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parameters(Request $request): array
    {
        $nested = $request->input('options.parameters');

        return is_array($nested) ? $nested : $request->all();
    }

    private function userId(Request $request): int
    {
        $user = $request->attributes->get('mapilio_mobile_user');

        return is_object($user) ? (int) $user->id : 0;
    }

    private function fallbackCallback(bool $success, MobileAccountService $accounts): string
    {
        return $accounts->appendCallback(
            (string) config('mapilio.mobile_accounts.verification_fallback_callback'),
            'tverification='.($success ? 'true' : 'false'),
        );
    }
}

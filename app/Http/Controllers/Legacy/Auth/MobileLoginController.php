<?php

namespace App\Http\Controllers\Legacy\Auth;

use App\Domain\IdentityAccess\LegacyMobileAuth;
use App\Domain\IdentityAccess\LegacyMobileAuthException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileLoginController extends Controller
{
    public function __invoke(Request $request, LegacyMobileAuth $auth): JsonResponse
    {
        try {
            return response()->json($auth->login($request->all()));
        } catch (LegacyMobileAuthException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->legacyMessage(),
            ], $exception->getCode() ?: 400);
        }
    }
}

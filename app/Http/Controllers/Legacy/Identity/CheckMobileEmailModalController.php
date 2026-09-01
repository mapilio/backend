<?php

namespace App\Http\Controllers\Legacy\Identity;

use App\Domain\IdentityAccess\Actions\CheckMobileEmailModal;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckMobileEmailModalController extends Controller
{
    public function __invoke(
        Request $request,
        CheckMobileEmailModal $modal,
    ): JsonResponse {
        $user = $request->attributes->get('mapilio_mobile_user');

        if (! is_object($user) || ! isset($user->id)) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $userId = filter_var($user->id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($userId === false) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return response()->json($modal->check($user));
    }
}

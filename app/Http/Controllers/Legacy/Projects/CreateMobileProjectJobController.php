<?php

namespace App\Http\Controllers\Legacy\Projects;

use App\Domain\Projects\Actions\CreateMobileProjectJob;
use App\Domain\Projects\Actions\MobileProjectJobException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreateMobileProjectJobController extends Controller
{
    public function __invoke(
        Request $request,
        CreateMobileProjectJob $jobs,
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

        $projectId = data_get($request->all(), 'options.parameters.id', $request->input('id'));

        if (! is_numeric($projectId) || (int) $projectId <= 0) {
            return $this->error("'id' is required!", 400);
        }

        try {
            return response()->json($jobs->create((int) $projectId, $user));
        } catch (MobileProjectJobException $exception) {
            return $this->error($exception->getMessage(), $exception->getCode());
        }
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => [$message],
            'error_code' => $status,
        ], $status);
    }
}

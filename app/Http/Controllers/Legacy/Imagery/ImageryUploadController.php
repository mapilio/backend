<?php

namespace App\Http\Controllers\Legacy\Imagery;

use App\Domain\IdentityAccess\LegacyMobileAuth;
use App\Domain\ImageryUploads\Actions\CreateImageryUpload;
use App\Domain\ImageryUploads\Actions\ImageryUploadException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImageryUploadController extends Controller
{
    public function __invoke(
        Request $request,
        LegacyMobileAuth $auth,
        CreateImageryUpload $uploads,
    ): JsonResponse {
        $user = $auth->userFromBearer($request->header('Authorization'));

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $parameters = data_get($request->all(), 'options.parameters', $request->all());

        if (! is_array($parameters)) {
            return $this->error("'options.parameters' is required!", 400);
        }

        try {
            return response()->json($uploads->create($parameters, $user));
        } catch (ImageryUploadException $exception) {
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

<?php

namespace App\Http\Controllers\Legacy\Imagery;

use App\Domain\IdentityAccess\LegacyMobileAuth;
use App\Domain\ImageryReports\Actions\CreateImageReport;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImageReportController extends Controller
{
    public function __invoke(
        Request $request,
        LegacyMobileAuth $auth,
        CreateImageReport $reports,
    ): JsonResponse {
        $imageryId = $this->parameter($request, 'imagery_id');
        $message = $this->parameter($request, 'message');

        if (! is_numeric($imageryId) || (int) $imageryId <= 0) {
            return $this->missingParameter('imagery_id');
        }

        if (! is_string($message) || trim($message) === '') {
            return $this->missingParameter('message');
        }

        $message = trim($message);
        $maxLength = (int) config('mapilio.imagery_reports.max_message_length');

        if (mb_strlen($message) > $maxLength) {
            return $this->invalidParameter(
                "'message' accepts at most {$maxLength} characters!",
            );
        }

        if (! $reports->imageryExists((int) $imageryId)) {
            return $this->invalidParameter("'imagery_id' does not exist!");
        }

        $user = $auth->userFromBearer($request->header('Authorization'));

        return response()->json($reports->create((int) $imageryId, $message, $user));
    }

    private function parameter(Request $request, string $key): mixed
    {
        return data_get($request->all(), "options.parameters.{$key}", $request->input($key));
    }

    private function missingParameter(string $parameter): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => ["'{$parameter}' is required!"],
            'error_code' => 400,
        ], 400);
    }

    /**
     * Reuses the established legacy error envelope so client error handling is
     * unchanged; only the message text is new.
     */
    private function invalidParameter(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => [$message],
            'error_code' => 400,
        ], 400);
    }
}

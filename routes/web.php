<?php

use App\Http\Controllers\Api\V1\Ai\PredictionCallbackController;
use App\Http\Controllers\Legacy\Config\GeneralConfigController;
use App\Http\Middleware\VerifyAiPredictionCallback;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => config('mapilio.service_name'),
        'health' => '/api/v1/system/health',
    ]);
});

Route::get('/config/general', GeneralConfigController::class)
    ->name('legacy.config.general');

Route::post('/webhook/response-prediction', PredictionCallbackController::class)
    ->middleware(VerifyAiPredictionCallback::class)
    ->name('webhook.legacy.prediction-response');

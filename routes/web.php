<?php

use App\Http\Controllers\Legacy\Config\GeneralConfigController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => config('mapilio.service_name'),
        'health' => '/api/v1/system/health',
    ]);
});

Route::get('/config/general', GeneralConfigController::class)
    ->name('legacy.config.general');

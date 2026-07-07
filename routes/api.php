<?php

use App\Http\Controllers\Api\V1\System\HealthController;
use App\Http\Controllers\Legacy\Imagery\CountryImageCountController;
use Illuminate\Support\Facades\Route;

Route::get('country-image-count', CountryImageCountController::class)
    ->name('api.legacy.country-image-count');

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('system/health', HealthController::class)->name('system.health');
    Route::get('imagery/country-image-count', CountryImageCountController::class)
        ->name('imagery.country-image-count');
});

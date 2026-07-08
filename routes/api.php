<?php

use App\Http\Controllers\Api\V1\System\HealthController;
use App\Http\Controllers\Legacy\Imagery\CountryImageCountController;
use App\Http\Controllers\Legacy\Imagery\GetPointByUserController;
use App\Http\Controllers\Legacy\Imagery\LeaderboardController;
use Illuminate\Support\Facades\Route;

Route::get('country-image-count', CountryImageCountController::class)
    ->name('api.legacy.country-image-count');
Route::get('leaderboard', LeaderboardController::class)
    ->name('api.legacy.leaderboard');
Route::get('get-point-by-user', GetPointByUserController::class)
    ->name('api.legacy.get-point-by-user');

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('system/health', HealthController::class)->name('system.health');
    Route::get('imagery/country-image-count', CountryImageCountController::class)
        ->name('imagery.country-image-count');
    Route::get('imagery/leaderboard', LeaderboardController::class)
        ->name('imagery.leaderboard');
    Route::get('imagery/user-points', GetPointByUserController::class)
        ->name('imagery.user-points');
});

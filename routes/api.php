<?php

use App\Http\Controllers\Api\V1\System\HealthController;
use App\Http\Controllers\Legacy\Geo\UploadedRoadsByGroupController;
use App\Http\Controllers\Legacy\Imagery\CountryImageCountController;
use App\Http\Controllers\Legacy\Imagery\EmbedImageController;
use App\Http\Controllers\Legacy\Imagery\GetPointByUserController;
use App\Http\Controllers\Legacy\Imagery\LeaderboardController;
use App\Http\Controllers\Legacy\Imagery\LeaderboardWinnerController;
use App\Http\Controllers\Legacy\Imagery\SequenceDetailController;
use App\Http\Controllers\Legacy\Inventory\SpriteController;
use App\Http\Controllers\Legacy\Inventory\TypeMetadataController;
use App\Http\Controllers\Legacy\Organizations\OrganizationLeaderboardController;
use App\Http\Controllers\Legacy\PublicContent\BlogContentController;
use Illuminate\Support\Facades\Route;

Route::get('country-image-count', CountryImageCountController::class)
    ->name('api.legacy.country-image-count');
Route::get('leaderboard', LeaderboardController::class)
    ->name('api.legacy.leaderboard');
Route::get('v2/leaderboard', LeaderboardController::class)
    ->defaults('score_version', 2)
    ->name('api.legacy.v2.leaderboard');
Route::get('leaderboard-organization', OrganizationLeaderboardController::class)
    ->name('api.legacy.organization-leaderboard');
Route::get('leaderboard-organization-v2', OrganizationLeaderboardController::class)
    ->defaults('score_version', 2)
    ->name('api.legacy.organization-leaderboard-v2');
Route::get('leaderboard-winner', LeaderboardWinnerController::class)
    ->name('api.legacy.leaderboard-winner');
Route::get('v2/leaderboard-winner', LeaderboardWinnerController::class)
    ->name('api.legacy.v2.leaderboard-winner');
Route::get('get-point-by-user', GetPointByUserController::class)
    ->name('api.legacy.get-point-by-user');
Route::get('sequence-detail', SequenceDetailController::class)
    ->name('api.legacy.sequence-detail');
Route::get('embed/{sequenceUuid}', EmbedImageController::class)
    ->name('api.legacy.embed-image');
Route::get('get-uploaded-roads-group', UploadedRoadsByGroupController::class)
    ->name('api.legacy.uploaded-roads-group');
Route::get('get-types', [TypeMetadataController::class, 'types'])
    ->name('api.legacy.types');
Route::get('get-groups', [TypeMetadataController::class, 'groups'])
    ->name('api.legacy.groups');
Route::get('get-sprites', [SpriteController::class, 'standard'])
    ->name('api.legacy.sprites');
Route::get('get-sprites2x', [SpriteController::class, 'retina'])
    ->name('api.legacy.sprites-2x');
Route::get('get-categories', [BlogContentController::class, 'categories'])
    ->name('api.legacy.blog-categories');
Route::get('get-blogs', [BlogContentController::class, 'blogs'])
    ->name('api.legacy.blogs');
Route::get('get-blog-detail/{slug}', [BlogContentController::class, 'detail'])
    ->name('api.legacy.blog-detail');

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('system/health', HealthController::class)->name('system.health');
    Route::get('imagery/country-image-count', CountryImageCountController::class)
        ->name('imagery.country-image-count');
    Route::get('imagery/leaderboard', LeaderboardController::class)
        ->name('imagery.leaderboard');
    Route::get('imagery/leaderboard-winner', LeaderboardWinnerController::class)
        ->name('imagery.leaderboard-winner');
    Route::get('organizations/leaderboard', OrganizationLeaderboardController::class)
        ->name('organizations.leaderboard');
    Route::get('imagery/user-points', GetPointByUserController::class)
        ->name('imagery.user-points');
    Route::get('imagery/sequence-detail', SequenceDetailController::class)
        ->name('imagery.sequence-detail');
    Route::get('imagery/embed/{sequenceUuid}', EmbedImageController::class)
        ->name('imagery.embed-image');
    Route::get('geo/uploaded-roads-group', UploadedRoadsByGroupController::class)
        ->name('geo.uploaded-roads-group');
    Route::get('inventory/types', [TypeMetadataController::class, 'types'])
        ->name('inventory.types');
    Route::get('inventory/groups', [TypeMetadataController::class, 'groups'])
        ->name('inventory.groups');
    Route::get('inventory/sprites', [SpriteController::class, 'standard'])
        ->name('inventory.sprites');
    Route::get('inventory/sprites-2x', [SpriteController::class, 'retina'])
        ->name('inventory.sprites-2x');
    Route::get('content/categories', [BlogContentController::class, 'categories'])
        ->name('content.categories');
    Route::get('content/blogs', [BlogContentController::class, 'blogs'])
        ->name('content.blogs');
    Route::get('content/blogs/{slug}', [BlogContentController::class, 'detail'])
        ->name('content.blogs.detail');
});

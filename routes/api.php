<?php

use App\Http\Controllers\Api\V1\Ai\PredictionCallbackController;
use App\Http\Controllers\Api\V1\Content\NewsletterSubscriptionController;
use App\Http\Controllers\Api\V1\Geo\AiFeatureDetailController;
use App\Http\Controllers\Api\V1\Mobile\MobileLogoutController;
use App\Http\Controllers\Api\V1\Mobile\MobilePublicTokenController;
use App\Http\Controllers\Api\V1\Mobile\MobileSocialTokenController;
use App\Http\Controllers\Api\V1\System\HealthController;
use App\Http\Controllers\Api\V1\System\ReadinessController;
use App\Http\Controllers\Api\V1\Web\WebTokenController;
use App\Http\Controllers\Legacy\Auth\MobileLoginController;
use App\Http\Controllers\Legacy\Billing\BillingPlanController;
use App\Http\Controllers\Legacy\Config\GeneralConfigController;
use App\Http\Controllers\Legacy\Gamification\GamificationBadgesController;
use App\Http\Controllers\Legacy\Geo\UploadedRoadsByGroupController;
use App\Http\Controllers\Legacy\Identity\CheckMobileEmailModalController;
use App\Http\Controllers\Legacy\Identity\MobileAccountController;
use App\Http\Controllers\Legacy\Identity\MobileProfileController;
use App\Http\Controllers\Legacy\Identity\OneSignalIdentityVerificationController;
use App\Http\Controllers\Legacy\Identity\PublicUserProfileController;
use App\Http\Controllers\Legacy\Imagery\CountryImageCountController;
use App\Http\Controllers\Legacy\Imagery\EmbedImageController;
use App\Http\Controllers\Legacy\Imagery\GetPointByUserController;
use App\Http\Controllers\Legacy\Imagery\ImageReportController;
use App\Http\Controllers\Legacy\Imagery\ImageryUploadController;
use App\Http\Controllers\Legacy\Imagery\LeaderboardController;
use App\Http\Controllers\Legacy\Imagery\LeaderboardWinnerController;
use App\Http\Controllers\Legacy\Imagery\SequenceDetailController;
use App\Http\Controllers\Legacy\Imagery\UserUploadDetailsController;
use App\Http\Controllers\Legacy\Imagery\UserUploadsController;
use App\Http\Controllers\Legacy\Inventory\SpriteController;
use App\Http\Controllers\Legacy\Inventory\TypeMetadataController;
use App\Http\Controllers\Legacy\Organizations\OrganizationLeaderboardController;
use App\Http\Controllers\Legacy\Projects\CreateMobileProjectJobController;
use App\Http\Controllers\Legacy\Projects\MarketplaceController;
use App\Http\Controllers\Legacy\Projects\MobileUserJobsController;
use App\Http\Controllers\Legacy\PublicContent\BlogContentController;
use App\Http\Controllers\Legacy\PublicContent\CatalogController;
use App\Http\Controllers\Legacy\System\LegacyErrorController;
use App\Http\Middleware\VerifyAiPredictionCallback;
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
Route::get('user-uploads-v2', UserUploadsController::class)
    ->name('api.legacy.user-uploads-v2');
Route::get('user-uploads-detail-v2', UserUploadDetailsController::class)
    ->name('api.legacy.user-uploads-detail-v2');
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
Route::get('catalog', CatalogController::class)
    ->name('api.legacy.catalog');
Route::get('package-list', [BillingPlanController::class, 'packages'])
    ->name('api.legacy.billing.packages');
Route::get('hosting-list', [BillingPlanController::class, 'hosting'])
    ->name('api.legacy.billing.hosting');
Route::get('get-marketplaces', MarketplaceController::class)
    ->name('api.legacy.projects.marketplaces');
Route::get('function/projects/job/getMyJobs', MobileUserJobsController::class)
    ->middleware('mobile.auth')
    ->name('api.legacy.projects.jobs.mine');
Route::post('function/projects/job/createJob', CreateMobileProjectJobController::class)
    ->middleware('mobile.auth')
    ->name('api.legacy.projects.jobs.create');
Route::get('gamification/badges/{userId}', GamificationBadgesController::class)
    ->whereNumber('userId')
    ->name('api.legacy.gamification.badges');
Route::get('error/{code}', LegacyErrorController::class)
    ->name('api.legacy.error');
Route::post('v2/login', MobileLoginController::class)
    ->middleware('throttle:mobile-auth')
    ->name('api.legacy.v2.mobile-login');
Route::post('register', [MobileAccountController::class, 'register'])
    ->middleware('throttle:mobile-registration')
    ->name('api.legacy.mobile-accounts.store');
Route::get('register/verify', [MobileAccountController::class, 'verifyRegistration'])
    ->middleware('throttle:30,1')
    ->name('api.legacy.mobile-accounts.verify');
Route::post('forgot-password', [MobileAccountController::class, 'forgotPassword'])
    ->middleware('throttle:mobile-password-reset')
    ->name('api.legacy.mobile-password.forgot');
Route::get('forgot-password/verify', [MobileAccountController::class, 'verifyPasswordReset'])
    ->middleware('throttle:30,1')
    ->name('api.legacy.mobile-password.verify');
Route::post('renew-password', [MobileAccountController::class, 'resetPassword'])
    ->middleware('throttle:mobile-password-renew')
    ->name('api.legacy.mobile-password.reset');
Route::get('function/user_profile/profile/getProfile', MobileProfileController::class)
    ->middleware('mobile.auth')
    ->name('api.legacy.mobile-profile');
Route::post('function/user_profile/profile/updateProfile', [MobileAccountController::class, 'updateProfile'])
    ->middleware(['mobile.auth', 'throttle:mobile-account-write'])
    ->name('api.legacy.mobile-profile.update');
Route::post('function/user_profile/profile/updateMail', [MobileAccountController::class, 'updateEmail'])
    ->middleware(['mobile.auth', 'throttle:mobile-account-write'])
    ->name('api.legacy.mobile-profile.email.update');
Route::get('function/user_profile/profile/verifyMail', [MobileAccountController::class, 'verifyEmail'])
    ->middleware('throttle:30,1')
    ->name('api.legacy.mobile-email.verify');
Route::post('function/user_profile/profile/delete-account', [MobileAccountController::class, 'deleteAccount'])
    ->middleware(['mobile.auth', 'throttle:mobile-account-delete'])
    ->name('api.legacy.mobile-account.delete');
Route::post('function/user_profile/profile/checkIsModalShown', CheckMobileEmailModalController::class)
    ->middleware('mobile.auth')
    ->name('api.legacy.mobile-profile.email-modal');
Route::post('onesignal/identity-verification', OneSignalIdentityVerificationController::class)
    ->defaults('identity_verification_failure_status', 500)
    ->name('api.legacy.onesignal.identity-verification');
Route::match(['GET', 'POST'], 'search-user', PublicUserProfileController::class)
    ->name('api.legacy.search-user');
Route::post('image-report', ImageReportController::class)
    ->middleware('throttle:imagery-reports')
    ->name('api.legacy.imagery.reports');
Route::post('function/mapilio/imagery/upload', ImageryUploadController::class)
    ->middleware('mobile.auth')
    ->name('api.legacy.imagery.uploads');

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('system/health', HealthController::class)->name('system.health');
    Route::get('system/readiness', ReadinessController::class)->name('system.readiness');
    Route::post('ai/predictions/callback', PredictionCallbackController::class)
        ->middleware(VerifyAiPredictionCallback::class)
        ->name('ai.predictions.callback');
    Route::get('mobile/config/general', GeneralConfigController::class)
        ->name('mobile.config.general');
    Route::post('mobile/auth/token', MobileLoginController::class)
        ->middleware('throttle:mobile-auth')
        ->name('mobile.auth.token');
    Route::post('mobile/auth/public-token', MobilePublicTokenController::class)
        ->middleware('throttle:mobile-auth')
        ->name('mobile.auth.public-token');
    Route::post('mobile/auth/social-token', MobileSocialTokenController::class)
        ->middleware('throttle:mobile-social-auth')
        ->name('mobile.auth.social-token');
    Route::post('mobile/auth/logout', MobileLogoutController::class)
        ->middleware('throttle:mobile-auth')
        ->name('mobile.auth.logout');
    Route::post('mobile/accounts', [MobileAccountController::class, 'register'])
        ->middleware('throttle:mobile-registration')
        ->name('mobile.accounts.store');
    Route::get('mobile/accounts/verify', [MobileAccountController::class, 'verifyRegistration'])
        ->middleware('throttle:30,1')
        ->name('mobile.accounts.verify');
    Route::post('mobile/password/forgot', [MobileAccountController::class, 'forgotPassword'])
        ->middleware('throttle:mobile-password-reset')
        ->name('mobile.password.forgot');
    Route::get('mobile/password/verify', [MobileAccountController::class, 'verifyPasswordReset'])
        ->middleware('throttle:30,1')
        ->name('mobile.password.verify');
    Route::post('mobile/password/reset', [MobileAccountController::class, 'resetPassword'])
        ->middleware('throttle:mobile-password-renew')
        ->name('mobile.password.reset');
    Route::get('mobile/profile', MobileProfileController::class)
        ->middleware('mobile.auth')
        ->name('mobile.profile');
    Route::post('mobile/profile', [MobileAccountController::class, 'updateProfile'])
        ->middleware(['mobile.auth', 'throttle:mobile-account-write'])
        ->name('mobile.profile.update');
    Route::post('mobile/profile/email', [MobileAccountController::class, 'updateEmail'])
        ->middleware(['mobile.auth', 'throttle:mobile-account-write'])
        ->name('mobile.profile.email.update');
    Route::get('mobile/profile/email/verify', [MobileAccountController::class, 'verifyEmail'])
        ->middleware('throttle:30,1')
        ->name('mobile.profile.email.verify');
    Route::delete('mobile/account', [MobileAccountController::class, 'deleteAccount'])
        ->middleware(['mobile.auth', 'throttle:mobile-account-delete'])
        ->name('mobile.account.delete');
    Route::post('mobile/profile/email-modal', CheckMobileEmailModalController::class)
        ->middleware('mobile.auth')
        ->name('mobile.profile.email-modal');
    Route::post('mobile/onesignal/identity-verification', OneSignalIdentityVerificationController::class)
        ->defaults('identity_verification_failure_status', 401)
        ->name('mobile.onesignal.identity-verification');
    Route::get('users/profile', PublicUserProfileController::class)
        ->name('users.profile');
    Route::get('system/errors/{code}', LegacyErrorController::class)
        ->name('system.errors');
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
        ->defaults('pagination_enabled', true)
        ->name('imagery.sequence-detail');
    Route::get('imagery/user-uploads', UserUploadsController::class)
        ->name('imagery.user-uploads');
    Route::get('imagery/user-upload-details', UserUploadDetailsController::class)
        ->name('imagery.user-upload-details');
    Route::get('imagery/embed/{sequenceUuid}', EmbedImageController::class)
        ->defaults('pagination_enabled', true)
        ->name('imagery.embed-image');
    Route::post('imagery/reports', ImageReportController::class)
        ->middleware('throttle:imagery-reports')
        ->name('imagery.reports');
    Route::post('imagery/uploads', ImageryUploadController::class)
        ->middleware('mobile.auth')
        ->name('imagery.uploads');
    Route::get('geo/uploaded-roads-group', UploadedRoadsByGroupController::class)
        ->defaults('pagination_enabled', true)
        ->name('geo.uploaded-roads-group');
    Route::get('geo/ai-features/{featureId}', AiFeatureDetailController::class)
        ->whereNumber('featureId')
        ->middleware('throttle:120,1')
        ->name('geo.ai-features.show');
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
    Route::get('content/catalog', CatalogController::class)
        ->name('content.catalog');
    Route::post('content/newsletter-subscriptions', NewsletterSubscriptionController::class)
        ->middleware('throttle:5,1')
        ->name('content.newsletter-subscriptions.store');
    Route::post('web/auth/token', WebTokenController::class)
        ->middleware('throttle:10,1')
        ->name('web.auth.token');
    Route::get('billing/packages', [BillingPlanController::class, 'packages'])
        ->name('billing.packages');
    Route::get('billing/hosting', [BillingPlanController::class, 'hosting'])
        ->name('billing.hosting');
    Route::get('projects/marketplaces', MarketplaceController::class)
        ->name('projects.marketplaces');
    Route::get('projects/jobs/mine', MobileUserJobsController::class)
        ->middleware('mobile.auth')
        ->name('projects.jobs.mine');
    Route::post('projects/jobs', CreateMobileProjectJobController::class)
        ->middleware('mobile.auth')
        ->name('projects.jobs.create');
    Route::get('gamification/badges/{userId}', GamificationBadgesController::class)
        ->whereNumber('userId')
        ->name('gamification.badges');
});

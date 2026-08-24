<?php

use App\Http\Controllers\V1\AuthController;
use App\Http\Controllers\V1\Earnings\AnalyticsController;
use App\Http\Controllers\V1\Explore\ExploreController;
use App\Http\Controllers\V1\Rolls\RollsController;
use App\Http\Controllers\V1\Timeline\FeedController;
use App\Http\Controllers\V1\User\BankInformationController;
use App\Http\Controllers\V1\User\ReferralController;
use App\Http\Controllers\V1\User\SocialController;
use App\Http\Controllers\V1\User\TransactionController;
use App\Http\Controllers\V1\User\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shared v1 routes (web + API)
|--------------------------------------------------------------------------
|
| Loaded twice from bootstrap/app.php:
|   - /v1/*        (preferred — same format for web + mobile clients)
|   - /api/v1/*    (backward-compatible alias)
|
*/

Route::prefix('v1')->group(function () {

    Route::get('/', function () {
        return response()->json([
            'name' => 'Welcome to Payhankey API',
            'version' => '5.60.40',
            'status' => 'online',
        ]);
    });

    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:10,1');

    Route::post('/verify/otp', [AuthController::class, 'verifyOTP'])
        ->middleware('throttle:10,1');

    Route::post('/resend/otp', [AuthController::class, 'resendOTP'])
        ->middleware('throttle:10,1');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:20,1');

    Route::post('/logout', [AuthController::class, 'logout'])
        ->middleware(['auth:api,web', 'throttle:60,1']);

    Route::middleware('auth:api,web')->group(function () {

        Route::prefix('user')->group(function () {
            Route::get('/me', [UserController::class, 'me']);
            Route::match(['put', 'patch', 'post'], '/profile', [UserController::class, 'updateProfile']);
            Route::get('/socials', [SocialController::class, 'show']);
            Route::match(['put', 'patch', 'post'], '/socials', [SocialController::class, 'update']);
            Route::post('/onboard', [UserController::class, 'onboardUser']);
            Route::get('/currency/list', [UserController::class, 'currency']);
            Route::get('/channel', [UserController::class, 'channel']);
            Route::get('/profile/{username}', [UserController::class, 'profile']);
            Route::get('/search', [UserController::class, 'search']);
            Route::post('/toggle/follow', [UserController::class, 'toggle']);
            Route::get('/bank', [BankInformationController::class, 'show']);
            Route::post('/bank', [BankInformationController::class, 'store']);
            Route::put('/bank', [BankInformationController::class, 'update']);
            Route::get('/transactions', [TransactionController::class, 'index']);
            Route::get('/referrals', [ReferralController::class, 'index']);
        });

        Route::prefix('explore')->group(function () {
            Route::get('/trending', [ExploreController::class, 'trending']);
            Route::get('/trending/hashtags', [ExploreController::class, 'trendingHashTags']);
            Route::get('/trending/members', [ExploreController::class, 'trendingMembers']);
            Route::get('/trending/hashtag/post', [ExploreController::class, 'getHastagPost']);
        });

        Route::prefix('timeline')->group(function () {
            Route::get('/feed', [FeedController::class, 'feed']);
            Route::post('/post', [FeedController::class, 'createPost']);
            Route::post('/like/toggle', [FeedController::class, 'toggleLikePost']);
            Route::post('/comment', [FeedController::class, 'postComment']);
            Route::get('/post/{postId}', [FeedController::class, 'viewPost']);
            Route::delete('/delete/post/{postId}', [FeedController::class, 'deletePost']);
        });

        Route::prefix('rolls')->group(function () {
            Route::get('/', [RollsController::class, 'index']);
            Route::get('/{videoId}/comments', [RollsController::class, 'comments']);
            Route::get('/{videoId}', [RollsController::class, 'show']);
        });

        Route::prefix('earnings')->group(function () {
            Route::get('/analytics/monthly', [AnalyticsController::class, 'monthly']);
            Route::get('/analytics/yearly', [AnalyticsController::class, 'yearly']);
        });
    });
});

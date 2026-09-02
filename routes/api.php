<?php

use App\Http\Controllers\V1\AuthController;
use App\Http\Controllers\V1\Blog\BlogController;
use App\Http\Controllers\V1\Community\CommunityController;
use App\Http\Controllers\V1\Community\CommunityMembershipController;
use App\Http\Controllers\V1\Community\CommunityPostController;
use App\Http\Controllers\V1\Earnings\AnalyticsController;
use App\Http\Controllers\V1\Explore\ExploreController;
use App\Http\Controllers\V1\Rolls\RollsController;
use App\Http\Controllers\V1\Timeline\BookmarkController;
use App\Http\Controllers\V1\Timeline\FeedController;
use App\Http\Controllers\V1\Timeline\PostActionController;
use App\Http\Controllers\V1\Timeline\PostAnalyticsController;
use App\Http\Controllers\V1\User\BankInformationController;
use App\Http\Controllers\V1\User\LevelController;
use App\Http\Controllers\V1\User\ReferralController;
use App\Http\Controllers\V1\User\SocialController;
use App\Http\Controllers\V1\User\TransactionController;
use App\Http\Controllers\V1\User\UserController;
use App\Http\Controllers\V1\User\WalletController;
use App\Http\Controllers\V1\Webhook\PaymentWebhookController;
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

    Route::post('/webhooks/flutterwave', [PaymentWebhookController::class, 'flutterwave']);

    Route::post('/logout', [AuthController::class, 'logout'])
        ->middleware(['auth:api,web', 'throttle:60,1']);

    Route::prefix('blogs')->group(function () {
        Route::get('/', [BlogController::class, 'index']);
        Route::get('/{slug}', [BlogController::class, 'show']);
    });

    Route::middleware('auth:api,web')->group(function () {

        Route::prefix('user')->group(function () {
            Route::get('/me', [UserController::class, 'me']);
            Route::get('/levels', [LevelController::class, 'index']);
            Route::post('/levels/{levelId}/checkout', [LevelController::class, 'checkout'])->whereUuid('levelId');
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
            Route::get('/wallet', [WalletController::class, 'show']);
        });

        Route::prefix('explore')->group(function () {
            Route::get('/trending', [ExploreController::class, 'trending']);
            Route::get('/trending/hashtags', [ExploreController::class, 'trendingHashTags']);
            Route::get('/trending/members', [ExploreController::class, 'trendingMembers']);
            Route::get('/trending/hashtag/post', [ExploreController::class, 'getHastagPost']);
        });

        Route::prefix('timeline')->group(function () {
            Route::get('/feed', [FeedController::class, 'feed']);
            Route::get('/bookmarks', [BookmarkController::class, 'index']);
            Route::post('/bookmark/toggle', [BookmarkController::class, 'toggle']);
            Route::post('/post/hide', [PostActionController::class, 'hide']);
            Route::post('/post/report', [PostActionController::class, 'report']);
            Route::post('/post', [FeedController::class, 'createPost']);
            Route::match(['put', 'patch', 'post'], '/post/{postId}', [FeedController::class, 'updatePost']);
            Route::post('/like/toggle', [FeedController::class, 'toggleLikePost']);
            Route::post('/comment', [FeedController::class, 'postComment']);
            Route::get('/post/{postId}', [FeedController::class, 'viewPost']);
            Route::get('/post/{postId}/analytics', [PostAnalyticsController::class, 'show']);
            Route::delete('/delete/post/{postId}', [FeedController::class, 'deletePost']);
        });

        Route::prefix('rolls')->group(function () {
            Route::get('/', [RollsController::class, 'index']);
            Route::get('/top', [RollsController::class, 'top']);
            Route::post('/{videoId}/play', [RollsController::class, 'recordPlay']);
            Route::post('/{videoId}/watch', [RollsController::class, 'recordWatch']);
            Route::get('/{videoId}/comments', [RollsController::class, 'comments']);
            Route::get('/{videoId}', [RollsController::class, 'show']);
        });

        Route::prefix('earnings')->group(function () {
            Route::get('/overview', [AnalyticsController::class, 'overview']);
            Route::get('/analytics/monthly', [AnalyticsController::class, 'monthly']);
            Route::get('/analytics/yearly', [AnalyticsController::class, 'yearly']);
        });

        Route::prefix('communities')->group(function () {
            Route::get('/categories', [CommunityController::class, 'categories']);
            Route::get('/', [CommunityController::class, 'index']);
            Route::post('/', [CommunityController::class, 'store']);

            Route::post('/invites/{token}/accept', [CommunityMembershipController::class, 'acceptInviteByToken']);
            
            Route::get('/s/{slug}', [CommunityController::class, 'showBySlug']);
            Route::post('/s/{slug}/join', [CommunityController::class, 'joinBySlug']);
            Route::get('/{id}', [CommunityController::class, 'show'])->whereUuid('id');

            Route::get('/{id}/posts', [CommunityPostController::class, 'index'])->whereUuid('id');
            Route::post('/{id}/posts', [CommunityPostController::class, 'store'])->whereUuid('id');
            Route::get('/{id}/posts/{postId}/comments', [CommunityPostController::class, 'comments'])->whereUuid('id')->whereUuid('postId');
            Route::post('/{id}/posts/{postId}/like/toggle', [CommunityPostController::class, 'toggleLike'])->whereUuid('id')->whereUuid('postId');
            Route::post('/{id}/posts/{postId}/comments', [CommunityPostController::class, 'storeComment'])->whereUuid('id')->whereUuid('postId');
            Route::post('/{id}/posts/{postId}/view', [CommunityPostController::class, 'recordView'])->whereUuid('id')->whereUuid('postId');

            Route::post('/{id}/join', [CommunityController::class, 'join'])->whereUuid('id');
            Route::post('/{id}/join/accept-invite', [CommunityMembershipController::class, 'acceptDirectInvite'])->whereUuid('id');
            Route::post('/{id}/leave', [CommunityMembershipController::class, 'leave'])->whereUuid('id');
            Route::get('/{id}/join-requests', [CommunityMembershipController::class, 'joinRequests'])->whereUuid('id');
            Route::post('/{id}/join-requests/{requestId}/approve', [CommunityMembershipController::class, 'approveJoinRequest'])->whereUuid('id');
            Route::post('/{id}/join-requests/{requestId}/deny', [CommunityMembershipController::class, 'denyJoinRequest'])->whereUuid('id');
            Route::get('/{id}/invites', [CommunityMembershipController::class, 'invites'])->whereUuid('id');
            Route::post('/{id}/invites/direct', [CommunityMembershipController::class, 'inviteDirect'])->whereUuid('id');
            Route::post('/{id}/invites/link', [CommunityMembershipController::class, 'generateLinkInvite'])->whereUuid('id');
            Route::delete('/{id}/invites/link', [CommunityMembershipController::class, 'revokeLinkInvite'])->whereUuid('id');
            Route::delete('/{id}/invites/{inviteId}', [CommunityMembershipController::class, 'revokeDirectInvite'])->whereUuid('id');
        });
    });
});

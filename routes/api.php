<?php

use Illuminate\Http\Request;
use App\Http\Controllers\V1\AuthController;
use App\Http\Controllers\V1\Timeline\FeedController;
use App\Http\Controllers\V1\User\UserController;
use Illuminate\Support\Facades\Route;


Route::prefix('v1')->group(function () {

    Route::get('/', function () {
        return response()->json([
            'name' => 'Welcome to Payhankey API',
            'version' => '5.60.40',
            'status' => 'online'
        ]);
    });

    // 🔐 Register (lower throttle to prevent spam account creation)
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:10,1'); // 10 requests per minute

    Route::post('/verify/otp', [AuthController::class, 'verifyOTP'])
        ->middleware('throttle:10,1');

    Route::post('/resend/otp', [AuthController::class, 'resendOTP'])
        ->middleware('throttle:10,1');

    // 🔑 Login (strict protection against brute force)
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:20,1'); // 20 requests per minute per IP

    // 🚪 Logout (light throttle, must be authenticated)
    Route::post('/logout', [AuthController::class, 'logout'])
        ->middleware(['auth:api', 'throttle:60,1']); // 60/min for safety

    Route::middleware('auth:api')->group(function () {
        Route::prefix('user')->group(function () {
            Route::get('/me', [UserController::class, 'me']);
            Route::post('/onboard', [UserController::class, 'onboardUser']);
        });

        Route::prefix('timeline')->group(function () {
            Route::get('feed', [FeedController::class, 'feed']);
            Route::post('post', [FeedController::class, 'createPost']);
            Route::post('like/toggle', [FeedController::class, 'toggleLikePost']);
            Route::post('comment', [FeedController::class, 'postComment']);
            Route::get('post/{postId}', [FeedController::class, 'viewPost']);
           
        });





        // Route::get('/user', function (Request $request) {
        //     return $request->user();
        // });
    });
});

<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\Wallet;
use App\Services\UserServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class UserController extends Controller
{
    protected $userservices;

    public function __construct(UserServices $userservices)
    {
        $this->userservices = $userservices;
    }
    
    public function me(Request $request)
    {
        try {

            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated user'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'message' => 'User Resources',
                'data' => [
                    'user' => new UserResource($user),
                    'level' => $this->userservices->activeLevel($user)
                ],
            ], 200);
        } catch (Throwable $e) {

            Log::error('Failed to fetch authenticated user', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => optional($request->user())->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch user profile at this time'
            ], 500);
        }
    }

    public function onboardUser(Request $request)
    {

        $validated = $request->validate([
            'heard' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string'],
        ]);


        try {

            $user = $request->user();

            $userInfo = User::where('id', $user->id)->first();
            $userInfo->is_onboarded = true;

            $userInfo->heard = $validated['heard'];
            $userInfo->save();

            $wl = Wallet::where('user_id', $user->id)->first();
            $wl->currency = $validated['currency'];
            $wl->save();

             return response()->json([
                'success' => true,
                'message' => 'User Onboarded successfully'
            ], 201);


        } catch (Throwable $e) {

            Log::error('Failed to fetch authenticated user', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                // 'user_id' => optional($request->user())->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch user profile at this time'
            ], 500);
        }
    }
}

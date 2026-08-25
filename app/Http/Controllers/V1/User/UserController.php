<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Currency;
use App\Models\Post;
use App\Models\Profile;
use App\Models\User;
use App\Models\Wallet;
use App\Services\FeedService;
use App\Services\FollowService;
use App\Services\UserServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

class UserController extends Controller
{
    protected $userservices;
    protected $followservice;
    protected $feedservice;

    public function __construct(UserServices $userservices, FollowService $followservice, FeedService $feedservice)
    {
        $this->userservices = $userservices;
        $this->followservice = $followservice;
        $this->feedservice = $feedservice;
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

            $user->load('profile');

            return response()->json([
                'success' => true,
                'message' => 'User Resources',
                'data' => [
                    'user' => new UserResource($user),
                    'level' => $this->userservices->activeLevel($user),
                    'baseCurrency' => userBaseCurrency($user->id),
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
                'message' => 'Unable to fetch user profile at this time',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => optional($request->user())->id,
            ], 500);
        }
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        if ($request->has('gender') && is_string($request->input('gender'))) {
            $request->merge(['gender' => strtolower(trim($request->input('gender')))]);
        }

        $validated = $request->validate([
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before_or_equal:' . now()->subYears(13)->toDateString()],
            'gender' => ['sometimes', 'nullable', 'in:male,female'],
            'location' => ['sometimes', 'nullable', 'string', 'max:50'],
            'about' => ['sometimes', 'nullable', 'string', 'max:160'],
        ], [
            'date_of_birth.before_or_equal' => 'You must be at least 13 years old to use Payhankey.',
        ]);

        try {
            $payload = [];
            foreach (['date_of_birth', 'gender', 'location', 'about'] as $field) {
                if (! array_key_exists($field, $validated)) {
                    continue;
                }

                $value = $validated[$field];
                $payload[$field] = is_string($value) && trim($value) === '' ? null : $value;
            }

            $profile = Profile::updateOrCreate(
                ['user_id' => $user->id],
                $payload
            );

            $user->setRelation('profile', $profile);

            return response()->json([
                'success' => true,
                'message' => 'Profile updated',
                'data' => [
                    'user' => new UserResource($user),
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to update profile', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to update profile at this time',
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

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

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

    public function profile(Request $request, $username)
    {
        try {

            $user = $request->user();

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            $profile = User::with('profile')->where('username', $username)->select(['id', 'avatar', 'name', 'username', 'followers', 'following', 'status'])->first();

            if (! $profile) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            $posts = $this->feedservice->getUserPosts($profile->id, $user->id, 8);

            $totalPostsQuery = Post::where('user_id', $profile->id);
            if ($user->id !== $profile->id) {
                $totalPostsQuery->where('status', 'LIVE');
            }

            return response()->json([
                'success' => true,
                'message' => 'User Profile fetched',
                'level' => $this->userservices->activeLevel($profile),
                'baseCurrency' => userBaseCurrency($profile->id),
                'total_posts' => $totalPostsQuery->count(),
                'profile' => $profile,
                'data' => $posts,
            ], 200);
        } catch (Throwable $e) {

            Log::error('Failed to fetch user', [
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

    public function currency(Request $request)
    {
        try {

            $user = $request->user();

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            $currency = Currency::where('is_active', true)->select(['symbol', 'code', 'country'])->get();

            return response()->json([
                'success' => true,
                'message' => 'Currency List',
                'data' => $currency
            ], 200);
        } catch (Throwable $e) {

            Log::error('Failed to fetch currency', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                // 'user_id' => optional($request->user())->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch currency at this time'
            ], 500);
        }
    }

    public function channel(Request $request)
    {
        try {

            $user = $request->user();

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            $list = [
                'Facebook',
                'Freebyz',
                'Twitter(X)',
                'Google',

            ];

            return response()->json([
                'success' => true,
                'message' => 'How you heard list',
                'data' => $list
            ], 200);
        } catch (Throwable $e) {

            Log::error('Failed to fetch how you heard', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                // 'user_id' => optional($request->user())->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch how you heard at this time'
            ], 500);
        }
    }

    public function search(Request $request)
    {
        $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:100'],
        ]);

        try {
            $users = $this->userservices->search($request->query('q'), 10);
            return response()->json(['success' => true, 'data' => $users]);
        } catch (Throwable $e) {
            Log::error('User search failed', ['term' => $request->query('q'), 'message' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Search failed'], 500);
        }
    }

     public function toggle(Request $request)
    {
        $authUser = $request->user();

        if (! $authUser) {
            return response()->json([
                'success' => false,
                'message' => 'User Unauthenticated',
            ], 401);
        }

        try {

            $userId = $request->query('id');

            $targetUser = User::where('id', $userId)->firstOrFail();

            $result = $this->followservice->toggle($authUser, $targetUser);

            return response()->json([
                'success' => true,
                'message' => $result['following'] ? 'Followed successfully' : 'Unfollowed successfully',
                'data' => $result,
            ]);

        } catch (ModelNotFoundException $e) {

            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);

        } catch (\InvalidArgumentException $e) {

            return response()->json([
                'success' => false,
                'message' => 'You cannot follow yourself',
            ], 422);

        } catch (Throwable $e) {

            Log::error('Failed to toggle follow', [
                'auth_user_id' => $authUser->id,
                'target_username' => $targetUser->username,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to process follow request at this time',
            ], 500);
        }


    }



}

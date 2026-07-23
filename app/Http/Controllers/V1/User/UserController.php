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
        
            $posts = $this->feedservice->getUserPosts($profile->id, $user->id, 8);
            // $posts = Post::with(['user:id,username,name'])
            //     ->where('user_id', $user->id)
            //     ->with(['video' => function ($q) {
            //         $q->where('processing_status', 'completed')
            //             ->select(['id', 'post_id', 'path', 'hd_path', 'thumbnail_path', 'duration', 'width', 'height']);
            //     }])
            //     ->with(['images' => function ($q) {
            //         $q->where('processing_status', 'completed')
            //             ->select(['id', 'post_id', 'path', 'thumbnail_path', 'full_path', 'width', 'height']);
            //     }])
            //     ->with(['postComments' => function ($q) {
            //         $q->with('user:id,username,name')
            //             ->whereIn('id', function ($sub) {
            //                 $sub->select('id')
            //                     ->from(DB::raw('(SELECT id, post_id, ROW_NUMBER() OVER (PARTITION BY post_id ORDER BY created_at DESC) AS rn FROM comments) AS ranked'))
            //                     ->where('rn', '<=', 3);
            //             })
            //             ->latest();
            //     }])
            //     ->where('status', 'LIVE')
            //     ->latest('created_at')
            //     ->select(['id', 'user_id', 'content', 'views', 'likes', 'comments', 'has_video', 'has_images', 'media_status', 'created_at'])
            //     ->paginate(10);

            // $posts->getCollection()->transform(function (Post $post) {
            //     $post->media = null;

            //     if ($post->media_status === 'completed') {
            //         if ($post->has_video && $post->video) {
            //             $post->media = [
            //                 'type' => 'video',
            //                 'sd_url' => $post->video->path,
            //                 'hd_url' => $post->video->hd_path,
            //                 'poster_url' => $post->video->thumbnail_path,
            //                 'duration' => $post->video->duration,
            //                 'width' => $post->video->width,
            //                 'height' => $post->video->height,
            //             ];
            //         } elseif ($post->has_images && $post->images->isNotEmpty()) {
            //             $post->media = [
            //                 'type' => 'images',
            //                 'items' => $post->images->map(fn($img) => [
            //                     'thumb_url' => $img->thumbnail_path,
            //                     'medium_url' => $img->path,
            //                     'full_url' => $img->full_path,
            //                     'width' => $img->width,
            //                     'height' => $img->height,
            //                 ])->values(),
            //             ];
            //         }
            //     }

            //     $post->comments_preview = $post->postComments->map(fn($c) => [
            //         'id' => $c->id,
            //         'user' => $c->user?->only(['id', 'username', 'name']),
            //         'message' => $c->message,
            //         'created_at' => $c->created_at,
            //     ])->values();

            //     unset($post->video, $post->images, $post->postComments);

            //     return $post;
            // });
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
        return response()->json([
            'success' => true,
            'message' => 'User Profile fetched',
            'profile' => $profile,
            'data' => $posts
        ], 200);
    }

    public function currency(Request $request)
    {
        try {

            $user = $request->user();

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            $currency = Currency::where('is_active', true)->select(['symbol', 'country'])->get();

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

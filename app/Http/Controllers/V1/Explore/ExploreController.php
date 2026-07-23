<?php

namespace App\Http\Controllers\V1\Explore;

use App\Http\Controllers\Controller;
use App\Services\FeedService;
use App\Services\HashTagPost;
use App\Services\TrendingHashTags;
use App\Services\TrendingMembers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;


class ExploreController extends Controller
{

    protected $trendingHashTags;
    protected $trendingMembers;
    protected $hashTagPost;
    protected $feedService;

    public function __construct(TrendingHashTags $trendingHashTags, TrendingMembers $trendingMembers, HashTagPost $hashTagPost, FeedService $feedService)
    {
        $this->trendingHashTags = $trendingHashTags;
        $this->trendingMembers = $trendingMembers;
        $this->hashTagPost = $hashTagPost;
        $this->feedService = $feedService;
    }

    public function trending(Request $request)
    {
        try {

            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User Unauthenticated'
                ], 401);
            }

            $trending =  $this->trendingHashTags->getTrending(5);
            $members = $this->trendingMembers->trending(5);

            return response()->json([
                'success' => true,
                'message' => 'Trending Hash Tags & Members - Top 5',
                'data' => [
                    'hashtags' => $trending,
                    'members' => $members
                ],
            ], 200);
        } catch (Throwable $e) {

            Log::error('Failed to fetch Trending', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                // 'user_id' => optional($request->user())->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch Trending'
            ], 500);
        }
    }

    public function trendingHashTags(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User Unauthenticated'
                ], 401);
            }

            $allTrendingHashTags = $this->trendingHashTags->getAllTrending(8);

            return response()->json([
                'success' => true,
                'message' => 'Trending Hash Tags',
                'data' => $allTrendingHashTags
            ], 200);
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



    public function getHastagPost(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User Unauthenticated',
            ], 401);
        }

        $tag = $request->query('hashtag');

        if (!$tag) {
            return response()->json([
                'success' => false,
                'message' => 'Hashtag query parameter is required',
            ], 422);
        }

        try {
            $posts = $this->feedService->getHashtagPosts($tag, $user->id, 8);//hashTagPost->getHashtagPosts($tag, 8);

            return response()->json([
                'success' => true,
                'message' => 'Hashtag Post',
                'data' => $posts,
            ]);
        } catch (ModelNotFoundException $e) {

            return response()->json([
                'success' => false,
                'message' => 'Hashtag not found',
            ], 404);
        } catch (Throwable $e) {

            Log::error('Failed to fetch hashtag posts', [
                'tag' => $tag,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch posts for this hashtag at this time',
            ], 500);
        }
    }



    public function trendingMembers(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User Unauthenticated'
                ], 401);
            }

            $trendingmembers = $this->trendingMembers->allTrendingMembers(8);

            return response()->json([
                'success' => true,
                'message' => 'Trending Members',
                'data' => $trendingmembers
            ], 200);
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

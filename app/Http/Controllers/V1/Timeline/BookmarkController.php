<?php

namespace App\Http\Controllers\V1\Timeline;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\BookmarkService;
use App\Services\FeedService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class BookmarkController extends Controller
{
    public function __construct(
        protected BookmarkService $bookmarkService,
        protected FeedService $feedService,
    ) {}

    /**
     * GET /v1/timeline/bookmarks — paginated bookmarked posts for the auth user.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        try {
            $posts = $this->feedService->getBookmarkedPosts(
                $user->id,
                (int) ($validated['per_page'] ?? 10),
            );

            return response()->json([
                'success' => true,
                'message' => 'Bookmarked posts',
                'data' => $posts,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to load bookmarked posts', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load bookmarked posts',
            ], 500);
        }
    }

    /**
     * POST /v1/timeline/bookmark/toggle — bookmark or remove bookmark.
     */
    public function toggle(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'post_id' => ['required', 'uuid', 'exists:posts,id'],
        ]);

        try {
            $post = Post::findOrFail($validated['post_id']);
            $result = $this->bookmarkService->toggle($user, $post);

            return response()->json([
                'success' => true,
                'message' => $result['bookmarked'] ? 'Post bookmarked' : 'Bookmark removed',
                'data' => $result,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
            ], 404);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Failed to toggle bookmark', [
                'user_id' => $user->id,
                'post_id' => $validated['post_id'] ?? null,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to update bookmark',
            ], 500);
        }
    }
}

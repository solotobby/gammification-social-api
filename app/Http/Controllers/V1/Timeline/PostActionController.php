<?php

namespace App\Http\Controllers\V1\Timeline;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\PostActionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostActionController extends Controller
{
    public function __construct(protected PostActionService $postActionService) {}

    /**
     * POST /v1/timeline/post/hide — hide a post from the auth user's feeds.
     */
    public function hide(Request $request)
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
            $result = $this->postActionService->hide($user, $post);

            return response()->json([
                'success' => true,
                'message' => 'Post hidden from your feed',
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
            Log::error('Failed to hide post', [
                'user_id' => $user->id,
                'post_id' => $validated['post_id'] ?? null,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to hide post',
            ], 500);
        }
    }

    /**
     * POST /v1/timeline/post/report — report a post for moderation review.
     */
    public function report(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'post_id' => ['required', 'uuid', 'exists:posts,id'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        try {
            $post = Post::findOrFail($validated['post_id']);
            $result = $this->postActionService->report(
                $user,
                $post,
                $validated['reason'] ?? null,
            );

            return response()->json([
                'success' => true,
                'message' => $result['already_reported']
                    ? 'Post already reported'
                    : 'Post reported. Thanks for letting us know.',
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
            Log::error('Failed to report post', [
                'user_id' => $user->id,
                'post_id' => $validated['post_id'] ?? null,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to report post',
            ], 500);
        }
    }
}

<?php

namespace App\Http\Controllers\V1\Timeline;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\PostAnalyticsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostAnalyticsController extends Controller
{
    public function __construct(protected PostAnalyticsService $analyticsService) {}

    /**
     * GET /v1/timeline/post/{postId}/analytics — poster-only post performance.
     */
    public function show(Request $request, string $postId)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        try {
            $post = Post::query()->findOrFail($postId);
            $data = $this->analyticsService->forPost($post, $user->id);

            return response()->json([
                'success' => true,
                'message' => 'Post analytics',
                'data' => $data,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
            ], 404);
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        } catch (Throwable $e) {
            Log::error('Failed to load post analytics', [
                'post_id' => $postId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load post analytics',
            ], 500);
        }
    }
}

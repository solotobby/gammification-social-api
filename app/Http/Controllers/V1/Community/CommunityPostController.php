<?php

namespace App\Http\Controllers\V1\Community;

use App\Http\Controllers\Controller;
use App\Services\CommunityPostService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class CommunityPostController extends Controller
{
    public function __construct(protected CommunityPostService $communityPostService) {}

    /**
     * GET /v1/communities/{id}/posts — paginated feed (7 posts), 3 comment preview each.
     */
    public function index(Request $request, string $id)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        try {
            $posts = $this->communityPostService->listFeed(
                $user,
                $id,
                (int) ($validated['per_page'] ?? CommunityPostService::POSTS_PER_PAGE),
            );

            return response()->json([
                'success' => true,
                'message' => 'Community posts',
                'data' => $posts,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Community not found'], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('Failed to load community posts', [
                'user_id' => $user->id,
                'community_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to load posts'], 500);
        }
    }

    /**
     * GET /v1/communities/{id}/posts/{postId}/comments — paginated comments for a post.
     */
    public function comments(Request $request, string $id, string $postId)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        try {
            $comments = $this->communityPostService->listComments(
                $user,
                $id,
                $postId,
                (int) ($validated['per_page'] ?? CommunityPostService::COMMENTS_PER_PAGE),
            );

            return response()->json([
                'success' => true,
                'message' => 'Post comments',
                'data' => $comments,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Post not found'], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('Failed to load community post comments', [
                'user_id' => $user->id,
                'community_id' => $id,
                'post_id' => $postId,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to load comments'], 500);
        }
    }

    /**
     * POST /v1/communities/{id}/posts/{postId}/like/toggle
     */
    public function toggleLike(Request $request, string $id, string $postId)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        try {
            $data = $this->communityPostService->toggleLike($user, $id, $postId);

            return response()->json([
                'success' => true,
                'message' => $data['liked'] ? 'Post liked' : 'Post unliked',
                'data' => $data,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Post not found'], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('Failed to toggle community post like', [
                'user_id' => $user->id,
                'post_id' => $postId,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to update like'], 500);
        }
    }

    /**
     * POST /v1/communities/{id}/posts/{postId}/comments
     */
    public function storeComment(Request $request, string $id, string $postId)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:'.CommunityPostService::MAX_COMMENT_LENGTH],
        ]);

        try {
            $comment = $this->communityPostService->addComment(
                $user,
                $id,
                $postId,
                $validated['content'],
            );

            return response()->json([
                'success' => true,
                'message' => 'Comment added',
                'data' => $comment,
            ], 201);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Post not found'], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('Failed to add community post comment', [
                'user_id' => $user->id,
                'post_id' => $postId,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to add comment'], 500);
        }
    }

    /**
     * POST /v1/communities/{id}/posts/{postId}/view
     */
    public function recordView(Request $request, string $id, string $postId)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        try {
            $data = $this->communityPostService->recordView(
                $user,
                $id,
                $postId,
                $request->ip(),
            );

            return response()->json([
                'success' => true,
                'message' => $data['recorded'] ? 'View recorded' : 'View already recorded',
                'data' => $data,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Post not found'], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('Failed to record community post view', [
                'user_id' => $user->id,
                'post_id' => $postId,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to record view'], 500);
        }
    }

    /**
     * POST /v1/communities/{id}/posts — create a community post.
     * Both content and media are optional (send either, both, or neither).
     */
    public function store(Request $request, string $id)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'content' => [
                'sometimes',
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && countSocialWords((string) $value) > CommunityPostService::MAX_WORDS) {
                        $fail('Community posts cannot exceed '.number_format(CommunityPostService::MAX_WORDS).' words.');
                    }
                },
            ],
            'media' => ['sometimes', 'nullable', 'array', 'max:'.CommunityPostService::MAX_MEDIA],
            'media.*' => ['file', 'mimes:jpg,jpeg,png,gif,mp4,mov', 'max:20480'],
        ]);

        $mediaFiles = $request->file('media', []);
        if (! is_array($mediaFiles)) {
            $mediaFiles = array_filter([$mediaFiles]);
        }

        try {
            $data = $this->communityPostService->create($user, $id, $validated, $mediaFiles);

            return response()->json([
                'success' => true,
                'message' => 'Post created',
                'data' => $data,
            ], 201);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Community not found',
            ], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Failed to create community post', [
                'user_id' => $user->id,
                'community_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to create post',
            ], 500);
        }
    }
}

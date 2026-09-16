<?php

namespace App\Http\Controllers\V1\Timeline;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostBoost;
use App\Services\PostBoostService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class PostBoostController extends Controller
{
    public function __construct(
        protected PostBoostService $boostService
    ) {}

    /**
     * GET /v1/timeline/post/{postId}/boost/config
     */
    public function config(Request $request, string $postId)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        try {
            $post = Post::findOrFail($postId);

            if ((string) $post->user_id !== (string) $user->id) {
                return response()->json(['success' => false, 'message' => 'You can only boost your own posts.'], 403);
            }

            $config = $this->boostService->getBoostConfig($user, $post);

            return response()->json([
                'success' => true,
                'message' => 'Boost configuration loaded',
                'data' => $config,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Post not found'], 404);
        } catch (Throwable $e) {
            Log::error('Failed to load boost config', [
                'user_id' => $user->id,
                'post_id' => $postId,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to load boost config'], 500);
        }
    }

    /**
     * POST /v1/timeline/post/{postId}/boost
     */
    public function store(Request $request, string $postId)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'target_url' => ['required', 'url', 'max:2000'],
            'cta' => ['required', 'string', 'max:64'],
            'clicks' => ['required', 'integer', 'min:' . PostBoostService::MIN_CLICKS],
            'platform_payhankey' => ['sometimes', 'boolean'],
            'platform_partner' => ['sometimes', 'boolean'],
        ]);

        try {
            $post = Post::findOrFail($postId);

            if ((string) $post->user_id !== (string) $user->id) {
                return response()->json(['success' => false, 'message' => 'You can only boost your own posts.'], 403);
            }

            $boost = $this->boostService->createBoost($user, $post, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Post boosted successfully',
                'data' => [
                    'id' => $boost->id,
                    'post_id' => $boost->post_id,
                    'user_id' => $boost->user_id,
                    'cta' => $boost->cta,
                    'target_url' => $boost->target_url,
                    'total_clicks' => $boost->total_clicks,
                    'requested_clicks' => $boost->total_clicks,
                    'delivered_clicks' => $boost->delivered_clicks,
                    'remaining_clicks' => $boost->remaining_clicks,
                    'pk_cost' => $boost->pk_cost,
                    'total_cost' => $boost->pk_cost,
                    'status' => $boost->status,
                    'spendable_pk' => (int) ($user->wallet?->fresh()?->paykoin_spendable ?? 0),
                ],
            ], 201);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Post not found'], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            Log::error('Failed to create post boost', [
                'user_id' => $user->id,
                'post_id' => $postId,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to launch boost campaign'], 500);
        }
    }

    /**
     * GET /v1/boosts
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $status = $request->query('status');
        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));

        $boosts = $this->boostService->listUserBoosts($user, $status, $perPage);

        return response()->json([
            'success' => true,
            'message' => 'User boost campaigns',
            'data' => $boosts,
        ]);
    }

    /**
     * GET /v1/boosts/{id}
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        try {
            $details = $this->boostService->getBoostDetails($user, $id);

            return response()->json([
                'success' => true,
                'message' => 'Campaign details loaded',
                'data' => $details,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Boost campaign not found'], 404);
        } catch (Throwable $e) {
            Log::error('Failed to load boost details', [
                'user_id' => $user->id,
                'boost_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to load campaign details'], 500);
        }
    }

    /**
     * POST /v1/boosts/{id}/pause
     */
    public function pause(Request $request, string $id)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        try {
            $boost = $this->boostService->pauseBoost($user, $id);

            return response()->json([
                'success' => true,
                'message' => 'Campaign paused',
                'data' => $boost,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Boost campaign not found'], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('Failed to pause boost', [
                'user_id' => $user->id,
                'boost_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to pause campaign'], 500);
        }
    }

    /**
     * POST /v1/boosts/{id}/resume
     */
    public function resume(Request $request, string $id)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        try {
            $boost = $this->boostService->resumeBoost($user, $id);

            return response()->json([
                'success' => true,
                'message' => 'Campaign resumed',
                'data' => $boost,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Boost campaign not found'], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('Failed to resume boost', [
                'user_id' => $user->id,
                'boost_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to resume campaign'], 500);
        }
    }

    /**
     * POST|GET /v1/boosts/{id}/click
     */
    public function click(Request $request, string $id)
    {
        try {
            $boost = PostBoost::findOrFail($id);
            $platform = (string) $request->input('platform', $request->query('platform', 'payhankey'));

            $targetUrl = $this->boostService->recordClick($boost, $request, $platform);

            if ($request->wantsJson() || $request->is('api/*') || $request->is('v1/*') || $request->ajax()) {
                return response()->json([
                    'status' => 'success',
                    'success' => true,
                    'message' => 'Click recorded successfully',
                    'data' => [
                        'target_url' => $targetUrl,
                        'cta' => $boost->cta,
                    ],
                ]);
            }

            return redirect()->away($targetUrl);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Boost campaign not found'], 404);
        } catch (Throwable $e) {
            Log::error('Failed to record boost click', [
                'boost_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to record click'], 500);
        }
    }
}

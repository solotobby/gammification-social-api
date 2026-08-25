<?php

namespace App\Http\Controllers\V1\Rolls;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessView;
use App\Models\PostVideo;
use App\Services\RollsService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class RollsController extends Controller
{
    public function __construct(protected RollsService $rollsService) {}

    /**
     * GET /v1/rolls — random video rolls, 5 per page.
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            if (! $user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            $exclude = array_values(array_filter((array) $request->input('exclude', [])));

            $rolls = $this->rollsService->getRolls(
                $user->id,
                RollsService::PER_PAGE,
                $exclude,
            );

            return response()->json([
                'success' => true,
                'message' => 'Rolls feed',
                'data' => $rolls,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to load rolls', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load rolls',
            ], 500);
        }
    }

    /**
     * GET /v1/rolls/top — top 5 rolls by combined engagement score.
     */
    public function top(Request $request)
    {
        try {
            $user = $request->user();
            if (! $user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            $data = $this->rollsService->getTopRolls($user->id);

            return response()->json([
                'success' => true,
                'message' => 'Top rolls',
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to load top rolls', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load top rolls',
            ], 500);
        }
    }

    /**
     * POST /v1/rolls/{videoId}/play — increment play count when a roll starts playing.
     */
    public function recordPlay(Request $request, string $videoId)
    {
        try {
            $user = $request->user();
            if (! $user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            $video = $this->rollsService->recordPlay($videoId);

            return response()->json([
                'success' => true,
                'message' => 'Play recorded',
                'data' => [
                    'video_id' => $video->id,
                    'post_id' => $video->post_id,
                    'play_count' => (int) $video->play_count,
                ],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Roll not found',
            ], 404);
        } catch (Throwable $e) {
            Log::error('Failed to record roll play', [
                'video_id' => $videoId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to record play',
            ], 500);
        }
    }

    /**
     * POST /v1/rolls/{videoId}/watch — record watch duration in seconds.
     *
     * Body: watch_time or watch_seconds (required), is_first_play (optional)
     */
    public function recordWatch(Request $request, string $videoId)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $this->mergeWatchPayload($request);

        $validated = $request->validate([
            'watch_time' => 'required_without:watch_seconds|nullable|numeric|min:0',
            'watch_seconds' => 'required_without:watch_time|nullable|numeric|min:0',
            'is_first_play' => 'sometimes|boolean',
        ]);

        $watchSeconds = (float) ($validated['watch_time'] ?? $validated['watch_seconds']);

        try {
            $video = $this->rollsService->recordWatch(
                $videoId,
                $watchSeconds,
                (bool) ($validated['is_first_play'] ?? false),
            );

            return response()->json([
                'success' => true,
                'message' => 'Watch time recorded',
                'data' => [
                    'video_id' => $video->id,
                    'post_id' => $video->post_id,
                    'play_count' => (int) $video->play_count,
                    'avg_watch_time' => $video->avg_watch_time !== null
                        ? round((float) $video->avg_watch_time, 2)
                        : null,
                ],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Roll not found',
            ], 404);
        } catch (Throwable $e) {
            Log::error('Failed to record roll watch time', [
                'video_id' => $videoId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to record watch time',
            ], 500);
        }
    }

    /**
     * Accept raw JSON bodies (e.g. sendBeacon) and alias watch_seconds → watch_time.
     */
    protected function mergeWatchPayload(Request $request): void
    {
        if ($request->has('watch_time') || $request->has('watch_seconds')) {
            return;
        }

        $raw = json_decode($request->getContent(), true);
        if (is_array($raw)) {
            $request->merge($raw);
        }
    }

    /**
     * GET /v1/rolls/{videoId} — exact video + 5 more random rolls + comments (5).
     */
    public function show(Request $request, string $videoId)
    {
        try {
            $user = $request->user();
            if (! $user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            $payload = $this->rollsService->getRoll($videoId, $user->id);

            ProcessView::dispatch($payload['current']['post_id'], $user->id)->afterCommit();

            return response()->json([
                'success' => true,
                'message' => 'Roll details',
                'data' => $payload,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Roll not found',
            ], 404);
        } catch (Throwable $e) {
            Log::error('Failed to load roll', [
                'video_id' => $videoId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load roll',
            ], 500);
        }
    }

    /**
     * GET /v1/rolls/{videoId}/comments — paginated comments (5 per page).
     */
    public function comments(Request $request, string $videoId)
    {
        try {
            $user = $request->user();
            if (! $user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            $video = PostVideo::query()
                ->where('id', $videoId)
                ->where('processing_status', 'completed')
                ->firstOrFail();

            $comments = $this->rollsService->getComments(
                $video->post_id,
                RollsService::COMMENTS_PER_PAGE,
            );

            return response()->json([
                'success' => true,
                'message' => 'Roll comments',
                'data' => $comments,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Roll not found',
            ], 404);
        } catch (Throwable $e) {
            Log::error('Failed to load roll comments', [
                'video_id' => $videoId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load comments',
            ], 500);
        }
    }
}

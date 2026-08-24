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

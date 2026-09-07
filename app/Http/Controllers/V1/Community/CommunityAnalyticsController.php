<?php

namespace App\Http\Controllers\V1\Community;

use App\Http\Controllers\Controller;
use App\Services\CommunityAnalyticsService;
use App\Services\CommunityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class CommunityAnalyticsController extends Controller
{
    public function __construct(
        protected CommunityAnalyticsService $analyticsService,
        protected CommunityService $communityService,
    ) {}

    /**
     * GET /v1/communities/{id}/analytics — overview metrics, top posts, and recent members.
     */
    public function analytics(Request $request, string $id)
    {
        return $this->respond($request, function () use ($request, $id) {
            $user = resolveApiUser($request);
            $community = $this->communityService->getCommunityById($id);

            $data = $this->analyticsService->getAnalytics($user, $community);

            return [
                'message' => 'Community analytics retrieved successfully',
                'data' => $data,
            ];
        });
    }

    /**
     * @param  callable(): array{message: string, data: mixed}  $callback
     */
    private function respond(Request $request, callable $callback)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        try {
            $payload = $callback();

            return response()->json([
                'success' => true,
                'message' => $payload['message'],
                'data' => $payload['data'],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Community not found',
            ], 404);
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Community analytics failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to retrieve community analytics',
            ], 500);
        }
    }
}

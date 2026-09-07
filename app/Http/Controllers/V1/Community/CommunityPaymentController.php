<?php

namespace App\Http\Controllers\V1\Community;

use App\Http\Controllers\Controller;
use App\Services\CommunityService;
use App\Services\CommunitySubscriptionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class CommunityPaymentController extends Controller
{
    public function __construct(
        protected CommunitySubscriptionService $subscriptionService,
        protected CommunityService $communityService,
    ) {}

    /**
     * POST /v1/communities/{id}/subscribe — initialize paid community checkout.
     */
    public function subscribe(Request $request, string $id)
    {
        return $this->respond($request, function () use ($request, $id) {
            $user = resolveApiUser($request);
            $community = $this->communityService->getCommunityById($id);

            $idempotencyKey = $request->header('X-Idempotency-Key')
                ?? $request->input('idempotency_key');

            $data = $this->subscriptionService->generatePaymentLink($community, $user, $idempotencyKey);

            return [
                'message' => 'Payment initiated successfully',
                'data' => $data,
            ];
        });
    }

    /**
     * GET /v1/communities/{id}/subscription/status — check active subscription for viewer.
     */
    public function subscriptionStatus(Request $request, string $id)
    {
        return $this->respond($request, function () use ($request, $id) {
            $user = resolveApiUser($request);
            $community = $this->communityService->getCommunityById($id);

            $data = $this->subscriptionService->subscriptionStatus($community, $user);

            return [
                'message' => 'Subscription status retrieved successfully',
                'data' => $data,
            ];
        });
    }

    /**
     * GET /v1/communities/{id}/earnings — creator earnings and subscriber breakdown.
     */
    public function earnings(Request $request, string $id)
    {
        return $this->respond($request, function () use ($request, $id) {
            $user = resolveApiUser($request);
            $community = $this->communityService->getCommunityById($id);

            $period = $request->input('period', 'all');
            if (! in_array($period, ['7d', '30d', '90d', 'all'], true)) {
                $period = 'all';
            }

            $perPage = min(max((int) $request->input('per_page', 15), 1), 100);

            $data = $this->subscriptionService->payoutDashboard($user, $community, $period, $perPage);

            return [
                'message' => 'Community earnings retrieved successfully',
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
            Log::error('Community payment action failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Unable to process payment request',
            ], 500);
        }
    }
}

<?php

namespace App\Http\Controllers\V1\PayKoin;

use App\Http\Controllers\Controller;
use App\Services\PayKoinService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PostGiftController extends Controller
{
    public function __construct(
        protected PayKoinService $payKoinService,
    ) {}

    /**
     * GET /v1/gifts — list all available gift artifacts with optional tier grouping.
     * Accessible publicly; includes user's spendable balance if authenticated.
     */
    public function index(Request $request)
    {
        $artifacts = $this->payKoinService->giftArtifacts();
        $user = resolveApiUser($request);
        $spendable = 0;

        if ($user) {
            $user->loadMissing('wallet');
            $spendable = (int) ($user->wallet?->paykoin_spendable ?? 0);
        }

        $byTier = collect($artifacts)->groupBy('tier')->all();

        return response()->json([
            'success' => true,
            'message' => 'Gift catalog retrieved successfully',
            'data' => [
                'gifts' => $artifacts,
                'by_tier' => $byTier,
                'spendable' => $spendable,
            ],
        ]);
    }

    /**
     * POST /v1/gifts/send — send a gift artifact to a post creator.
     */
    public function send(Request $request)
    {
        return $this->respond($request, function () use ($request) {
            $request->validate([
                'artifact_id' => ['required', 'string'],
                'post_id' => ['required', 'string'],
                'post_type' => ['nullable', 'string', 'in:community_post,community,post,timeline'],
            ]);

            $user = resolveApiUser($request);
            $artifactId = $request->input('artifact_id');
            $postId = $request->input('post_id');
            $postType = $request->input('post_type', 'community_post');

            $result = $this->payKoinService->sendGift(
                $user,
                $artifactId,
                $postType,
                $postId,
            );

            return [
                'message' => 'Gift sent successfully',
                'data' => $result,
            ];
        });
    }

    /**
     * GET /v1/gifts/post/{type}/{id} — get gifts summary and recent gifts for a post.
     */
    public function postGifts(Request $request, string $type, string $id)
    {
        try {
            $limit = min(max((int) $request->input('limit', 20), 1), 50);
            $gifts = $this->payKoinService->giftsFor($type, $id, $limit);

            $user = resolveApiUser($request);
            $spendable = 0;
            if ($user) {
                $user->loadMissing('wallet');
                $spendable = (int) ($user->wallet?->paykoin_spendable ?? 0);
            }

            return response()->json([
                'success' => true,
                'message' => 'Post gifts retrieved successfully',
                'data' => [
                    ...$gifts,
                    'spendable' => $spendable,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
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
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first(),
                'errors' => $e->validator->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Post or creator not found',
            ], 404);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Gift sending failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Unable to send gift',
            ], 500);
        }
    }
}

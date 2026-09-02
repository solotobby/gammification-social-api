<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\LevelUpgradeCheckoutService;
use App\Services\LevelUpgradeService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class LevelController extends Controller
{
    public function __construct(
        protected LevelUpgradeService $levelUpgradeService,
        protected LevelUpgradeCheckoutService $levelUpgradeCheckoutService,
    ) {}

    /**
     * GET /v1/user/levels — list subscription levels and upgrade options for the auth user.
     */
    public function index(Request $request)
    {
        $user = resolveApiUser($request);

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        try {
            $data = $this->levelUpgradeService->listForUser($user);

            return response()->json([
                'success' => true,
                'message' => 'Subscription levels',
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to load subscription levels', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load subscription levels',
            ], 500);
        }
    }

    /**
     * POST /v1/user/levels/{levelId}/checkout — start upgrade payment (NGN → Korapay, others → Flutterwave).
     */
    public function checkout(Request $request, string $levelId)
    {
        $user = resolveApiUser($request);

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'billing_mode' => ['sometimes', 'in:subscription,payg'],
        ]);

        try {
            $result = $this->levelUpgradeCheckoutService->checkout(
                $user,
                $levelId,
                $validated['billing_mode'] ?? 'subscription',
                $request->header('Idempotency-Key'),
            );

            return response()->json([
                'success' => true,
                'message' => 'Checkout initialized',
                'data' => $result,
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Level not found'], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('Failed to initialize level upgrade checkout', [
                'user_id' => $user->id,
                'level_id' => $levelId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to initialize checkout',
            ], 500);
        }
    }
}

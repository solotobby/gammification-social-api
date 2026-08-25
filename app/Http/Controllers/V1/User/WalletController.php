<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class WalletController extends Controller
{
    public function __construct(protected WalletService $walletService) {}

    /**
     * GET /v1/user/wallet — all wallet balances and their total.
     */
    public function show(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        try {
            $data = $this->walletService->balances($user->id);

            return response()->json([
                'success' => true,
                'message' => 'Wallet balances',
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to load wallet balances', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load wallet balances at this time',
            ], 500);
        }
    }
}

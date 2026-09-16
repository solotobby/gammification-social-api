<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PayoutController extends Controller
{
    /**
     * Allowed statuses: only QUEUED and PAID/processed payouts.
     */
    public const ALLOWED_STATUSES = ['Queued', 'Paid'];

    /**
     * GET /v1/user/payouts
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        try {
            $statusFilter = $request->query('status');
            $perPage = max(1, min(50, (int) $request->query('per_page', 15)));

            // Compute summary totals across all queued and paid payouts for this user
            $baseQuery = Payout::query()
                ->where('user_id', $user->id)
                ->whereIn('status', self::ALLOWED_STATUSES);

            $totalPaid = (float) Payout::where('user_id', $user->id)
                ->where('status', 'Paid')
                ->sum('amount');

            $totalQueued = (float) Payout::where('user_id', $user->id)
                ->where('status', 'Queued')
                ->sum('amount');

            // Apply optional specific status filter within the allowed list
            $listQuery = clone $baseQuery;
            if ($statusFilter && in_array(ucfirst(strtolower($statusFilter)), self::ALLOWED_STATUSES, true)) {
                $listQuery->where('status', ucfirst(strtolower($statusFilter)));
            }

            $paginated = $listQuery->latest('created_at')->paginate($perPage);

            $paginated->getCollection()->transform(function (Payout $payout) {
                return [
                    'id' => $payout->id,
                    'month' => $payout->month,
                    'amount' => (float) $payout->amount,
                    'currency' => $payout->currency,
                    'status' => $payout->status,
                    'level' => $payout->level,
                    'total_engagement' => $payout->total_engagement,
                    'type' => $payout->type,
                    'created_at' => $payout->created_at?->toIso8601String(),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'User payouts retrieved',
                'data' => [
                    'summary' => [
                        'total_paid' => $totalPaid,
                        'total_queued' => $totalQueued,
                        'currency' => $user->wallet?->currency ?? 'NGN',
                    ],
                    'payouts' => $paginated,
                ],
            ], 200);
        } catch (Throwable $e) {
            Log::error('Failed to retrieve user payouts', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to retrieve payouts at this time'], 500);
        }
    }
}

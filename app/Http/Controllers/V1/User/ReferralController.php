<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReferralController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        try {
            $perPage = (int) ($validated['per_page'] ?? 20);

            $referrals = Referral::query()
                ->where('referral_id', $user->id)
                ->with('user:id,name,username,avatar,followers,following,created_at')
                ->latest('created_at')
                ->paginate($perPage)
                ->through(function (Referral $referral) {
                    $referred = $referral->user;

                    return [
                        'id' => $referred?->id,
                        'name' => $referred?->name,
                        'username' => $referred?->username,
                        'avatar' => $referred?->avatar,
                        'followers' => $referred?->followers,
                        'following' => $referred?->following,
                        'joined_at' => $referred?->created_at,
                        'referred_at' => $referral->created_at,
                    ];
                });

            $total = Referral::where('referral_id', $user->id)->count();
            $thisMonth = Referral::where('referral_id', $user->id)
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count();

            return response()->json([
                'success' => true,
                'message' => 'Referrals',
                'data' => $referrals,
                'summary' => [
                    'total' => $total,
                    'this_month' => $thisMonth,
                    'referral_code' => $user->referral_code,
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to load referrals', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load referrals',
            ], 500);
        }
    }
}

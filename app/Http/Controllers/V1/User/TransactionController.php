<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'max:50'],
            'q' => ['sometimes', 'string', 'max:100'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        try {
            $perPage = (int) ($validated['per_page'] ?? 10);

            $transactions = Transaction::query()
                ->where('user_id', $user->id)
                ->select(['id', 'ref', 'amount', 'currency', 'status', 'type', 'action', 'description', 'created_at'])
                ->when(! empty($validated['q']), function ($q) use ($validated) {
                    $term = '%'.$validated['q'].'%';
                    $q->where(function ($sub) use ($term) {
                        $sub->where('ref', 'like', $term)
                            ->orWhere('description', 'like', $term)
                            ->orWhere('currency', 'like', $term);
                    });
                })
                ->when(! empty($validated['status']), fn ($q) => $q->where('status', $validated['status']))
                ->when(! empty($validated['from']), fn ($q) => $q->whereDate('created_at', '>=', $validated['from']))
                ->when(! empty($validated['to']), fn ($q) => $q->whereDate('created_at', '<=', $validated['to']))
                ->latest('created_at')
                ->paginate($perPage);

            $stats = Transaction::query()
                ->where('user_id', $user->id)
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            return response()->json([
                'success' => true,
                'message' => 'Transactions',
                'data' => $transactions,
                'stats' => $stats,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to load transactions', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load transactions',
            ], 500);
        }
    }
}

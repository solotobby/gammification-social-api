<?php

namespace App\Http\Controllers\V1\Earnings;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use App\Services\PlatformEarningOverviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyticsController extends Controller
{
    public function __construct(
        protected AnalyticsService $analyticsService,
        protected PlatformEarningOverviewService $overviewService,
    ) {}

    /**
     * GET /v1/earnings/overview — hourly cached reach + monthly earnings snapshot.
     */
    public function overview(Request $request)
    {
        $authUser = $request->user();

        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'User Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
        ]);

        try {
            $data = $this->overviewService->overview(
                $authUser->id,
                $validated['hours'] ?? PlatformEarningOverviewService::DEFAULT_HOURS,
            );

            return response()->json([
                'success' => true,
                'message' => 'Platform earning overview',
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to load earning overview', [
                'user_id' => $authUser->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load earning overview at this time',
            ], 500);
        }
    }

    public function monthly(Request $request)
    {
        $authUser = $request->user();

        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'User Unauthenticated'], 401);
        }

        $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:' . (now()->year + 1)],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        try {
            $data = $this->analyticsService->forMonth(
                $authUser->id,
                $request->integer('year'),
                $request->integer('month')
            );

            return response()->json(['success' => true, 'data' => $data]);
        } catch (Throwable $e) {
            Log::error('Failed to load monthly analytics', [
                'user_id' => $authUser->id,
                'year' => $request->integer('year'),
                'month' => $request->integer('month'),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to load analytics at this time'], 500);
        }
    }

    public function yearly(Request $request)
    {
        $authUser = $request->user();

        if (! $authUser) {
            return response()->json(['success' => false, 'message' => 'User Unauthenticated'], 401);
        }

        $request->validate([
            'year' => ['sometimes', 'integer', 'min:2020', 'max:' . (now()->year + 1)],
        ]);

        try {
            $data = $this->analyticsService->yearlySummary(
                $authUser->id,
                $request->integer('year') ?: null
            );

            return response()->json(['success' => true, 'data' => $data]);
        } catch (Throwable $e) {
            Log::error('Failed to load yearly analytics', [
                'user_id' => $authUser->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to load analytics at this time'], 500);
        }
    }
}

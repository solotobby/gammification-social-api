<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    /**
     * All months within a given year (existing behavior).
     */
    public function monthly(string $userId, ?int $year = null): Collection
    {
        $year ??= now()->year;

        $rows = Post::query()
            ->where('user_id', $userId)
            ->whereYear('created_at', $year)
            ->select([
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                DB::raw('COALESCE(SUM(views), 0) as monetized_views'),
                DB::raw('COALESCE(SUM(likes), 0) as monetized_likes'),
                DB::raw('COALESCE(SUM(comments), 0) as monetized_comments'),
                DB::raw('COALESCE(SUM(views_external), 0) as unmonetized_views'),
                DB::raw('COALESCE(SUM(likes_external), 0) as unmonetized_likes'),
                DB::raw('COALESCE(SUM(comment_external), 0) as unmonetized_comments'),
                DB::raw('COUNT(id) as total_posts'),
            ])
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return $rows->map(fn ($row) => $this->shapeRow($row->month, $row));
    }

    /**
     * A single specific month (e.g. year=2026, month=7 -> July 2026).
     */
    public function forMonth(string $userId, int $year, int $month): array
    {
        $row = Post::query()
            ->where('user_id', $userId)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->select([
                DB::raw('COALESCE(SUM(views), 0) as monetized_views'),
                DB::raw('COALESCE(SUM(likes), 0) as monetized_likes'),
                DB::raw('COALESCE(SUM(comments), 0) as monetized_comments'),
                DB::raw('COALESCE(SUM(views_external), 0) as unmonetized_views'),
                DB::raw('COALESCE(SUM(likes_external), 0) as unmonetized_likes'),
                DB::raw('COALESCE(SUM(comment_external), 0) as unmonetized_comments'),
                DB::raw('COUNT(id) as total_posts'),
            ])
            ->first();

        $label = sprintf('%04d-%02d', $year, $month);

        return $this->shapeRow($label, $row);
    }

    /**
     * Full-year rollup with per-month breakdown.
     */
    public function yearlySummary(string $userId, ?int $year = null): array
    {
        $months = $this->monthly($userId, $year);

        return [
            'year' => $year ?? now()->year,
            'total_posts' => $months->sum('total_posts'),
            'total_monetized_engagement' => $months->sum(fn ($m) => $m['monetized']['total_engagement']),
            'total_unmonetized_engagement' => $months->sum(fn ($m) => $m['unmonetized']['total_engagement']),
            'total_estimated_earning' => round($months->sum('estimated_earning'), 2),
            'months' => $months,
        ];
    }

    /**
     * Shared row-shaping logic for both single-month and multi-month results.
     */
    protected function shapeRow(string $label, $row): array
    {
        $rates = config('earnings.rates');

        $estimatedEarning =
            ($row->monetized_views * $this->rates()['per_view']) +
            ($row->monetized_likes * $this->rates()['per_like']) +
            ($row->monetized_comments * $this->rates()['per_comment']);

        return [
            'month' => $label,
            'total_posts' => (int) $row->total_posts,
            'monetized' => [
                'views' => (int) $row->monetized_views,
                'likes' => (int) $row->monetized_likes,
                'comments' => (int) $row->monetized_comments,
                'total_engagement' => (int) ($row->monetized_views + $row->monetized_likes + $row->monetized_comments),
            ],
            'unmonetized' => [
                'views' => (int) $row->unmonetized_views,
                'likes' => (int) $row->unmonetized_likes,
                'comments' => (int) $row->unmonetized_comments,
                'total_engagement' => (int) ($row->unmonetized_views + $row->unmonetized_likes + $row->unmonetized_comments),
            ],
            'estimated_earning' => round($estimatedEarning, 2),
        ];
    }

    private function rates()
    {
        return [

            'per_view' => 0.0002,     // placeholder — replace with your real monetization rate
            'per_like' => 0.001,
            'per_comment' => 0.005,

        ];
    }

}
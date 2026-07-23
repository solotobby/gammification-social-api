<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;

class TrendingMembers
{
    /**
     * Shared engagement-ranked member collection (used by both entry points).
     */
    protected function rankedMembers(): Collection
    {
        $hours = 6;
        $since = now()->subHours($hours);

        return Post::with(['user:id,name,username'])
            ->where('created_at', '>=', $since)
            ->select(
                'user_id',
                DB::raw('SUM(likes) as total_likes'),
                DB::raw('SUM(comments + comment_external) as total_comments'),
                DB::raw('SUM(views + views_external) as total_views'),
                DB::raw('COUNT(id) as total_posts')
            )
            ->groupBy('user_id')
            ->get()
            ->filter(fn ($row) => $row->user !== null) // guard orphaned posts
            ->map(function ($row) {
                $totalEngagement = (
                    $row->total_likes +
                    $row->total_comments +
                    $row->total_posts +
                    $row->total_views
                ) * 8;

                return [
                    'id' => $row->user->id,
                    'name' => $row->user->name,
                    'username' => $row->user->username,
                    'total_engagement' => $totalEngagement,
                ];
            })
            ->sortByDesc('total_engagement')
            ->values();
    }

    /**
     * Sidebar widget — top N trending members.
     * Usage: (new TrendingMembers)->trending(5);
     */
    public function trending(int $limit = 5): Collection
    {
        return $this->rankedMembers()->take($limit)->values();
    }

    /**
     * "See all" page — paginated trending members.
     * Usage: (new TrendingMembers)->allTrendingMembers(8);
     */
    public function allTrendingMembers(int $perPage = 8, ?int $page = null): LengthAwarePaginator
    {
        $page = $page ?: (int) request()->input('page', 1);
        $members = $this->rankedMembers();

        $items = $members->forPage($page, $perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $members->count(),
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );
    }
}
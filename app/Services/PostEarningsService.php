<?php

namespace App\Services;

use App\Models\UserComment;
use App\Models\UserLike;
use App\Models\UserView;
use Illuminate\Support\Collection;

class PostEarningsService
{
    /**
     * Batch-compute 30-day estimated earnings for many posts in three queries.
     *
     * @return array<string, float> keyed by post id
     */
    public function forPosts(Collection $postIds, ?string $currency = null): array
    {
        $postIds = $postIds->filter()->unique()->values();

        if ($postIds->isEmpty()) {
            return [];
        }

        $currency = strtoupper($currency ?? userBaseCurrency() ?? 'USD');
        $since = now()->subDays(30);

        $views = UserView::query()
            ->whereIn('post_id', $postIds)
            ->where('created_at', '>=', $since)
            ->selectRaw('post_id, COALESCE(SUM(amount), 0) as total')
            ->groupBy('post_id')
            ->pluck('total', 'post_id');

        $likes = UserLike::query()
            ->whereIn('post_id', $postIds)
            ->where('created_at', '>=', $since)
            ->selectRaw('post_id, COALESCE(SUM(amount), 0) as total')
            ->groupBy('post_id')
            ->pluck('total', 'post_id');

        $comments = UserComment::query()
            ->whereIn('post_id', $postIds)
            ->where('created_at', '>=', $since)
            ->selectRaw('post_id, COALESCE(SUM(amount), 0) as total')
            ->groupBy('post_id')
            ->pluck('total', 'post_id');

        $result = [];

        foreach ($postIds as $postId) {
            $raw = (float) ($views[$postId] ?? 0)
                + (float) ($likes[$postId] ?? 0)
                + (float) ($comments[$postId] ?? 0);

            $result[$postId] = (float) round(convertToBaseCurrency($raw, $currency), 5);
        }

        return $result;
    }
}

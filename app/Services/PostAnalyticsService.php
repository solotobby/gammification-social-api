<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Auth\Access\AuthorizationException;

class PostAnalyticsService
{
    public function forPost(Post $post, string $ownerId): array
    {
        if ($post->user_id !== $ownerId) {
            throw new AuthorizationException('You can only view analytics for your own posts.');
        }

        $monetizedViews = (int) ($post->views ?? 0);
        $unmonetizedViews = (int) ($post->views_external ?? 0);
        $totalViews = $monetizedViews + $unmonetizedViews;

        $monetizedLikes = (int) ($post->likes ?? 0);
        $unmonetizedLikes = (int) ($post->likes_external ?? 0);
        $totalLikes = $monetizedLikes + $unmonetizedLikes;

        $monetizedComments = (int) ($post->comments ?? 0);
        $unmonetizedComments = (int) ($post->comment_external ?? 0);
        $totalComments = $monetizedComments + $unmonetizedComments;

        $viewsRevenue = viewsAmountCalculator($post->id, $ownerId);
        $likesRevenue = likesAmountCalculator($post->id, $ownerId);
        $commentsRevenue = commentsAmountCalculator($post->id, $ownerId);
        $totalEarnings = $viewsRevenue + $likesRevenue + $commentsRevenue;

        $monetizedEngagement = $monetizedViews + $monetizedLikes + $monetizedComments;
        $currencySymbol = currencySymbol(null, $ownerId);

        return [
            'post' => [
                'id' => $post->id,
                'content' => $post->content,
                'created_at' => $post->created_at,
                'posted_ago' => $post->created_at?->diffForHumans(short: true),
                'account_level' => userLevel($ownerId),
            ],
            'summary' => [
                'monetized_engagements' => $monetizedEngagement,
                'estimated_total_earnings' => round($totalEarnings, 2),
                'currencySymbol' => $currencySymbol,
                'earnings_breakdown' => [
                    'views' => round($viewsRevenue, 2),
                    'likes' => round($likesRevenue, 2),
                    'comments' => round($commentsRevenue, 2),
                ],
            ],
            'stats' => [
                'total_views' => $totalViews,
                'monetized_likes' => $monetizedLikes,
                'total_comments' => $totalComments,
                'monetized_engagement' => $monetizedEngagement,
            ],
            'views' => [
                'monetized' => $monetizedViews,
                'unmonetized' => $unmonetizedViews,
                'total' => $totalViews,
                'revenue' => round($viewsRevenue, 2),
            ],
            'likes' => [
                'monetized' => $monetizedLikes,
                'unmonetized' => $unmonetizedLikes,
                'total' => $totalLikes,
                'revenue' => round($likesRevenue, 2),
            ],
            'comments' => [
                'monetized' => $monetizedComments,
                'unmonetized' => $unmonetizedComments,
                'total' => $totalComments,
                'revenue' => round($commentsRevenue, 2),
            ],
            'revenue_breakdown' => [
                [
                    'type' => 'views',
                    'label' => 'Views',
                    'monetized_count' => $monetizedViews,
                    'revenue' => round($viewsRevenue, 2),
                ],
                [
                    'type' => 'likes',
                    'label' => 'Likes',
                    'monetized_count' => $monetizedLikes,
                    'revenue' => round($likesRevenue, 2),
                ],
                [
                    'type' => 'comments',
                    'label' => 'Comments',
                    'monetized_count' => $monetizedComments,
                    'revenue' => round($commentsRevenue, 2),
                ],
            ],
            // 'disclaimer' => 'Figures are estimates based on current monetized engagement. Final payout may differ after validation.',
        ];
    }
}

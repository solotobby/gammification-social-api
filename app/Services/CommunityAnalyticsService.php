<?php

namespace App\Services;

use App\Models\Community;
use App\Models\CommunityInvite;
use App\Models\CommunityJoinRequest;
use App\Models\CommunityPost;
use App\Models\CommunitySubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CommunityAnalyticsService
{
    public function __construct(
        protected CommunityService $communityService,
    ) {}

    /**
     * Get comprehensive community analytics for community managers (owner/admin).
     */
    public function getAnalytics(User $viewer, Community $community): array
    {
        $this->communityService->assertCanManage($community, $viewer);

        $id = $community->id;

        $stats = [
            'members_total' => (int) DB::table('community_users')
                ->where('community_id', $id)
                ->where('status', 'active')
                ->count(),
            'members_7d' => (int) DB::table('community_users')
                ->where('community_id', $id)
                ->where('status', 'active')
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
            'members_30d' => (int) DB::table('community_users')
                ->where('community_id', $id)
                ->where('status', 'active')
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
            'posts_total' => (int) CommunityPost::query()
                ->where('community_id', $id)
                ->count(),
            'posts_7d' => (int) CommunityPost::query()
                ->where('community_id', $id)
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
            'posts_30d' => (int) CommunityPost::query()
                ->where('community_id', $id)
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
            'likes_total' => (int) CommunityPost::query()
                ->where('community_id', $id)
                ->sum('likes_count'),
            'comments_total' => (int) CommunityPost::query()
                ->where('community_id', $id)
                ->sum('comments_count'),
            'views_total' => (int) CommunityPost::query()
                ->where('community_id', $id)
                ->sum('views_count'),
            'pending_requests' => (int) CommunityJoinRequest::query()
                ->where('community_id', $id)
                ->where('status', 'pending')
                ->count(),
            'active_subscribers' => $community->type === 'paid'
                ? (int) CommunitySubscription::query()
                    ->where('community_id', $id)
                    ->where('status', 'active')
                    ->where(function ($q) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->count()
                : 0,
            'invite_link_uses' => (int) CommunityInvite::query()
                ->where('community_id', $id)
                ->where('type', 'link')
                ->sum('uses_count'),
        ];

        $topPosts = CommunityPost::query()
            ->where('community_id', $id)
            ->with(['user:id,name,username,avatar', 'media'])
            ->orderByDesc('likes_count')
            ->orderByDesc('comments_count')
            ->limit(5)
            ->get();

        $recentMembers = $community->members()
            ->select('users.id', 'users.name', 'users.username', 'users.avatar')
            ->withPivot('role', 'status', 'created_at')
            ->orderByDesc('community_users.created_at')
            ->limit(5)
            ->get();

        return [
            'community_id' => $id,
            'community_name' => $community->name,
            'stats' => $stats,
            'top_posts' => $topPosts,
            'recent_members' => $recentMembers,
        ];
    }
}

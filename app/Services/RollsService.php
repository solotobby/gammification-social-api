<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Follow;
use App\Models\Post;
use App\Models\PostVideo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RollsService
{
    public const PER_PAGE = 5;

    public const TOP_LIMIT = 5;

    public const COMMENTS_PER_PAGE = 5;

    /**
     * Top 5 completed rolls ranked by a combined engagement score:
     * views + likes + comments + (play_count × 2) + avg_watch_time.
     *
     * @return list<array>
     */
    public function getTopRolls(?string $viewerId, int $limit = self::TOP_LIMIT): array
    {
        $scoreExpression = '(
            COALESCE(posts.views, 0)
            + COALESCE(posts.likes, 0)
            + COALESCE(posts.comments, 0)
            + (COALESCE(post_videos.play_count, 0) * 2)
            + COALESCE(post_videos.avg_watch_time, 0)
        )';

        $posts = $this->baseQuery($viewerId)
            ->join('post_videos', 'post_videos.post_id', '=', 'posts.id')
            ->where('post_videos.processing_status', 'completed')
            ->whereNotNull('post_videos.path')
            ->addSelect(DB::raw("{$scoreExpression} AS engagement_score"))
            ->orderByDesc('engagement_score')
            ->orderByDesc('posts.created_at')
            ->limit($limit)
            ->get();

        return $posts
            ->values()
            ->map(fn (Post $post, int $index) => $this->transformTopRoll($post, $index + 1))
            ->all();
    }

    protected function transformTopRoll(Post $post, int $rank): array
    {
        $video = $post->video;
        $versions = $video?->quality_versions ?? [];

        return [
            'rank' => $rank,
            'post_id' => $post->id,
            'video_id' => $video?->id,
            'user' => $post->user?->only(['id', 'username', 'name', 'avatar']),
            'media' => $video ? [
                'type' => 'video',
                'url' => $video->path,
                'sd_url' => $versions['medium'] ?? $versions['low'] ?? $video->path,
                'hd_url' => $versions['high'] ?? $video->hd_path,
                'low_url' => $versions['low'] ?? null,
                'quality_versions' => $versions,
                'thumbnail_url' => $video->thumbnail_path,
                'duration' => $video->duration,
                'width' => $video->width,
                'height' => $video->height,
                'format' => $video->format,
            ] : null,
        ];
    }

    /**
     * Record a play for a roll video.
     */
    public function recordPlay(string $videoId): PostVideo
    {
        $video = PostVideo::query()
            ->where('id', $videoId)
            ->where('processing_status', 'completed')
            ->whereHas('post', fn ($q) => $q->where('status', 'LIVE'))
            ->firstOrFail();

        $video->incrementPlays();

        return $video->fresh();
    }

    /**
     * Record watch time (seconds) for a roll video.
     */
    public function recordWatch(string $videoId, float $watchSeconds, bool $countAsPlay = false): PostVideo
    {
        $video = PostVideo::query()
            ->where('id', $videoId)
            ->where('processing_status', 'completed')
            ->whereHas('post', fn ($q) => $q->where('status', 'LIVE'))
            ->firstOrFail();

        if ($countAsPlay) {
            $video->incrementPlays();
            $video->refresh();
        }

        if ($watchSeconds >= 0.25) {
            $video->updateWatchTime($watchSeconds);
        }

        return $video->fresh();
    }

    /**
     * Random completed video posts for the rolls feed.
     *
     * @param  list<string>  $excludeVideoIds
     */
    public function getRolls(?string $viewerId, int $perPage = self::PER_PAGE, array $excludeVideoIds = []): LengthAwarePaginator
    {
        $query = $this->baseQuery($viewerId)
            ->inRandomOrder();

        if ($excludeVideoIds !== []) {
            $query->whereDoesntHave('video', fn ($q) => $q->whereIn('id', $excludeVideoIds));
        }

        $posts = $query->paginate($perPage);

        $followingIds = $this->followingIds($viewerId, $posts->getCollection());

        $posts->getCollection()->transform(
            fn (Post $post) => $this->transformRoll($post, $viewerId, $followingIds)
        );

        return $posts;
    }

    /**
     * Exact video by PostVideo id, plus a random batch of more rolls and paginated comments.
     *
     * @return array{current: array, more: LengthAwarePaginator, comments: LengthAwarePaginator}
     */
    public function getRoll(string $videoId, ?string $viewerId, int $morePerPage = self::PER_PAGE): array
    {
        $video = PostVideo::query()
            ->where('id', $videoId)
            ->where('processing_status', 'completed')
            ->firstOrFail();

        $post = $this->baseQuery($viewerId)
            ->where('posts.id', $video->post_id)
            ->firstOrFail();

        $followingIds = $this->followingIds($viewerId, collect([$post]));
        $current = $this->transformRoll($post, $viewerId, $followingIds);

        $more = $this->getRolls($viewerId, $morePerPage, [$videoId]);
        $comments = $this->getComments($post->id, self::COMMENTS_PER_PAGE);

        return [
            'current' => $current,
            'more' => $more,
            'comments' => $comments,
        ];
    }

    public function getComments(string $postId, int $perPage = self::COMMENTS_PER_PAGE): LengthAwarePaginator
    {
        $comments = Comment::query()
            ->where('post_id', $postId)
            ->with('user:id,username,name,avatar')
            ->latest('created_at')
            ->paginate($perPage);

        $comments->getCollection()->transform(fn (Comment $c) => [
            'id' => $c->id,
            'message' => $c->message,
            'created_at' => $c->created_at,
            'user' => $c->user?->only(['id', 'username', 'name', 'avatar']),
        ]);

        return $comments;
    }

    protected function baseQuery(?string $viewerId): Builder
    {
        return Post::query()
            ->select([
                'posts.id',
                'posts.user_id',
                'posts.content',
                'posts.views',
                'posts.likes',
                'posts.comments',
                'posts.has_video',
                'posts.media_status',
                'posts.created_at',
            ])
            ->where('posts.status', 'LIVE')
            ->where('posts.has_video', true)
            ->where('posts.media_status', 'completed')
            ->whereHas('video', fn ($q) => $q->where('processing_status', 'completed'))
            ->with(['user:id,username,name,avatar'])
            ->with(['video' => fn ($q) => $q->where('processing_status', 'completed')
                ->select([
                    'id', 'post_id', 'path', 'hd_path', 'thumbnail_path',
                    'duration', 'width', 'height', 'format', 'quality_versions',
                    'view_count', 'play_count', 'avg_watch_time',
                ])])
            ->when($viewerId, fn ($q) => $q->withExists([
                'likes as is_liked_by_viewer' => fn ($sub) => $sub->where('user_id', $viewerId),
            ]));
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return array<string, true>
     */
    protected function followingIds(?string $viewerId, Collection $posts): array
    {
        if (! $viewerId || $posts->isEmpty()) {
            return [];
        }

        $authorIds = $posts->pluck('user_id')->unique()->filter()->values()->all();

        if ($authorIds === []) {
            return [];
        }

        return Follow::query()
            ->where('follower_id', $viewerId)
            ->whereIn('following_id', $authorIds)
            ->pluck('following_id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /**
     * @param  array<string, true>  $followingIds
     */
    protected function transformRoll(Post $post, ?string $viewerId, array $followingIds, bool $withMetrics = false): array
    {
        $video = $post->video;
        $versions = $video?->quality_versions ?? [];

        $roll = [
            'video_id' => $video?->id,
            'post_id' => $post->id,
            'content' => $post->content,
            'likes' => (int) $post->likes,
            'comments' => (int) $post->comments,
            'views' => (int) $post->views,
            'video_views' => (int) ($video?->view_count ?? 0),
            'play_count' => (int) ($video?->play_count ?? 0),
            'avg_watch_time' => $video?->avg_watch_time !== null
                ? round((float) $video->avg_watch_time, 2)
                : null,
            'is_liked_by_viewer' => (bool) ($post->is_liked_by_viewer ?? false),
            'is_following' => $viewerId !== null
                && $post->user_id !== $viewerId
                && isset($followingIds[$post->user_id]),
            'user' => $post->user?->only(['id', 'username', 'name', 'avatar']),
            'media' => $video ? [
                'type' => 'video',
                'url' => $video->path,
                'sd_url' => $versions['medium'] ?? $versions['low'] ?? $video->path,
                'hd_url' => $versions['high'] ?? $video->hd_path,
                'low_url' => $versions['low'] ?? null,
                'quality_versions' => $versions,
                'thumbnail_url' => $video->thumbnail_path,
                'duration' => $video->duration,
                'width' => $video->width,
                'height' => $video->height,
                'format' => $video->format,
            ] : null,
            'created_at' => $post->created_at,
        ];

        if ($withMetrics) {
            $roll['metrics'] = [
                'reactions' => [
                    'views' => (int) $post->views,
                    'likes' => (int) $post->likes,
                    'comments' => (int) $post->comments,
                    'total' => (int) $post->views + (int) $post->likes + (int) $post->comments,
                ],
                'play_count' => (int) ($video?->play_count ?? 0),
                'avg_watch_time' => $video?->avg_watch_time !== null
                    ? round((float) $video->avg_watch_time, 2)
                    : null,
            ];
        }

        return $roll;
    }
}

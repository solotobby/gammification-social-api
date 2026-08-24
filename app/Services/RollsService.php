<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Follow;
use App\Models\Post;
use App\Models\PostVideo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RollsService
{
    public const PER_PAGE = 5;

    public const COMMENTS_PER_PAGE = 5;

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
                    'view_count', 'play_count',
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
    protected function transformRoll(Post $post, ?string $viewerId, array $followingIds): array
    {
        $video = $post->video;
        $versions = $video?->quality_versions ?? [];

        return [
            'video_id' => $video?->id,
            'post_id' => $post->id,
            'content' => $post->content,
            'likes' => (int) $post->likes,
            'comments' => (int) $post->comments,
            'views' => (int) $post->views,
            'video_views' => (int) ($video?->view_count ?? 0),
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
    }
}

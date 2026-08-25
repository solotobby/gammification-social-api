<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Hashtag;
use App\Models\Post;
use App\Models\PostBookmark;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FeedService
{
    public function __construct(protected PostEarningsService $earningsService) {}

    private const COMMENTS_PREVIEW_LIMIT = 3;
    private const LIKERS_PREVIEW_LIMIT = 3;

    private const USER_LIKES_TABLE = 'user_likes';
    private const COMMENTS_TABLE = 'comments';

    private const POST_SUMMARY_COLUMNS = [
        'id', 'user_id', 'content', 'views', 'likes',
        'comments', 'has_video', 'has_images', 'media_status', 'created_at',
    ];

    public function getFeed(?string $viewerId, int $perPage = 10): LengthAwarePaginator
    {
        $posts = $this->baseQuery($viewerId, withCommentsPreview: true)
            ->where('status', 'LIVE')
            ->latest('created_at')
            ->paginate($perPage);

        return $this->transformPage($posts, $viewerId);
    }

    public function getPost(string $postId, ?string $viewerId): Post
    {
        $post = $this->baseQuery($viewerId, withCommentsPreview: false)
            ->where('status', 'LIVE')
            ->findOrFail($postId);

        return $this->applyEarnings(
            collect([$this->transformPost($post, includeCommentsPreview: false)]),
            $viewerId,
        )->first();
    }

    public function getPostComments(string $postId, int $perPage = 10): LengthAwarePaginator
    {
        $comments = Comment::query()
            ->where('post_id', $postId)
            ->with('user:id,username,name,avatar')
            ->latest('created_at')
            ->paginate($perPage);

        $comments->getCollection()->transform(fn (Comment $c) => [
            'id' => $c->id,
            'user' => $c->user?->only(['id', 'username', 'name', 'avatar']),
            'message' => $c->message,
            'created_at' => $c->created_at,
        ]);

        return $comments;
    }

    public function getUserPosts(string $profileUserId, ?string $viewerId, int $perPage = 10): LengthAwarePaginator
    {
        $isOwner = $viewerId !== null && $viewerId === $profileUserId;

        $query = $this->baseQuery($viewerId, withCommentsPreview: true)
            ->where('user_id', $profileUserId);

        if (! $isOwner) {
            $query->where('status', 'LIVE');
        }

        $posts = $query->latest('created_at')->paginate($perPage);

        return $this->transformPage($posts, $viewerId);
    }

    /**
     * Posts tagged with a given hashtag name.
     * Reuses the same eager-load graph/transform as every other feed entry point.
     */
    public function getHashtagPosts(string $tag, ?string $viewerId, int $perPage = 10): LengthAwarePaginator
    {
        $hashtag = Hashtag::select('id')
            ->where('name', $tag)
            ->firstOrFail();

        $posts = $this->baseQuery($viewerId, withCommentsPreview: true)
            ->whereHas('hashtags', fn ($q) => $q->where('hashtags.id', $hashtag->id))
            ->where('status', 'LIVE')
            ->latest('created_at')
            ->paginate($perPage);

        return $this->transformPage($posts, $viewerId);
    }

    public function getBookmarkedPosts(string $userId, int $perPage = 10): LengthAwarePaginator
    {
        $bookmarks = PostBookmark::query()
            ->where('user_id', $userId)
            ->latest('created_at')
            ->paginate($perPage);

        $postIds = $bookmarks->pluck('post_id')->filter()->values();

        if ($postIds->isEmpty()) {
            $bookmarks->setCollection(collect());

            return $bookmarks;
        }

        $postsById = $this->baseQuery($userId, withCommentsPreview: true)
            ->whereIn('id', $postIds)
            ->where('status', 'LIVE')
            ->get()
            ->keyBy('id');

        $bookmarkTimes = $bookmarks->getCollection()->keyBy('post_id');

        $ordered = $postIds
            ->map(function (string $postId) use ($postsById, $bookmarkTimes) {
                $post = $postsById->get($postId);

                if (! $post) {
                    return null;
                }

                $transformed = $this->transformPost($post, includeCommentsPreview: true);
                $transformed->is_bookmarked = true;
                $transformed->bookmarked_at = $bookmarkTimes->get($postId)?->created_at;

                return $transformed;
            })
            ->filter()
            ->values();

        $bookmarks->setCollection(
            $this->applyEarnings($ordered, $userId)
        );

        return $bookmarks;
    }

    protected function transformPage(LengthAwarePaginator $posts, ?string $viewerId): LengthAwarePaginator
    {
        $posts->setCollection(
            $this->applyEarnings(
                $posts->getCollection()->map(fn (Post $post) => $this->transformPost($post, includeCommentsPreview: true)),
                $viewerId,
            )
        );

        return $posts;
    }

    protected function applyEarnings(Collection $posts, ?string $viewerId): Collection
    {
        if ($posts->isEmpty()) {
            return $posts;
        }

        $currencyCode = strtoupper($viewerId ? (userBaseCurrency($viewerId) ?? 'USD') : 'USD');
        $earnings = $this->earningsService->forPosts($posts->pluck('id'), $currencyCode);

        return $posts->map(function (Post $post) use ($earnings, $currencyCode, $viewerId) {
            $post->estimatedEarnings = $earnings[$post->id] ?? 0.0;
            $post->currencySymbol = currencySymbol($currencyCode, $viewerId);

            return $post;
        });
    }

    protected function baseQuery(?string $viewerId, bool $withCommentsPreview): Builder
    {
        $query = Post::query()
            ->select(self::POST_SUMMARY_COLUMNS)
            ->with(['user:id,username,name,avatar'])
            ->with(['video' => fn ($q) => $q->where('processing_status', 'completed')
                ->select(['id', 'post_id', 'path', 'hd_path', 'thumbnail_path', 'duration', 'width', 'height', 'format', 'quality_versions'])])
            ->with(['images' => fn ($q) => $q->where('processing_status', 'completed')
                ->select(['id', 'post_id', 'path', 'thumbnail_path', 'medium_path', 'full_path', 'width', 'height'])])
            ->with(['likes' => fn ($q) => $this->latestPerPost($q, self::USER_LIKES_TABLE, self::LIKERS_PREVIEW_LIMIT)])
            ->when($viewerId, fn ($q) => $q->withExists([
                'likes as is_liked_by_viewer' => fn ($sub) => $sub->where('user_id', $viewerId),
                'bookmarks as is_bookmarked' => fn ($sub) => $sub->where('user_id', $viewerId),
            ]));

        if ($withCommentsPreview) {
            $query->with(['postComments' => fn ($q) => $this->latestPerPost($q, self::COMMENTS_TABLE, self::COMMENTS_PREVIEW_LIMIT)]);
        }

        return $query;
    }

    protected function latestPerPost(Relation $q, string $table, int $limit): Relation
    {
        return $q->with('user:id,username,name,avatar')
            ->whereIn('id', function ($sub) use ($table, $limit) {
                $sub->select('id')
                    ->from(DB::raw("(
                        SELECT id, post_id,
                               ROW_NUMBER() OVER (PARTITION BY post_id ORDER BY created_at DESC) AS rn
                        FROM {$table}
                    ) AS ranked"))
                    ->where('rn', '<=', $limit);
            })
            ->latest();
    }

    protected function transformPost(Post $post, bool $includeCommentsPreview): Post
    {
        $post->media = $this->buildMedia($post);

        if ($includeCommentsPreview) {
            $post->comments_preview = $post->postComments->map(fn ($c) => [
                'id' => $c->id,
                'user' => $c->user?->only(['id', 'username', 'name', 'avatar']),
                'message' => $c->message,
                'created_at' => $c->created_at,
            ])->values();
        }

        $post->is_liked_by_viewer = (bool) ($post->is_liked_by_viewer ?? false);
        $post->is_bookmarked = (bool) ($post->is_bookmarked ?? false);

        // Raw 'likes' count column stays intact — build the preview from the
        // relation, then only strip the RELATION (not the attribute) below.
        $post->likers_preview = $post->getRelation('likes')->map(fn ($l) => [
            'id' => $l->user->id,
            'name' => $l->user->name,
            'username' => $l->user->username,
            'avatar' => $l->user->avatar,
        ])->values();

        // unsetRelation() clears the loaded relation only — the 'likes' count
        // column attribute (e.g. $post->likes as an int) survives untouched.
        // Plain unset($post->likes) would have wiped BOTH, which was the bug.
        $post->unsetRelation('video');
        $post->unsetRelation('images');
        $post->unsetRelation('likes');
        if ($includeCommentsPreview) {
            $post->unsetRelation('postComments');
        }

        return $post;
    }

    protected function buildMedia(Post $post): ?array
    {
        if ($post->media_status !== 'completed') {
            return null;
        }

        if ($post->has_video && $post->video) {
            $versions = $post->video->quality_versions ?? [];

            return [
                'type' => 'video',
                'url' => $post->video->path,
                'sd_url' => $versions['medium'] ?? $versions['low'] ?? $post->video->path,
                'hd_url' => $versions['high'] ?? $post->video->hd_path,
                'low_url' => $versions['low'] ?? null,
                'quality_versions' => $versions,
                'thumbnail_url' => $post->video->thumbnail_path,
                'duration' => $post->video->duration,
                'width' => $post->video->width,
                'height' => $post->video->height,
                'format' => $post->video->format,
            ];
        }

        if ($post->has_images && $post->images->isNotEmpty()) {
            return [
                'type' => 'images',
                'items' => $post->images->map(fn ($img) => [
                    'thumb_url' => $img->thumbnail_path,
                    'medium_url' => $img->medium_path ?: $img->path,
                    'full_url' => $img->full_path,
                    'width' => $img->width,
                    'height' => $img->height,
                ])->values(),
            ];
        }

        return null;
    }
}
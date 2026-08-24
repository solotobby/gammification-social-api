<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Hashtag;
use App\Models\Post;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

class FeedService
{
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

        $posts->getCollection()->transform(fn (Post $post) => $this->transformPost($post, includeCommentsPreview: true));

        return $posts;
    }

    public function getPost(string $postId, ?string $viewerId): Post
    {
        $post = $this->baseQuery($viewerId, withCommentsPreview: false)
            ->where('status', 'LIVE')
            ->findOrFail($postId);

        return $this->transformPost($post, includeCommentsPreview: false);
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

        $posts->getCollection()->transform(fn (Post $post) => $this->transformPost($post, includeCommentsPreview: true));

        return $posts;
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

        $posts->getCollection()->transform(fn (Post $post) => $this->transformPost($post, includeCommentsPreview: true));

        return $posts;
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
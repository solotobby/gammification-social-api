<?php

namespace App\Services;

use App\Models\Comment;
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
        'id',
        'user_id',
        'content',
        'views',
        'likes',
        'comments',
        'has_video',
        'has_images',
        'media_status',
        'created_at',
    ];

    public function getFeed(?string $viewerId, int $perPage = 10): LengthAwarePaginator
    {
        $posts = $this->baseQuery($viewerId, withCommentsPreview: true)
            ->where('status', 'LIVE')
            ->latest('created_at')
            ->paginate($perPage);

        $posts->getCollection()->transform(fn(Post $post) => $this->transformPost($post, includeCommentsPreview: true));

        return $posts;
    }

    /**
     * Single post — full detail. Does NOT include a comments preview;
     * call getPostComments() separately for the paginated thread.
     */
    public function getPost(string $postId, ?string $viewerId): Post
    {
        $post = $this->baseQuery($viewerId, withCommentsPreview: false)
            ->where('status', 'LIVE')
            ->findOrFail($postId);

        return $this->transformPost($post, includeCommentsPreview: false);
    }

    /**
     * Full paginated comment thread for a single post.
     * Kept separate from getPost() so page size/sort can evolve independently
     * of the feed's lightweight preview.
     */
    public function getPostComments(string $postId, int $perPage = 10): LengthAwarePaginator
    {
        $comments = Comment::query()
            ->where('post_id', $postId)
            ->with('user:id,username,name,avatar')
            ->latest('created_at')
            ->paginate($perPage);

        $comments->getCollection()->transform(fn(Comment $c) => [
            'id' => $c->id,
            'user' => $c->user?->only(['id', 'username', 'name', 'avatar']),
            'message' => $c->message,
            'created_at' => $c->created_at,
        ]);

        return $comments;
    }

    protected function baseQuery(?string $viewerId, bool $withCommentsPreview): Builder
    {
        $query = Post::query()
            ->select(self::POST_SUMMARY_COLUMNS)
            ->with(['user:id,username,name,avatar'])
            ->with(['video' => fn($q) => $q->where('processing_status', 'completed')
                ->select(['id', 'post_id', 'path', 'hd_path', 'thumbnail_path', 'duration', 'width', 'height'])])
            ->with(['images' => fn($q) => $q->where('processing_status', 'completed')
                ->select(['id', 'post_id', 'path', 'thumbnail_path', 'full_path', 'width', 'height'])])
            ->with(['likes' => fn($q) => $this->latestPerPost($q, self::USER_LIKES_TABLE, self::LIKERS_PREVIEW_LIMIT)])
            ->when($viewerId, fn($q) => $q->withExists([
                'likes as is_liked_by_viewer' => fn($sub) => $sub->where('user_id', $viewerId),
            ]));

        if ($withCommentsPreview) {
            $query->with(['postComments' => fn($q) => $this->latestPerPost($q, self::COMMENTS_TABLE, self::COMMENTS_PREVIEW_LIMIT)]);
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
            $post->comments_preview = $post->postComments->map(fn($c) => [
                'id' => $c->id,
                'user' => $c->user?->only(['id', 'username', 'name', 'avatar']),
                'message' => $c->message,
                'created_at' => $c->created_at,
            ])->values();
        }

        $post->is_liked_by_viewer = (bool) ($post->is_liked_by_viewer ?? false);

        $post->likers_preview = $post->getRelation('likes')->map(fn($l) => [
            'id' => $l->user->id,
            'name' => $l->user->name,
            'username' => $l->user->username,
            'avatar' => $l->user->avatar,
        ])->values();

        unset($post->video, $post->images, $post->likes);
        if ($includeCommentsPreview) {
            unset($post->postComments);
        }

        return $post;
    }

    protected function buildMedia(Post $post): ?array
    {
        if ($post->media_status !== 'completed') {
            return null;
        }

        if ($post->has_video && $post->video) {
            return [
                'type' => 'video',
                'sd_url' => $post->video->path,
                'hd_url' => $post->video->hd_path,
                'poster_url' => $post->video->thumbnail_path,
                'duration' => $post->video->duration,
                'width' => $post->video->width,
                'height' => $post->video->height,
            ];
        }

        if ($post->has_images && $post->images->isNotEmpty()) {
            return [
                'type' => 'images',
                'items' => $post->images->map(fn($img) => [
                    'thumb_url' => $img->thumbnail_path,
                    'medium_url' => $img->path,
                    'full_url' => $img->full_path,
                    'width' => $img->width,
                    'height' => $img->height,
                ])->values(),
            ];
        }

        return null;
    }


    /**
     * Posts belonging to a specific user (profile view / "my posts").
     * When $viewerId === $profileUserId (viewing your own posts), all statuses
     * are visible. Otherwise, only LIVE posts are shown — mirrors scopeVisibleToViewer
     * already defined on the Post model for shadow-ban handling.
     */
    public function getUserPosts(string $profileUserId, ?string $viewerId, int $perPage = 10): LengthAwarePaginator
    {
        $isOwner = $viewerId !== null && $viewerId === $profileUserId;

        $query = $this->baseQuery($viewerId, withCommentsPreview: true)
            ->where('user_id', $profileUserId);

        if (! $isOwner) {
            $query->where('status', 'LIVE');
        }

        $posts = $query->latest('created_at')->paginate($perPage);

        $posts->getCollection()->transform(fn(Post $post) => $this->transformPost($post, includeCommentsPreview: true));

        return $posts;
    }
}

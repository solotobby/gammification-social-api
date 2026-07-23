<?php

namespace App\Services;

use App\Models\Hashtag;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class HashTagPost
{

    protected function latestCommentsPerPost($q)
    {
        $q->with('user:id,username,name')
            ->whereIn('id', function ($sub) {
                $sub->select('id')
                    ->from(DB::raw('(
                        SELECT id, post_id,
                               ROW_NUMBER() OVER (PARTITION BY post_id ORDER BY created_at DESC) AS rn
                        FROM comments
                    ) AS ranked'))
                    ->where('rn', '<=', 3);
            })
            ->latest();
    }


    public function getHashtagPosts(string $tag, int $perPage = 10): LengthAwarePaginator
    {
        $hashtag = Hashtag::select('id')
            ->where('name', $tag)
            ->firstOrFail();

        $posts = Post::query()
            ->select(['id', 'user_id', 'content', 'views', 'likes', 'comments', 'has_video', 'has_images', 'media_status', 'created_at'])
            ->whereHas('hashtags', function ($q) use ($hashtag) {
                $q->where('hashtags.id', $hashtag->id);
            })
            ->where('status', 'LIVE')
            ->with(['user:id,username,name'])
            ->with(['video' => fn($q) => $q->where('processing_status', 'completed')
                ->select(['id', 'post_id', 'path', 'hd_path', 'thumbnail_path', 'duration', 'width', 'height'])])
            ->with(['images' => fn($q) => $q->where('processing_status', 'completed')
                ->select(['id', 'post_id', 'path', 'thumbnail_path', 'full_path', 'width', 'height'])])
            ->with(['postComments' => fn($q) => $this->latestCommentsPerPost($q)])
            ->latest('created_at')
            ->paginate($perPage);

        $posts->getCollection()->transform(fn(Post $post) => $this->transformPost($post));

        return $posts;
    }


    protected function transformPost(Post $post): Post
    {
        $post->media = null;

        if ($post->media_status === 'completed') {
            if ($post->has_video && $post->video) {
                $post->media = [
                    'type' => 'video',
                    'sd_url' => $post->video->path,
                    'hd_url' => $post->video->hd_path,
                    'poster_url' => $post->video->thumbnail_path,
                    'duration' => $post->video->duration,
                    'width' => $post->video->width,
                    'height' => $post->video->height,
                ];
            } elseif ($post->has_images && $post->images->isNotEmpty()) {
                $post->media = [
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
        }

        $post->comments_preview = $post->postComments->map(fn($c) => [
            'id' => $c->id,
            'user' => $c->user?->only(['id', 'username', 'name']),
            'message' => $c->message,
            'created_at' => $c->created_at,
        ])->values();

        unset($post->video, $post->images, $post->postComments);

        return $post;
    }
}

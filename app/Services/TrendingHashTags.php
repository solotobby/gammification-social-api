<?php

namespace App\Services;

use App\Models\Hashtag;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TrendingHashTags
{
    
    protected function baseQuery(int $hours = 24): Builder
    {
        return Hashtag::select('hashtags.*')
            ->join('hashtag_trends', 'hashtags.id', '=', 'hashtag_trends.hashtag_id')
            ->where('hashtag_trends.created_at', '>', now()->subHours($hours))
            ->selectRaw("
                SUM(
                    hashtag_trends.score *
                    EXP(-(TIMESTAMPDIFF(MINUTE, hashtag_trends.created_at, NOW()) / 60))
                ) AS trend_score
            ")
            ->groupBy('hashtags.id')
            ->orderByDesc('trend_score');
    }

  
    public function getTrending(int $limit): Collection
    {
        return $this->baseQuery()->limit($limit)->get();
    }

 
    public function getAllTrending(int $perPage): LengthAwarePaginator
    {
        return $this->baseQuery()->paginate($perPage);
    }

    public function getHastagPost($tag){

        $hashtag =  Hashtag::where(
            'name',
            $tag
        )
        ->firstOrFail();
            
           $posts = Post::with(['user:id,username,name'])
                ->with(['video' => function ($q) {
                    $q->where('processing_status', 'completed')
                        ->select(['id', 'post_id', 'path', 'hd_path', 'thumbnail_path', 'duration', 'width', 'height']);
                }])
                ->with(['images' => function ($q) {
                    $q->where('processing_status', 'completed')
                        ->select(['id', 'post_id', 'path', 'thumbnail_path', 'full_path', 'width', 'height']);
                }])
                ->with(['postComments' => function ($q) {
                    $q->with('user:id,username,name')
                        ->whereIn('id', function ($sub) {
                            $sub->select('id')
                                ->from(DB::raw('(SELECT id, post_id, ROW_NUMBER() OVER (PARTITION BY post_id ORDER BY created_at DESC) AS rn FROM comments) AS ranked'))
                                ->where('rn', '<=', 3);
                        })
                        ->latest();
                }])
                ->where('status', 'LIVE')
                ->latest('created_at')
                ->select(['id', 'user_id', 'content', 'views', 'likes', 'comments', 'has_video', 'has_images', 'media_status', 'created_at'])
                ->paginate(10);

            $posts->getCollection()->transform(function (Post $post) {
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
            });



        // $posts = $hashtag
        //     ->posts()
        //     ->with([
        //         'user',
        //         'hashtags'
        //     ])
        //     ->latest()
        //     ->paginate(15);

        

    }
}
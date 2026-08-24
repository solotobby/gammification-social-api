<?php

namespace App\Services;

use App\Models\Hashtag;
use App\Models\HashtagTrend;
use App\Models\Post;
use App\Models\PostHashTag;

class HashTagServices
{
    public function extract($text)
    {
        preg_match_all(
            '/#([a-zA-Z0-9_]+)/',
            $text,
            $matches
        );

        return collect($matches[1])->unique();
    }

    public function attach(Post $post, $text): void
    {
        $tags = $this->extract($text);

        foreach ($tags as $tag) {
            $hashtag = Hashtag::firstOrCreate([
                'name' => $tag,
            ]);

            $alreadyAttached = PostHashTag::where('post_id', $post->id)
                ->where('hashtag_id', $hashtag->id)
                ->exists();

            if ($alreadyAttached) {
                continue;
            }

            PostHashTag::create([
                'post_id' => $post->id,
                'hashtag_id' => $hashtag->id,
            ]);

            $hashtag->increment('posts_count');
            $this->recordTrend($hashtag);
        }
    }

    private function recordTrend($hashtag): void
    {
        HashtagTrend::create([
            'hashtag_id' => $hashtag->id,
            'score' => 1,
        ]);
    }
}

<?php 

namespace App\Services;


use App\Models\Hashtag;
use App\Models\HashtagTrend;
use App\Models\Post;
use App\Models\PostHashTag;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class HashTagServices{

    public function extract($text)
    {

        // preg_match_all('/#(\w+)/u', $text, $matches);

        preg_match_all(
            '/#([a-zA-Z0-9_]+)/',
            $text,
            $matches
        );

        return collect($matches[1])
            // ->map(fn($tag) => strtolower($tag))
            ->map(fn($tag) => ($tag))
            ->unique();
    }

    public function attach(Post $post, $text)
    {

        $tags = $this->extract($text);


        foreach ($tags as $tag) {

            $hashtag = Hashtag::firstOrCreate([
                'name' => $tag,
                // 'posts_count' => 1
            ]);


            $attach = PostHashTag::updateOrInsert(
                    [
                        
                        'id' => Str::uuid(),
                        'post_id' => $post->id,
                        'hashtag_id' => $hashtag->id,
                    ],
                    [
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

            // PostHashTag::create(['post_id' => $post->id, 'hashtag_id' => $hashtag->id]);

            if ($attach) {
                $hashtag->increment(
                    'posts_count'
                );

                $this->recordTrend(
                    $hashtag
                );
            }





            return $hashtag;
        }
    }

    private function recordTrend($hashtag)
    {

        HashtagTrend::create([

            'hashtag_id' => $hashtag->id,

            'score' => 1

        ]);
    }


}
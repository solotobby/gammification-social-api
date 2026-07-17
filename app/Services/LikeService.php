<?php

namespace App\Services;

use App\Mail\GeneralMail;
use App\Models\Post;
use App\Models\User;
use App\Models\UserLevel;
use App\Notifications\GeneralNotification;
use Illuminate\Support\Facades\DB;



class LikeService
{

    public function toggle(string $postUnicode, User $user): void
    {
        $post = Post::with('user')
            ->where('unicode', $postUnicode)
            ->firstOrFail();

        DB::transaction(function () use ($post, $user) {

            $isSelfLike = $user->id === $post->user_id;

            $existingLike = $post->likes()
                ->where('user_id', $user->id)
                ->first();

            if ($existingLike) {
                // 👎 Unlike
                $existingLike->delete();
                $post->decrement('likes');
                
                // userActivity('unlike');

                return;
            }
            //manage account monetization 
            $type = match (true) {
                $isSelfLike => 'self-like',
                $user->status === 'SHADOW_BANNED' => 'self-like',
                default => 'like',
            };

            // ❤️ Like
            $post->likes()->create([
                'user_id'        => $user->id,
                'poster_user_id' => $post->user_id,
                'is_paid'        => false,
                'amount'         => $this->calculateUniqueEarningPerLike($user->id),
                'type'           => $type, //$isSelfLike ? 'self-like' : 'like',
            ]);

            $post->increment('likes');

            // 🔔 Notify post owner (skip self-like)
            if (! $isSelfLike) {
                
                // $post->user->notify(
                //     (new GeneralNotification([
                //         'title'   => displayName($user->name) . ' liked your post',
                //         'message' => displayName($user->name) . ' liked your post',
                //         'icon'    => 'fa-heart text-danger',
                //         'url'     => url('timeline/' . $post->id),
                //     ]))->delay(now()->addSeconds(1))
                // );

            }

            // userActivity('like');
        });
    }

    private function sendLikeNotification(User $postOwner, User $liker, Post $post): void
    {
        // Send email notification
        // \Mail::to($postOwner->email)->send(new GeneralMail([
        //     'subject' => 'Your post was liked!',
        //     'body'    => displayName($liker->name) . ' liked your post.',
        // ]));
    }

    private function sendLikePushNotification(User $postOwner, User $liker, Post $post): void
    {
        // Send push notification
        // $postOwner->notify(new GeneralNotification([
        //     'title'   => displayName($liker->name) . ' liked your post',
        //     'message' => displayName($liker->name) . ' liked your post',
        //     'icon'    => 'fa-heart text-danger',
        //     'url'     => url('timeline/' . $post->id),
        // ]));
    }

    private function calculateUniqueEarningPerLike(string $userId): float
    {
        $userLevel = UserLevel::where('user_id', $userId)->first();

        if ($userLevel && ($userLevel->plan_name === 'Basic' || $userLevel->plan_name === 'Creator')) {
            return 0.00002;
        } else {
            return 0.0004;
        }
    }


}



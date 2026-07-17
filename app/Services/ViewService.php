<?php

namespace App\Services;

use App\Models\Post;
use App\Models\User;
use App\Models\UserLevel;
use App\Models\UserView;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ViewService
{
    // public function recordView($post, $user): void
    // {
    // //    Log::info('Recording view for Post ID: ' . $post->id . ' by User ID: ' . $user->id);

    //     DB::transaction(function () use ($post,  $user) {

    //         $user = User::find($user->id);

    //        Log::info('User ID: ' . $user->id);


    //         $isSelfView = $user->id === $post->user_id;

    //         //manage account monetization 
    //         $type = match (true) {
    //             $isSelfView => 'self-view',
    //             $user->status === 'SHADOW_BANNED' => 'self-view',
    //             default => 'view',
    //         };

    //         Log::info('View Type: ' . $type);

    //         $view = UserView::firstOrCreate(
    //             [
    //                 'user_id' => $user->id,
    //                 'post_id' => $post->id,
    //             ],
    //             [
    //                 'is_paid' => false,
    //                 'amount' => $this->calculateUniqueEarningPerView($user->id),
    //                 'poster_user_id' => $post->user_id,
    //                 'type' => $type, //$isSelfView ? 'self-view' : 'view',
    //             ]
    //         );

    //         Log::info('View Recorded: ' . $view->id . ' for Post ID: ' . $post->id . ' by User ID: ' . $user->id);

    //         // userActivity('views');

    //         $post->video?->increment('view_count');

    //         Log::info('Post ID: ' . $post->id . ' Video View Count: ' . $post->video?->view_count);

    //         if ($view->wasRecentlyCreated) {
    //             $post->increment('views');
    //         } else {
    //             $post->increment('views_external');
    //         }
    //     });
    // }

    public function recordView($post, $user): void
    {
        DB::transaction(function () use ($post, $user) {
            // Log::info('Recording view for Post ID: ' . $user);

            DB::transaction(function () use ($post, $user) {
                //User::where('id', $user->id)->first();
                Log::info('User ID: ' . $post->id );
            });
            // $isSelfView = $user->id === $post->user_id;

            // // Manage account monetization 
            // $type = match (true) {
            //     $isSelfView => 'self-view',
            //     $user->status === 'SHADOW_BANNED' => 'self-view',
            //     default => 'view',
            // };

            // $view = UserView::firstOrCreate(
            //     [
            //         'user_id' => $user->id,
            //         'post_id' => $post->id,
            //     ],
            //     [
            //         'is_paid' => false,
            //         'amount' => $this->calculateUniqueEarningPerView($user->id),
            //         'poster_user_id' => $post->user_id,
            //         'type' => $type,
            //     ]
            // );

            // // userActivity('views');

            // if ($view->wasRecentlyCreated) {
            //     $post->increment('views');
            // } else {
            //     $post->increment('views_external');
            // }

            // // Increment video view count if applicable
            // if ($post->video) {
            //     $post->video->increment('view_count');
            // }
        });
    }

    private function calculateUniqueEarningPerView($userId): float
    {
        $userLevel = UserLevel::where('user_id', $userId)->first();

        if ($userLevel && ($userLevel->plan_name === 'Basic' || $userLevel->plan_name === 'Creator')) {
            return 0.00002;
        } else {
            return 0.0008;
        }
    }
}

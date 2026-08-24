<?php

namespace App\Services;

use App\Models\Post;
use App\Models\User;
use App\Models\UserLevel;
use App\Models\UserView;
use Illuminate\Support\Facades\DB;

class ViewService
{
    public function recordView(string $postId, string $userId): void
    {
        DB::transaction(function () use ($postId, $userId) {
            $post = Post::with('video')->lockForUpdate()->find($postId);
            $user = User::query()->select(['id', 'status'])->find($userId);

            if (! $post || ! $user) {
                return;
            }

            $isSelfView = $user->id === $post->user_id;

            $type = match (true) {
                $isSelfView => 'self-view',
                $user->status === 'SHADOW_BANNED' => 'self-view',
                default => 'view',
            };

            $view = UserView::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'post_id' => $post->id,
                ],
                [
                    'is_paid' => false,
                    'amount' => $this->calculateUniqueEarningPerView($user->id),
                    'poster_user_id' => $post->user_id,
                    'type' => $type,
                ]
            );

            if ($post->video) {
                $post->video->increment('view_count');
            }

            if ($view->wasRecentlyCreated) {
                $post->increment('views');
            } else {
                $post->increment('views_external');
            }
        });
    }

    private function calculateUniqueEarningPerView(string $userId): float
    {
        $userLevel = UserLevel::where('user_id', $userId)->first();

        if ($userLevel && ($userLevel->plan_name === 'Basic' || $userLevel->plan_name === 'Creator')) {
            return 0.00002;
        }

        return 0.0008;
    }
}

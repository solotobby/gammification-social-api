<?php

namespace App\Jobs;

use App\Models\CommunityPost;
use App\Notifications\CommunityNewPostNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendCommunityNewPostNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $postId
    ) {}

    public function handle(): void
    {
        $post = CommunityPost::with(['community', 'user'])->find($this->postId);

        if (! $post || ! $post->community || ! $post->user) {
            return;
        }

        $community = $post->community;
        $author = $post->user;

        try {
            // Notify all active members of the community, strictly excluding the post author
            $community->members()
                ->where('users.id', '!=', $post->user_id)
                ->chunk(100, function ($members) use ($post, $author, $community) {
                    Notification::send($members, new CommunityNewPostNotification($post, $author, $community));
                });
        } catch (\Throwable $e) {
            Log::error('SendCommunityNewPostNotificationJob failed', [
                'post_id' => $this->postId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\CommentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessComment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        public string $postId,
        public User $user,
        public string $message,
    ) {}

    /**
     * Only the "is this the user's first comment on this post" earning
     * check needs serializing — two rapid comments from the SAME user on
     * the SAME post could otherwise both read isFirstComment=true and
     * both create a UserComment payout row. Comments from different
     * users, or on different posts, are never contended and run fully
     * in parallel across workers — this scales horizontally at
     * high traffic, it's not a global comment-table lock.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("comment:{$this->postId}:{$this->user->id}"))
                ->releaseAfter(5)
                ->expireAfter(30),
        ];
    }

    public function handle(CommentService $commentService): void
    {
        try {
            $commentService->addComment($this->postId, $this->user, $this->message);
        } catch (Throwable $e) {
            Log::error('Failed to process comment', [
                'post_id' => $this->postId,
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e; // rethrow so it retries per $tries, then lands in failed_jobs
        }
    }
}
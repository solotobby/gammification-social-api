<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\LikeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessToggleLike implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 5;

    public function __construct(
        public string $postId,      // used only for the lock key below
        public string $postUnicode, // what LikeService actually needs
        public User $user,
    ) {}

    /**
     * Serialize toggles for the same (post, user) pair so a rapid
     * double-tap can never race two inserts/deletes against each other.
     * releaseAfter: how long the second job waits before retrying.
     * expireAfter: safety valve — don't hold the lock forever if a
     * worker dies mid-job.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("like:{$this->postId}:{$this->user->id}"))
                ->releaseAfter(5)
                ->expireAfter(30),
        ];
    }

    public function handle(LikeService $likeService): void
    {
        
        try {
            $likeService->toggle($this->postUnicode, $this->user);
        } catch (Throwable $e) {
            Log::error('Failed to process like toggle', [
                'post_id' => $this->postId,
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e; // rethrow so it retries per $tries, then lands in failed_jobs for visibility
        }
    }
}
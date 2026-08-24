<?php

namespace App\Jobs;

use App\Services\ViewService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessView implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        public string $postId,
        public string $userId,
    ) {}

    public function handle(ViewService $viewService): void
    {
        try {
            $viewService->recordView($this->postId, $this->userId);
        } catch (Throwable $e) {
            Log::error('Failed to record view', [
                'post_id' => $this->postId,
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

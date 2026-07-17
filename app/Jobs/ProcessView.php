<?php

namespace App\Jobs;

use App\Models\Post;
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
        public string $post,
        public string $user,
    ) {}

    public function handle(ViewService $viewService): void
    {
        try {


            // Log::info('Processing view for post', [
            //     'post' => $this->post,
            //     'user' => $this->user,
            // ]);

            $viewService->recordView($this->post, $this->user);

        } catch (Throwable $e) {
            Log::error('Failed to record view', [
                // 'post_id' => $this->post,
                // 'user_id' => $this->user,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
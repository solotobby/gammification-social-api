<?php

namespace App\Jobs;

use App\Models\CommunityPost;
use App\Models\CommunityPostMedia;
use App\Services\ImageUploadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessCommunityPostImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 15;

    public int $timeout = 120;

    public function __construct(
        public string $mediaId,
        public string $localPath,
        public string $userId,
        public string $communityId,
    ) {
        $this->onQueue('media');
    }

    public function handle(ImageUploadService $service): void
    {
        $record = CommunityPostMedia::find($this->mediaId);

        if (! $record) {
            @unlink($this->localPath);

            return;
        }

        try {
            $info = @getimagesize($this->localPath) ?: [null, null];
            $key = $service->uploadReturningKey(
                $this->localPath,
                "communities/{$this->communityId}/posts",
                $this->userId,
            );

            $record->update([
                'processing_status' => 'completed',
                'path' => $key,
                'thumbnail_path' => $key,
                'width' => $info[0],
                'height' => $info[1],
                'size_bytes' => @filesize($this->localPath) ?: null,
                'failure_reason' => null,
            ]);

            $this->refreshPostMediaStatus($record->community_post_id);

            Log::info('Community image processing completed', [
                'media_id' => $this->mediaId,
            ]);
        } catch (Throwable $e) {
            Log::error('Community image processing failed', [
                'media_id' => $this->mediaId,
                'error' => $e->getMessage(),
            ]);

            $record->update([
                'processing_status' => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);

            $this->refreshPostMediaStatus($record->community_post_id);
        } finally {
            @unlink($this->localPath);
        }
    }

    private function refreshPostMediaStatus(string $postId): void
    {
        CommunityPost::find($postId)?->refreshMediaStatus();
    }
}

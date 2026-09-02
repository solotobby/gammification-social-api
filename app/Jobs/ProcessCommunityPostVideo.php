<?php

namespace App\Jobs;

use App\Models\CommunityPost;
use App\Models\CommunityPostMedia;
use App\Services\VideoUploadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessCommunityPostVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public int $backoff = 30;

    public function __construct(
        public string $mediaId,
        public string $localPath,
        public string $communityId,
    ) {
        $this->onQueue('media');
    }

    public function handle(VideoUploadService $service): void
    {
        $record = CommunityPostMedia::find($this->mediaId);

        if (! $record) {
            @unlink($this->localPath);

            return;
        }

        try {
            $data = $service->uploadCommunityVideo($this->localPath, $this->communityId);

            $record->update([
                'processing_status' => 'completed',
                'path' => $data['path'],
                'width' => $data['width'],
                'height' => $data['height'],
                'size_bytes' => $data['size_bytes'],
                'failure_reason' => null,
            ]);

            $this->refreshPostMediaStatus($record->community_post_id);

            Log::info('Community video processing completed', [
                'media_id' => $this->mediaId,
            ]);
        } catch (Throwable $e) {
            Log::error('Community video processing failed', [
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

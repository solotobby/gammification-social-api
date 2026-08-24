<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\PostVideo;
use App\Services\VideoUploadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessPostVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 600;
    public int $backoff = 30;

    public function __construct(
        public string $postVideoId,
        public string $localPath,
        public string $userId,
        public string $level,
    ) {
        $this->onQueue('media');
    }

    public function handle(VideoUploadService $service): void
    {
        $record = PostVideo::find($this->postVideoId);

        if (! $record) {
            @unlink($this->localPath);
            return;
        }

        try {
            $data = $service->upload($this->localPath, $this->level, $this->userId);
            $versions = $data['quality_versions'] ?? [];

            $record->update([
                'processing_status' => 'completed',
                'path' => $data['url'],
                'hd_path' => $versions['high'] ?? null,
                'thumbnail_path' => $data['thumbnail'],
                'duration' => $data['duration'],
                'width' => $data['width'],
                'height' => $data['height'],
                'format' => $data['format'] ?? 'mp4',
                'file_size' => $data['file_size'],
                'public_id' => $data['public_id'] ?? null,
                'quality_versions' => $versions,
                'failure_reason' => null,
            ]);

            Post::where('id', $record->post_id)->update([
                'has_video' => true,
                'media_status' => 'completed',
            ]);
        } catch (Throwable $e) {
            Log::error('Video processing failed', [
                'post_video_id' => $this->postVideoId,
                'error' => $e->getMessage(),
                'previous' => $e->getPrevious()?->getMessage(),
            ]);

            $record->update([
                'processing_status' => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);

            Post::where('id', $record->post_id)->update(['media_status' => 'failed']);
        } finally {
            @unlink($this->localPath);
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\PostImages;
use App\Services\ImageUploadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessPostImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 15;
    public int $timeout = 120;

    public function __construct(
        public string $postImageId,
        public string $localPath,
        public string $userId,
    ) {
        $this->onQueue('media');
    }

    public function handle(ImageUploadService $service): void
    {
        $record = PostImages::find($this->postImageId);

        if (! $record) {
            @unlink($this->localPath);
            return;
        }

        try {
            $info = @getimagesize($this->localPath) ?: [null, null];
            $url = $service->upload(
                $this->localPath,
                "payhankey_media/images/{$this->userId}",
                $this->userId,
            );

            $record->update([
                'processing_status' => 'completed',
                'path' => $url,
                'thumbnail_path' => $url,
                'medium_path' => $url,
                'full_path' => $url,
                'width' => $info[0],
                'height' => $info[1],
                'size_bytes' => @filesize($this->localPath) ?: null,
                'failure_reason' => null,
            ]);

            Post::where('id', $record->post_id)->update([
                'has_images' => true,
                'media_status' => 'completed',
            ]);

            Log::info('Image processing completed', [
                'post_image_id' => $this->postImageId,
            ]);
        } catch (Throwable $e) {
            Log::error('Image processing failed', [
                'post_image_id' => $this->postImageId,
                'error' => $e->getMessage(),
            ]);

            $record->update([
                'processing_status' => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);

            Post::where('id', $record->post_id)->update([
                'media_status' => 'failed',
            ]);
        } finally {
            @unlink($this->localPath);
        }
    }
}

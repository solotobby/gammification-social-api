<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\PostImages;
use App\Services\Media\ImageProcessingService;
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

    public function __construct(
        public string $postImageId,
        public string $localPath,
        public string $userId,
    ) {}

    public function handle(ImageProcessingService $service): void
    {
        $record = PostImages::find($this->postImageId);

        if (!$record) {
            @unlink($this->localPath);
            return;
        }

        try {
            $data = $service->process($this->localPath, $this->userId);

            $record->update([
                'processing_status' => 'completed',
                'thumbnail_path' => $data['thumb'],
                'medium_path' => $data['medium'],
                'full_path' => $data['full'],
                'width' => $data['width'],
                'height' => $data['height'],
                'size_bytes' => $data['size_bytes'],
            ]);

            Post::where('id', $record->post_id)->update(['has_images' => true, 'media_status' => 'completed']);
            if($record->failure_reason == null) {
                $record->update(['processing_status' => 'completed']);
            }
            Log::info('Image processing completed', [
                'post_image_id' => $this->postImageId,
            ]);


        } catch (Throwable $e) {
            Log::error('Image processing failed', [
                'post_image_id' => $this->postImageId,
                'error' => $e->getMessage(),
            ]);
            $record->update(['status' => 'failed', 'failure_reason' => $e->getMessage()]);
        } finally {
            @unlink($this->localPath);
        }
    }
}
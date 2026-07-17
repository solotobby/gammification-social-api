<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\PostVideo;
use App\Services\Media\VideoProcessingService;
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

    public function __construct(
        public string $postVideoId,
        public string $localPath,
        public string $userId,
        public int $maxSeconds,
        public bool $includeHd,
    ) {}

    public function handle(VideoProcessingService $service): void
    {
        $record = PostVideo::find($this->postVideoId);

        if (!$record) {
            @unlink($this->localPath);
            return;
        }

        try {
            $duration = $service->probeDuration($this->localPath);

            if ($this->maxSeconds > 0 && $duration > $this->maxSeconds + 1) {
                $record->update([
                    'processing_status' => 'failed',
                    'failure_reason' => sprintf(
                        'Video is %ds, longer than the %ds allowed on this plan',
                        round($duration),
                        $this->maxSeconds
                    ),
                ]);
                return;
            }

            $data = $service->process($this->localPath, $this->userId, $this->includeHd);

            $record->update([
                'processing_status' => 'processing',
                'path' => $data['sd'],                 // SD rendition — always generated
                'hd_path' => $data['hd'] ?? null,       // Influencer-only extra rendition
                'thumbnail_path' => $data['poster'],    // poster frame
                'duration' => $data['duration'],
                'width' => $data['width'],
                'height' => $data['height'],
                'format' => 'webm',
                'file_size' => $data['size_bytes'],
                'quality_version' => $this->includeHd ? 'sd+hd' : 'sd',
                'failure_reason' => null,
            ]);
        
            Post::where('id', $record->post_id)->update(['has_video' => true, 'media_status' => 'completed']);

            if($record->failure_reason == null) {
                $record->update(['processing_status' => 'completed']);
            }
            
        } catch (Throwable $e) {
            Log::error('Video processing failed', [
                'post_video_id' => $this->postVideoId,
                'error' => $e->getMessage(),
                'previous' => $e->getPrevious()?->getMessage(), // TEMP — this usually has ffmpeg's real stderr output
            ]);
            $record->update([
                'processing_status' => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);
        } finally {
            @unlink($this->localPath);
        }

        // Log::error('Video processing failed', [
        //     'post_video_id' => $this->postVideoId,
        //     'error' => $e->getMessage(),
        // ]);
        // $record->update([
        //     'processing_status' => 'failed',
        //     'failure_reason' => $e->getMessage(),
        // ]);

    }
}

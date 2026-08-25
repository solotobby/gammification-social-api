<?php

namespace App\Services;

use App\Models\Post;
use App\Models\PostImages;
use App\Models\PostVideo;
use App\Support\StoredMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PostUpdateService
{
    public function __construct(protected HashTagServices $hashtagServices) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{post: Post, queued: array{images: array<int, array{id: string, local: string}>, video: ?array{id: string, local: string}}, media_status: string}
     */
    public function update(
        Post $post,
        string $userId,
        string $level,
        array $input,
        array $newImages = [],
        ?UploadedFile $newVideo = null,
    ): array {
        $removeImageIds = array_values(array_unique($input['remove_image_ids'] ?? []));
        $removeVideo = (bool) ($input['remove_video'] ?? false);
        $hasMediaChanges = $newImages !== []
            || $newVideo
            || $removeImageIds !== []
            || $removeVideo;

        if ($post->media_status === 'processing' && $hasMediaChanges) {
            throw new \InvalidArgumentException('Media cannot be changed while processing is in progress');
        }

        $tier = config("media_tiers.tiers.{$level}", config('media_tiers.tiers.default'));

        if ($newImages !== [] && $newVideo) {
            throw new \InvalidArgumentException('Post with either images or a video, not both');
        }

        $existingImages = PostImages::where('post_id', $post->id)->get();
        $imagesToRemove = $existingImages->whereIn('id', $removeImageIds);

        if ($imagesToRemove->count() !== count($removeImageIds)) {
            throw new \InvalidArgumentException('One or more image ids are invalid for this post');
        }

        $remainingImageCount = $existingImages->count() - $imagesToRemove->count();
        $finalImageCount = $remainingImageCount + count($newImages);
        $willHaveVideo = ($post->video && ! $removeVideo && ! $newVideo) || $newVideo;

        if ($finalImageCount > 0 && $willHaveVideo) {
            throw new \InvalidArgumentException('Post cannot contain both images and video');
        }

        if ($newImages !== [] && ! $tier['images']['allowed']) {
            throw new \InvalidArgumentException('Your account level cannot upload images');
        }

        if ($newVideo && ! $tier['video']['allowed']) {
            throw new \InvalidArgumentException('Your account level cannot upload video');
        }

        if ($finalImageCount > ($tier['images']['max'] ?? 0)) {
            throw new \InvalidArgumentException('Image count exceeds your account limit');
        }

        $content = null;
        if (array_key_exists('content', $input)) {
            $content = $this->normalizeContent($input['content']);

            if ($content === '') {
                throw new \InvalidArgumentException('Post content cannot be empty');
            }
        }

        $queued = ['images' => [], 'video' => null];

        $post = DB::transaction(function () use (
            $post,
            $userId,
            $content,
            $imagesToRemove,
            $removeVideo,
            $newImages,
            $newVideo,
            $hasMediaChanges,
            &$queued,
        ) {
            if ($content !== null) {
                $post->update(['content' => $content]);
                $this->hashtagServices->sync($post, $content);
            }

            if (! $hasMediaChanges) {
                return $post->fresh();
            }

            foreach ($imagesToRemove as $image) {
                $this->deleteImageRecord($image);
            }

            if ($removeVideo || $newVideo) {
                $this->deleteVideoRecord($post);
            }

            foreach ($newImages as $image) {
                $localPath = $image->store('queue/images', 'local');

                $record = PostImages::create([
                    'user_id' => $userId,
                    'post_id' => $post->id,
                    'path' => $localPath,
                    'processing_status' => 'processing',
                ]);

                $queued['images'][] = [
                    'id' => $record->id,
                    'local' => Storage::disk('local')->path($localPath),
                ];
            }

            if ($newVideo) {
                $localPath = $newVideo->store('queue/videos', 'local');

                $record = PostVideo::create([
                    'id' => (string) Str::uuid(),
                    'path' => $localPath,
                    'user_id' => $userId,
                    'post_id' => $post->id,
                    'processing_status' => 'processing',
                ]);

                $queued['video'] = [
                    'id' => $record->id,
                    'local' => Storage::disk('local')->path($localPath),
                ];
            }

            $post->refresh();

            if ($post->images()->exists() || $post->video()->exists()) {
                $post->refreshMediaStatus();
            } else {
                $post->update([
                    'has_images' => false,
                    'has_video' => false,
                    'media_status' => 'completed',
                ]);
            }

            return $post->fresh();
        });

        return [
            'post' => $post,
            'queued' => $queued,
            'media_status' => $post->media_status,
        ];
    }

    public function normalizeContent(string $raw): string
    {
        $text = strip_tags($raw);
        $pattern = '/\b(?:https?:\/\/|www\.)\S+\b/';
        $replacement = '<a href="$0" target="_blank" rel="noopener noreferrer">$0</a>';

        return trim((string) preg_replace($pattern, $replacement, $text));
    }

    private function deleteImageRecord(PostImages $image): void
    {
        foreach (['path', 'thumbnail_path', 'medium_path', 'full_path'] as $field) {
            StoredMedia::delete($image->{$field});
        }

        $image->delete();
    }

    private function deleteVideoRecord(Post $post): void
    {
        $video = PostVideo::where('post_id', $post->id)->first();

        if (! $video) {
            return;
        }

        StoredMedia::delete($video->path);
        StoredMedia::delete($video->hd_path);
        StoredMedia::delete($video->thumbnail_path);

        if (is_array($video->quality_versions)) {
            foreach ($video->quality_versions as $url) {
                StoredMedia::delete($url);
            }
        }

        $video->forceDelete();
    }
}

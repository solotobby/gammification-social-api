<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageUploadService
{
    public function maxFileKb(): int
    {
        return (int) config('media.image_max_kb', 10240);
    }

    /**
     * Compress and upload an image as WebP to Spaces.
     */
    public function upload(UploadedFile|string $file, string $folder = 'payhankey_media/images', ?string $userId = null): string
    {
        $userId = $userId ?? (string) auth()->id();
        $filename = Str::uuid().'-'.$userId.'.webp';
        $key = trim($folder, '/').'/'.$filename;

        $binary = $this->toWebpBinary($file);

        Storage::disk('spaces')->put($key, $binary, ['visibility' => 'public']);

        return $this->spacesUrl($key);
    }

    /**
     * @return resource|string binary stream for Storage::put
     */
    private function toWebpBinary(UploadedFile|string $file): mixed
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;

        if (! extension_loaded('gd') || ! function_exists('imagewebp')) {
            return fopen($path, 'r');
        }

        $info = @getimagesize($path);
        if ($info === false) {
            return fopen($path, 'r');
        }

        [$width, $height, $type] = $info;
        $source = $this->loadImage($path, $type);

        if ($source === null) {
            return fopen($path, 'r');
        }

        $maxW = (int) config('media.image.max_width', 2048);
        $maxH = (int) config('media.image.max_height', 2048);
        $quality = (int) config('media.image.quality', 82);

        if ($width > $maxW || $height > $maxH) {
            $ratio = min($maxW / $width, $maxH / $height);
            $newW = max(1, (int) round($width * $ratio));
            $newH = max(1, (int) round($height * $ratio));
            $resized = imagecreatetruecolor($newW, $newH);

            if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
            }

            imagecopyresampled($resized, $source, 0, 0, 0, 0, $newW, $newH, $width, $height);
            imagedestroy($source);
            $source = $resized;
        }

        ob_start();
        imagewebp($source, null, $quality);
        imagedestroy($source);
        $binary = ob_get_clean();

        return $binary !== false ? $binary : fopen($path, 'r');
    }

    private function loadImage(string $path, int $type): ?\GdImage
    {
        return match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path) ?: null,
            IMAGETYPE_PNG => @imagecreatefrompng($path) ?: null,
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($path) ?: null) : null,
            IMAGETYPE_GIF => @imagecreatefromgif($path) ?: null,
            default => null,
        };
    }

    private function spacesUrl(string $key): string
    {
        $base = rtrim((string) config('filesystems.disks.spaces.url'), '/');

        return $base.'/'.ltrim($key, '/');
    }
}

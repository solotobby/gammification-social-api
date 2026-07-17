<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;

class ImageProcessingService
{
    protected ImageManager $manager;

    public function __construct()
    {
        $this->manager = ImageManager::usingDriver(Driver::class);
    }

    public function process(string $localPath, string $userId): array
    {
        $variants = config('media_tiers.image.variants');

        $probe = $this->manager->decodePath($localPath);

        $result = [
            'width' => $probe->width(),
            'height' => $probe->height(),
            'size_bytes' => filesize($localPath),
        ];

        foreach ($variants as $name => $spec) {
            $image = $this->manager->decodePath($localPath);

            if ($image->width() > $spec['width']) {
                $image->scaleDown(width: $spec['width']);
            }

            $encoded = $image->encodeUsingFormat(Format::WEBP, quality: $spec['quality']);

            $filename = Str::uuid() . "-{$name}.webp";
            $path = "payhankey_media/images/{$userId}/{$filename}";

            Storage::disk('spaces')->put($path, (string) $encoded, 'public');

            $result[$name] = config('filesystems.disks.spaces.url') . '/' . $path;
        }

        return $result;
    }
}
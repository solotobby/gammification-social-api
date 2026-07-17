<?php

namespace App\Services\Media;

use FFMpeg\Coordinate\Dimension;
use FFMpeg\Coordinate\TimeCode;
use App\Services\Media\Format\WebMOpus;
// use FFMpeg\Format\Video\WebM;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use RuntimeException;

class VideoProcessingService
{
    protected FFMpeg $ffmpeg;
    protected FFProbe $ffprobe;

    public function __construct()
    {
        $binaries = [
            'ffmpeg.binaries'  => env('FFMPEG_BINARY', '/usr/bin/ffmpeg'),
            'ffprobe.binaries' => env('FFPROBE_BINARY', '/usr/bin/ffprobe'),
            'timeout'          => 300,
            'ffmpeg.threads'   => 2,
        ];

        $this->ffmpeg = FFMpeg::create($binaries);
        $this->ffprobe = FFProbe::create($binaries);
    }

    public function probeDuration(string $localPath): float
    {
        return (float) $this->ffprobe->format($localPath)->get('duration');
    }

    public function process(string $localPath, string $userId, bool $includeHd): array
    {
        $stream = $this->ffprobe->streams($localPath)->videos()->first();

        if (!$stream) {
            throw new RuntimeException('No video stream found in upload');
        }

        $width = (int) $stream->get('width');
        $height = (int) $stream->get('height');
        $duration = (int) round($this->probeDuration($localPath));

        $result = [
            'width' => $width,
            'height' => $height,
            'duration' => $duration,
            'size_bytes' => filesize($localPath),
        ];

        $result['poster'] = $this->extractPoster($localPath, $userId, $duration);

        $renditions = config('media_tiers.video.renditions');

        foreach (['sd', 'hd'] as $tier) {
            if ($tier === 'hd' && !$includeHd) {
                $result['hd'] = null;
                continue;
            }

            $result[$tier] = $this->transcode($localPath, $userId, $tier, $renditions[$tier], $width, $height);
        }

        return $result;
    }

    protected function extractPoster(string $localPath, string $userId, int $duration): string
    {
        $second = min(config('media_tiers.video.poster_second'), max($duration - 1, 0));

        $frameLocal = sys_get_temp_dir() . '/' . Str::uuid() . '.jpg';
        $this->ffmpeg->open($localPath)
            ->frame(TimeCode::fromSeconds($second))
            ->save($frameLocal);

        $webp = ImageManager::usingDriver(Driver::class)
            ->decodePath($frameLocal)
            ->encodeUsingFormat(Format::WEBP, quality: 75);

        @unlink($frameLocal);

        $path = "payhankey_media/videos/{$userId}/" . Str::uuid() . '-poster.webp';
        Storage::disk('spaces')->put($path, (string) $webp, 'public');

        return config('filesystems.disks.spaces.url') . '/' . $path;
    }


// remove: use FFMpeg\Format\Video\WebM;

protected function transcode(string $localPath, string $userId, string $tier, array $spec, int $width, int $height): string
{
    $video = $this->ffmpeg->open($localPath);
    $hasAudio = $this->ffprobe->streams($localPath)->audios()->count() > 0;

    $targetHeight = min($spec['height'], $height);
    $targetWidth = (int) round($width * ($targetHeight / $height));
    $targetWidth -= $targetWidth % 2;

    $video->filters()
        ->resize(new Dimension($targetWidth, $targetHeight))
        ->synchronize();

    $format = new WebMOpus();
    $format->setVideoCodec(config('media_tiers.video.codec')); // libvpx-vp9

    if ($hasAudio) {
        $format->setAudioCodec('libopus');
        $format->setAudioKiloBitrate($spec['audio_kbps']);
    } else {
        $format->setAudioCodec('none');
    }

    $format->setKiloBitrate($spec['video_kbps']);
    $format->setAdditionalParameters(['-deadline', 'good', '-cpu-used', '2', '-row-mt', '1']);

    $outLocal = sys_get_temp_dir() . '/' . Str::uuid() . "-{$tier}.webm";
    $video->save($format, $outLocal);

    $remotePath = "payhankey_media/videos/{$userId}/" . Str::uuid() . "-{$tier}.webm";
    Storage::disk('spaces')->put($remotePath, file_get_contents($outLocal), 'public');
    @unlink($outLocal);

    return config('filesystems.disks.spaces.url') . '/' . $remotePath;
}
}

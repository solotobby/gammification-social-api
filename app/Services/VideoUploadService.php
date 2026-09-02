<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoUploadService
{
    private const DEFAULT_MAX_KB = [
        'Creator' => 1048576, // 1 GB
        'Influencer' => 1048576, // 1 GB
    ];

    private const DEFAULT_MAX_SECONDS = [
        'Creator' => 0,
        'Influencer' => 600,
    ];

    public function maxSeconds(string $level): int
    {
        $level = $this->normalizeLevel($level);
        $configured = config("media.video_max_seconds.{$level}");
        $value = is_numeric($configured) ? (int) $configured : 0;

        return $value > 0 ? $value : (self::DEFAULT_MAX_SECONDS[$level] ?? 0);
    }

    public function maxFileKb(string $level): int
    {
        $level = $this->normalizeLevel($level);
        $configured = config("media.video_max_kb.{$level}");
        $value = is_numeric($configured) ? (int) $configured : 0;

        return $value > 0 ? $value : (self::DEFAULT_MAX_KB[$level] ?? 0);
    }

    /**
     * Broad video acceptance — ffmpeg normalizes exotic formats.
     */
    public function allowedMimetypes(): string
    {
        return implode(',', [
            'video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm',
            'video/x-matroska', 'video/3gpp', 'video/3gpp2', 'video/mpeg',
            'video/x-flv', 'video/ogg', 'video/x-ms-wmv', 'video/x-m4v',
            'application/octet-stream',
        ]);
    }

    /**
     * Stage a raw upload for background transcoding. Returns job id.
     */
    public function stage(string $realPath, ?string $originalName = null): string
    {
        $jobId = (string) Str::uuid();
        $dir = $this->stagingDir($jobId);
        File::ensureDirectoryExists($dir);

        $extension = $this->guessExtension($realPath, $originalName);
        $dest = $dir.'/source.'.$extension;

        if (! File::copy($realPath, $dest)) {
            throw new \RuntimeException('Could not stage video for processing.');
        }

        return $jobId;
    }

    /**
     * Transcode staged upload into high / medium / low MP4 + WebP poster.
     *
     * @return array{
     *   url: string,
     *   public_id: string,
     *   thumbnail: ?string,
     *   duration: ?int,
     *   width: ?int,
     *   height: ?int,
     *   format: string,
     *   file_size: ?int,
     *   quality_versions: array<string, string>
     * }
     */
    public function processStaged(string $jobId, string $level, ?string $userId = null): array
    {
        $stagingDir = $this->stagingDir($jobId);

        if (! File::isDirectory($stagingDir)) {
            throw new \RuntimeException('Staged video not found. Please upload again.');
        }

        $source = $this->findSourceFile($stagingDir);

        if ($source === null) {
            throw new \RuntimeException('Staged video file is missing.');
        }

        return $this->process($source, $level, $userId, $jobId);
    }

    /**
     * Synchronous path — stage + process in one call (legacy / tests / queue jobs).
     */
    public function upload(string $realPath, string $level, ?string $userId = null): array
    {
        $jobId = $this->stage($realPath);

        try {
            return $this->processStaged($jobId, $level, $userId);
        } catch (\Throwable $e) {
            $this->cleanupStaging($jobId);
            throw $e;
        }
    }

    /**
     * Upload a community post video directly to Spaces (no transcode tiers).
     *
     * @return array{path: string, url: string, width: ?int, height: ?int, size_bytes: ?int, duration: ?int}
     */
    public function uploadCommunityVideo(string $localPath, string $communityId): array
    {
        $extension = strtolower(pathinfo($localPath, PATHINFO_EXTENSION) ?: 'mp4');
        $allowed = ['mp4', 'mov', 'webm', 'quicktime'];
        if (! in_array($extension, $allowed, true)) {
            $extension = 'mp4';
        }

        if ($extension === 'quicktime') {
            $extension = 'mov';
        }

        $key = 'communities/'.$communityId.'/posts/'.Str::uuid().'.'.$extension;
        $disk = Storage::disk('spaces');

        $disk->put($key, fopen($localPath, 'r'), [
            'visibility' => 'public',
            'ContentType' => match ($extension) {
                'mov' => 'video/quicktime',
                'webm' => 'video/webm',
                default => 'video/mp4',
            },
        ]);

        $meta = $this->hasFfmpeg() ? $this->probe($localPath) : [];

        return [
            'path' => $key,
            'url' => $this->spacesUrl($key),
            'width' => $meta['width'] ?? null,
            'height' => $meta['height'] ?? null,
            'size_bytes' => file_exists($localPath) ? filesize($localPath) : null,
            'duration' => isset($meta['duration']) ? (int) round((float) $meta['duration']) : null,
        ];
    }

    /**
     * @return array{
     *   url: string,
     *   public_id: string,
     *   thumbnail: ?string,
     *   duration: ?int,
     *   width: ?int,
     *   height: ?int,
     *   format: string,
     *   file_size: ?int,
     *   quality_versions: array<string, string>
     * }
     */
    private function process(string $sourcePath, string $level, ?string $userId, string $token): array
    {
        $level = $this->normalizeLevel($level);

        if ($level !== 'Influencer') {
            throw new \RuntimeException('Only Influencer accounts can upload rolls.');
        }

        $maxSeconds = $this->maxSeconds($level);

        if ($maxSeconds === 0) {
            throw new \RuntimeException('Your account level cannot upload rolls.');
        }

        if (! $this->hasFfmpeg()) {
            throw new \RuntimeException(
                'Video processing is unavailable on this server. Install ffmpeg and ffprobe, then restart the queue worker.'
            );
        }

        set_time_limit(0);

        $userId = $userId ?? (string) Auth::id();
        $prefix = "payhankey_videos/{$userId}/{$token}";
        $work = storage_path('app/video-work/'.$token);

        File::ensureDirectoryExists($work);

        try {
            $meta = $this->probe($sourcePath);

            // Normalize any container/codec to a master H.264 MP4 first (TikTok-style pipeline).
            $master = $work.'/master.mp4';
            if (! $this->normalizeToMaster($sourcePath, $master, $maxSeconds, $meta)) {
                throw new \RuntimeException('Could not read or convert this video. Try MP4 or MOV, or re-export from your editor.');
            }

            $meta = $this->probe($master);
            $qualityVersions = [];
            $disk = Storage::disk('spaces');
            $variants = config('media.video_variants', []);
            $done = 0;
            $total = max(count($variants), 1);

            foreach ($variants as $label => $cfg) {
                $local = $work.'/'.$cfg['file'];
                if ($this->transcodeVariant($master, $local, $cfg, $maxSeconds)) {
                    $key = $prefix.'/'.$cfg['file'];
                    $disk->put($key, file_get_contents($local), [
                        'visibility' => 'public',
                        'ContentType' => 'video/mp4',
                    ]);
                    $qualityVersions[$label] = $this->spacesUrl($key);
                } else {
                    Log::warning('Video variant failed', ['label' => $label, 'token' => $token]);
                }

                $done++;
                $this->reportProgress($token, 25 + (int) round(($done / $total) * 65));
            }

            if ($qualityVersions === []) {
                throw new \RuntimeException('All video quality renditions failed during processing.');
            }

            // Fill missing tiers from nearest available (never leave playback without a URL).
            $qualityVersions = $this->fillMissingVariants($qualityVersions);

            $thumbnail = null;
            $posterLocal = $work.'/poster.webp';
            if ($this->extractPosterWebp($master, $posterLocal)) {
                $posterKey = $prefix.'/poster.webp';
                $disk->put($posterKey, file_get_contents($posterLocal), [
                    'visibility' => 'public',
                    'ContentType' => 'image/webp',
                ]);
                $thumbnail = $this->spacesUrl($posterKey);
            } else {
                $posterJpg = $work.'/poster.jpg';
                if ($this->extractPosterJpg($master, $posterJpg)) {
                    $posterKey = $prefix.'/poster.jpg';
                    $disk->put($posterKey, file_get_contents($posterJpg), [
                        'visibility' => 'public',
                        'ContentType' => 'image/jpeg',
                    ]);
                    $thumbnail = $this->spacesUrl($posterKey);
                }
            }

            $primaryUrl = $qualityVersions['medium']
                ?? $qualityVersions['high']
                ?? reset($qualityVersions);

            return [
                'url' => $primaryUrl,
                'public_id' => $prefix,
                'thumbnail' => $thumbnail,
                'duration' => isset($meta['duration']) ? (int) round((float) $meta['duration']) : null,
                'width' => $meta['width'] ?? null,
                'height' => $meta['height'] ?? null,
                'format' => 'mp4',
                'file_size' => file_exists($master) ? filesize($master) : null,
                'quality_versions' => $qualityVersions,
            ];
        } finally {
            File::deleteDirectory($work);
            $this->cleanupStaging($token);
        }
    }

    public function delete(string $storagePrefix): void
    {
        if ($storagePrefix === '') {
            return;
        }

        $disk = Storage::disk('spaces');

        foreach ($disk->allFiles($storagePrefix) as $file) {
            $disk->delete($file);
        }
    }

    public function cleanupStaging(string $jobId): void
    {
        $dir = $this->stagingDir($jobId);
        if (File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }
    }

    private function stagingDir(string $jobId): string
    {
        $base = config('media.staging_path', 'video-staging');

        return storage_path('app/'.$base.'/'.$jobId);
    }

    private function findSourceFile(string $dir): ?string
    {
        foreach (File::files($dir) as $file) {
            if (str_starts_with($file->getFilename(), 'source.')) {
                return $file->getPathname();
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $versions
     * @return array<string, string>
     */
    private function fillMissingVariants(array $versions): array
    {
        $order = ['high', 'medium', 'low'];
        $fallback = null;

        foreach ($order as $tier) {
            if (isset($versions[$tier])) {
                $fallback = $versions[$tier];
            } elseif ($fallback !== null) {
                $versions[$tier] = $fallback;
            }
        }

        $fallback = $versions['low'] ?? $versions['medium'] ?? $versions['high'] ?? null;
        foreach ($order as $tier) {
            $versions[$tier] ??= $fallback;
        }

        return array_filter($versions);
    }

    private function spacesUrl(string $key): string
    {
        $base = rtrim((string) config('filesystems.disks.spaces.url'), '/');

        return $base.'/'.ltrim($key, '/');
    }

    private function guessExtension(string $path, ?string $originalName = null): string
    {
        $ext = strtolower(pathinfo($originalName ?: $path, PATHINFO_EXTENSION));

        $allowed = ['mp4', 'mov', 'webm', 'avi', 'mkv', 'mpeg', 'mpg', '3gp', '3g2', 'flv', 'wmv', 'm4v', 'ogv', 'ts', 'mts'];

        return in_array($ext, $allowed, true) ? $ext : 'mp4';
    }

    private function hasFfmpeg(): bool
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $ffmpeg = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));
        $ffprobe = trim((string) shell_exec('command -v ffprobe 2>/dev/null'));

        return $cached = ($ffmpeg !== '' && $ffprobe !== '');
    }

    private function probe(string $path): array
    {
        $meta = [
            'duration' => null,
            'width' => null,
            'height' => null,
            'format' => pathinfo($path, PATHINFO_EXTENSION),
            'size' => file_exists($path) ? filesize($path) : null,
        ];

        $cmd = sprintf(
            'ffprobe -v quiet -print_format json -show_format -show_streams %s 2>/dev/null',
            escapeshellarg($path)
        );

        $json = json_decode((string) shell_exec($cmd), true);
        if (! is_array($json)) {
            return $meta;
        }

        if (isset($json['format']['duration'])) {
            $meta['duration'] = (float) $json['format']['duration'];
        }

        foreach ($json['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'video') {
                $meta['width'] = isset($stream['width']) ? (int) $stream['width'] : null;
                $meta['height'] = isset($stream['height']) ? (int) $stream['height'] : null;
                break;
            }
        }

        return $meta;
    }

    /**
     * Decode any supported format into a clean master MP4 (H.264 + AAC).
     */
    private function normalizeToMaster(string $input, string $output, int $maxSeconds, array $meta): bool
    {
        $scale = "scale='min(1080,iw)':-2";
        $duration = (float) ($meta['duration'] ?? 0);
        $trimArgs = ($duration > $maxSeconds) ? ['-t', (string) $maxSeconds] : [];

        $ok = $this->runFfmpeg(array_merge([
            '-y', '-i', $input,
        ], $trimArgs, [
            '-vf', $scale,
            '-c:v', 'libx264',
            '-preset', 'fast',
            '-crf', '22',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-movflags', '+faststart',
            '-pix_fmt', 'yuv420p',
            '-map', '0:v:0?',
            '-map', '0:a:0?',
            $output,
        ]));

        if ($ok && file_exists($output) && filesize($output) > 0) {
            return true;
        }

        // Fallback without explicit stream maps (some files have odd stream layouts).
        return $this->runFfmpeg(array_merge([
            '-y', '-i', $input,
        ], $trimArgs, [
            '-vf', $scale,
            '-c:v', 'libx264',
            '-preset', 'fast',
            '-crf', '22',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-movflags', '+faststart',
            '-pix_fmt', 'yuv420p',
            $output,
        ])) && file_exists($output) && filesize($output) > 0;
    }

    /**
     * @param  array{file?: string, width?: int, crf?: string, preset?: string, audio?: string}  $cfg
     */
    private function transcodeVariant(string $input, string $output, array $cfg, int $maxSeconds): bool
    {
        $maxWidth = (int) ($cfg['width'] ?? 720);
        $crf = (string) ($cfg['crf'] ?? '24');
        $preset = (string) ($cfg['preset'] ?? 'fast');
        $audio = (string) ($cfg['audio'] ?? '96k');
        $scale = "scale='min({$maxWidth},iw)':-2";

        return $this->runFfmpeg([
            '-y', '-i', $input,
            '-t', (string) $maxSeconds,
            '-vf', $scale,
            '-c:v', 'libx264',
            '-preset', $preset,
            '-crf', $crf,
            '-c:a', 'aac',
            '-b:a', $audio,
            '-movflags', '+faststart',
            '-pix_fmt', 'yuv420p',
            $output,
        ]) && file_exists($output) && filesize($output) > 0;
    }

    private function extractPosterWebp(string $input, string $output): bool
    {
        $jpg = $output.'.tmp.jpg';
        if (! $this->extractPosterJpg($input, $jpg)) {
            return false;
        }

        if (! extension_loaded('gd') || ! function_exists('imagewebp')) {
            return false;
        }

        $img = @imagecreatefromjpeg($jpg);
        @unlink($jpg);

        if ($img === false) {
            return false;
        }

        $ok = imagewebp($img, $output, 82);
        imagedestroy($img);

        return $ok && file_exists($output);
    }

    private function extractPosterJpg(string $input, string $output): bool
    {
        return $this->runFfmpeg([
            '-y', '-i', $input,
            '-ss', '00:00:00.5',
            '-vframes', '1',
            '-q:v', '2',
            $output,
        ]) && file_exists($output);
    }

    private function runFfmpeg(array $args): bool
    {
        $cmd = 'ffmpeg '.implode(' ', array_map('escapeshellarg', $args)).' 2>&1';
        exec($cmd, $out, $code);

        if ($code !== 0) {
            Log::debug('ffmpeg failed', ['code' => $code, 'tail' => array_slice($out, -8)]);
        }

        return $code === 0;
    }

    private function reportProgress(string $jobId, int $progress): void
    {
        $key = "video_upload:{$jobId}";
        $existing = Cache::get($key, []);

        if (! is_array($existing) || ($existing['status'] ?? '') === 'completed') {
            return;
        }

        Cache::put($key, array_merge($existing, [
            'status' => 'processing',
            'progress' => min(95, max(15, $progress)),
        ]), config('media.upload_cache_ttl', 3600));
    }

    private function normalizeLevel(?string $level): string
    {
        $level = trim((string) $level);

        return match (strtolower($level)) {
            'influencer' => 'Influencer',
            'creator' => 'Creator',
            'basic' => 'Basic',
            default => $level !== '' ? $level : 'Basic',
        };
    }
}

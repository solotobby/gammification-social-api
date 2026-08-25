<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PostVideo extends Model
{
    use HasFactory, SoftDeletes, UuidTrait;

    protected $fillable = [
        'id',
        'user_id',
        'post_id',
        'path',
        'thumbnail_path',
        'public_id',
        'duration',
        'width',
        'height',
        'format',
        'file_size',
        'processing_status',
        'quality_versions',
        'view_count',
        'play_count',
        'avg_watch_time',
        'total_watch_time',
        'watch_sessions',
        'hd_path',
        'failure_reason',
    ];

    protected $casts = [
        'quality_versions' => 'array',
        'duration' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'file_size' => 'integer',
        'view_count' => 'integer',
        'play_count' => 'integer',
        'avg_watch_time' => 'decimal:2',
        'total_watch_time' => 'decimal:2',
        'watch_sessions' => 'integer',
    ];

    /**
     * Get the post that owns the video.
     */
    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Get the user that uploaded the video.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get video URL for specific quality
     */
    public function getQualityUrl($quality = 'high')
    {
        if ($this->quality_versions && isset($this->quality_versions[$quality])) {
            return $this->quality_versions[$quality];
        }
        
        return $this->path; // Fallback to original
    }

    /**
     * Get adaptive video URL based on network strength
     */
    public function getAdaptiveUrl($networkStrength = 'medium')
    {
        $qualityMap = [
            'slow' => 'low',
            '2g' => 'low',
            '3g' => 'medium',
            'medium' => 'medium',
            '4g' => 'high',
            '5g' => 'high',
            'fast' => 'high',
        ];

        $quality = $qualityMap[$networkStrength] ?? 'medium';
        return $this->getQualityUrl($quality);
    }

    /**
     * Increment view count
     */
    public function incrementViews()
    {
        $this->increment('view_count');
    }

    /**
     * Increment play count
     */
    public function incrementPlays()
    {
        $this->increment('play_count');
    }

    /**
     * Record a watch segment and keep total + average watch time in sync.
     */
    public function updateWatchTime(float $watchTime): void
    {
        if ($watchTime < 0.25) {
            return;
        }

        $this->refresh();

        $sessions = max(0, (int) ($this->watch_sessions ?? 0));
        $total = (float) ($this->total_watch_time ?? 0) + $watchTime;
        $newSessions = $sessions + 1;
        $avg = $newSessions > 0 ? round($total / $newSessions, 2) : round($watchTime, 2);

        $this->update([
            'total_watch_time' => round($total, 2),
            'watch_sessions' => $newSessions,
            'avg_watch_time' => $avg,
        ]);
    }

    /**
     * Check if video processing is complete
     */
    public function isProcessed()
    {
        return $this->processing_status === 'completed';
    }

    /**
     * Get formatted duration
     */
    public function getFormattedDurationAttribute()
    {
        if (!$this->duration) {
            return '0:00';
        }

        $minutes = floor($this->duration / 60);
        $seconds = $this->duration % 60;

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * Get formatted file size
     */
    public function getFormattedFileSizeAttribute()
    {
        if (!$this->file_size) {
            return '0 MB';
        }

        $size = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }

        public function getAdaptiveUrlAttribute(): string
    {
        if (! $this->public_id) return $this->path;
 
        $cloud = config('cloudinary.cloud_url');   // e.g. "cloudinary://..."
        // Extract cloud name from the DSN
        preg_match('/cloudinary:\/\/[^:]+:[^@]+@([^\/]+)/', $cloud, $m);
        $cloudName = $m[1] ?? null;
 
        if (! $cloudName) return $this->path;
 
        return "https://res.cloudinary.com/{$cloudName}/video/upload/f_auto,q_auto/{$this->public_id}.mp4";
    }
 
    /**
     * Low-quality mobile URL (480p, q_auto:low).
     */
    public function getLowQualityUrlAttribute(): string
    {
        if (! $this->public_id) return $this->path;
 
        preg_match('/cloudinary:\/\/[^:]+:[^@]+@([^\/]+)/', config('cloudinary.cloud_url'), $m);
        $cloudName = $m[1] ?? null;
        if (! $cloudName) return $this->path;
 
        return "https://res.cloudinary.com/{$cloudName}/video/upload/f_auto,q_auto:low,w_480/{$this->public_id}.mp4";
    }
 
    /**
     * Poster frame URL — used as the video thumbnail in the feed.
     *
     * Priority:
     *   1. thumbnail column — stored at upload time (fastest, no Cloudinary round-trip)
     *   2. Built on-the-fly from public_id using Cloudinary image transformation
     *   3. Empty string — blade will show a placeholder
     */
    public function getPosterUrlAttribute(): string
    {
        // 1. Use stored thumbnail (set during uploadToCloudinary)
        if ($this->thumbnail_path) {
            return $this->thumbnail_path;
        }
 
        // 2. Build from public_id on the fly
        if (! $this->public_id) return '';
 
        $cloudName = config('cloudinary.cloud_name')
            ?? $this->resolveCloudName();
 
        if (! $cloudName) return '';
 
        return "https://res.cloudinary.com/{$cloudName}/video/upload/so_0,f_jpg,w_640,h_360,c_fill,q_auto/{$this->public_id}.jpg";
    }
 
    private function resolveCloudName(): ?string
    {
        preg_match('/cloudinary:\/\/[^:]+:[^@]+@([^\/\s]+)/', config('cloudinary.cloud_url', ''), $m);
        return $m[1] ?? null;
    }
}
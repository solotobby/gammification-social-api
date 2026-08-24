<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Blog extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['user_id', 'blog_category_id', 'title', 'slug', 'content', 'excerpt', 'cover_image', 'status', 'published_at', 'views', 'ext_views'];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    protected $appends = ['cover_url'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($blog) {
            $blog->id = (string) Str::uuid();
            $blog->slug = Str::slug($blog->title) . '-' . uniqid();
        });
    }

    public function blogCategory()
    {
        return $this->belongsTo(BlogCategory::class, 'blog_category_id');
    }

    /**
     * Normalized, display-ready cover image URL (or null if unusable).
     */
    public function getCoverUrlAttribute(): ?string
    {
        return static::normalizeImageUrl($this->cover_image);
    }

    public function hasCover(): bool
    {
        return filled($this->cover_url);
    }

    /**
     * Article HTML with broken-image safety hooks on <img> tags.
     */
    public function safeContentHtml(): string
    {
        $html = (string) ($this->content ?? '');
        if ($html === '') {
            return '';
        }

        // Upgrade plain http image URLs inside content.
        $html = preg_replace_callback(
            '/(<img\b[^>]*\bsrc=["\'])(http:\/\/[^"\']+)(["\'])/i',
            function (array $m) {
                $url = static::normalizeImageUrl($m[2]) ?? $m[2];
                $url = preg_replace('#^http://#i', 'https://', $url);

                return $m[1] . e($url) . $m[3];
            },
            $html
        ) ?? $html;

        // Ensure every content image can fail gracefully (no broken icon).
        $html = preg_replace(
            '/<img\b(?![^>]*\bonerror=)/i',
            '<img onerror="this.remove()" loading="lazy"',
            $html
        ) ?? $html;

        return $html;
    }

    public static function normalizeImageUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || strcasecmp($url, 'null') === 0 || strcasecmp($url, 'undefined') === 0) {
            return null;
        }

        // Protocol-relative → https
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        // Local / relative storage path
        if (! preg_match('#^(https?:)?//#i', $url) && ! str_starts_with($url, 'data:')) {
            return asset(ltrim($url, '/'));
        }

        // Force https for Cloudinary (and general http assets)
        if (str_starts_with($url, 'http://')) {
            $url = 'https://' . substr($url, 7);
        }

        // Reject obviously invalid URLs
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $url;
    }
}

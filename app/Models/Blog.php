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

    public function getCoverUrlAttribute(): ?string
    {
        return static::normalizeImageUrl($this->cover_image);
    }

    public static function normalizeImageUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || strcasecmp($url, 'null') === 0 || strcasecmp($url, 'undefined') === 0) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        if (! preg_match('#^(https?:)?//#i', $url) && ! str_starts_with($url, 'data:')) {
            return asset(ltrim($url, '/'));
        }

        if (str_starts_with($url, 'http://')) {
            $url = 'https://' . substr($url, 7);
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $url;
    }
}

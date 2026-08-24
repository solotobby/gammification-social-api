<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class HelpArticle extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [
        'help_category_id',
        'title',
        'slug',
        'body',
        'meta_title',
        'meta_description',
        'published',
        'sort_order',
        'published_at',
    ];

    protected $casts = [
        'published' => 'boolean',
        'published_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (HelpArticle $article) {
            if (empty($article->id)) {
                $article->id = (string) Str::uuid();
            }
            if (empty($article->slug)) {
                $article->slug = Str::slug($article->title).'-'.uniqid();
            }
            if ($article->published && empty($article->published_at)) {
                $article->published_at = now();
            }
        });

        static::updating(function (HelpArticle $article) {
            if ($article->published && empty($article->published_at)) {
                $article->published_at = now();
            }
        });
    }

    public function category()
    {
        return $this->belongsTo(HelpCategory::class, 'help_category_id');
    }

    public function seoTitle(): string
    {
        return $this->meta_title ?: $this->title.' · Help Center';
    }

    public function seoDescription(): string
    {
        if (filled($this->meta_description)) {
            return (string) $this->meta_description;
        }

        return Str::limit(strip_tags((string) $this->body), 155);
    }

    public function safeBodyHtml(): string
    {
        $html = (string) ($this->body ?? '');

        if ($html === '') {
            return '';
        }

        if ($html === strip_tags($html)) {
            return nl2br(e($html));
        }

        return $html;
    }

    public function scopePublished($query)
    {
        return $query->where('published', true);
    }
}

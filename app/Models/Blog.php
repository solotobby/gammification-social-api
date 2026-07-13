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

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($blog) {
            $blog->id = (string) Str::uuid();
            $blog->slug = Str::slug($blog->title) . '-' . uniqid();
        });
    }

    public function blogCategory(){
        return $this->belongsTo(BlogCategory::class, 'blog_category_id');
    }


}

<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class HelpCategory extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['name', 'slug', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (HelpCategory $category) {
            if (empty($category->id)) {
                $category->id = (string) Str::uuid();
            }
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    public function articles()
    {
        return $this->hasMany(HelpArticle::class, 'help_category_id');
    }

    public function publishedArticles()
    {
        return $this->articles()->published()->orderBy('sort_order')->orderBy('title');
    }
}

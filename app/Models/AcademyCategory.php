<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AcademyCategory extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['name', 'slug'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (AcademyCategory $category) {
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
        return $this->hasMany(AcademyArticle::class, 'academy_category_id');
    }
}

<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Hashtag extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['name', 'posts_count'];

    public function posts()
    {
        // return $this->belongsToMany(Post::class);
        return $this->belongsToMany(
            Post::class,
            'post_hashtag',   
            'hashtag_id',     
            'post_id'
        );
    }
}

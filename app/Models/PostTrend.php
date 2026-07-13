<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PostTrend extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [
        'post_id',
        'trend_id',
    ];

     public function post()
    {
        return $this->belongsTo(Post::class);
    }
    
     public function trend()
    {
        return $this->belongsTo(Trend::class);
    }
}

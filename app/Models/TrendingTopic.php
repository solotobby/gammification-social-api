<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrendingTopic extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [
        'phrase',
        'slug',
        'score',
        'timeframe',

    ];

    public function getRouteKeyName()
    {
        return 'slug';
    }

    public function getUrlAttribute()
    {
        return url('/search?q=' . urlencode($this->phrase));
    }
}

<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FremiumEngagementStat extends Model
{
    use HasFactory, UuidTrait;

    protected $table = 'fremium_engagement_stats';
    
    protected $fillable = [
        'user_id',
        'level',
        'date',
        'views',
        'likes',
        'comments',
        'points',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

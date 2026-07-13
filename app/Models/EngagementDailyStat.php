<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class EngagementDailyStat extends Model
{
    use HasFactory, UuidTrait;

    protected $table = 'engagement_daily_stats';

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

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->where('date', $date);
    }

    public function scopeForMonth(Builder $query, string $month): Builder
    {
        return $query->whereBetween(
            'date',
            [
                now()->createFromFormat('Y-m', $month)->startOfMonth(),
                now()->createFromFormat('Y-m', $month)->endOfMonth(),
            ]
        );
    }

    public function scopeForTier(Builder $query, string $tier): Builder
    {
        return $query->where('level', $tier);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function recalculatePoints(): void
    {
        $this->points = $this->views + $this->likes + $this->comments;
        $this->save();
    }
}

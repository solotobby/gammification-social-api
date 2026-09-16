<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PostBoost extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [
        'post_id',
        'user_id',
        'target_url',
        'cta',
        'total_clicks',
        'delivered_clicks',
        'remaining_clicks',
        'pk_cost',
        'rate_pk',
        'platform_payhankey',
        'platform_partner',
        'status',
        'ref',
    ];

    protected $casts = [
        'total_clicks' => 'integer',
        'delivered_clicks' => 'integer',
        'remaining_clicks' => 'integer',
        'pk_cost' => 'integer',
        'rate_pk' => 'integer',
        'platform_payhankey' => 'boolean',
        'platform_partner' => 'boolean',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(PostBoostClick::class, 'post_boost_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')->where('remaining_clicks', '>', 0);
    }

    public function scopeForPartner($query)
    {
        return $query->active()->where('platform_partner', true);
    }

    public function scopeForPayhankey($query)
    {
        return $query->active()->where('platform_payhankey', true);
    }
}

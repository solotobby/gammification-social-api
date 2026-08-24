<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommunitySubscription extends Model
{
    use HasFactory, UuidTrait;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'community_id',
        'user_id',
        'billing_type',
        'billing_interval',
        'fee_payer',
        'amount',
        'platform_fee',
        'creator_amount',
        'status',
        'starts_at',
        'expires_at',
        'cancelled_at',
        'gateway',
        'gateway_reference',
        'gateway_meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'creator_amount' => 'decimal:2',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'gateway_meta' => 'array',
    ];

    

    public function community()
    {
        return $this->belongsTo(Community::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isOneOff(): bool
    {
        return $this->billing_type === 'one_off';
    }

    public function isRecurring(): bool
    {
        return $this->billing_type === 'subscription';
    }

    /**
     * One-off purchases never expire (expires_at is always null for them),
     * so this is true forever once active. Subscriptions are active only
     * until their expires_at passes.
     */
    public function isActive(): bool
    {
        return $this->status === 'active'
            && (! $this->expires_at || $this->expires_at->isFuture());
    }
}

<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommunityPayout extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [
        'community_id',
        'community_subscription_id',
        'transaction_id',
        'payer_user_id',
        'gross_amount',
        'platform_fee',
        'creator_amount',
        'currency',
        'billing_type',
        'billing_interval',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'creator_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function community()
    {
        return $this->belongsTo(Community::class);
    }

    public function subscription()
    {
        return $this->belongsTo(CommunitySubscription::class, 'community_subscription_id');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'payer_user_id');
    }
}

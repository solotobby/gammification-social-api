<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityPaymentPlan extends Model
{
    use UuidTrait;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'community_id',
        'currency',
        'billing_interval',
        'amount',
        'flutterwave_plan_id',
        'flutterwave_plan_token',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }
}

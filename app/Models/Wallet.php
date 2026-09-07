<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [
        'user_id',
        'promoter_balance',
        'referral_balance',
        'paykoin_spendable',
        'paykoin_earned',
        'balance',
        'currency',
        'level',
        'usdt_wallet_address',
        'currency_updated_at',
    ];

    protected $casts = [
        'paykoin_spendable' => 'integer',
        'paykoin_earned' => 'integer',
        'currency_updated_at' => 'datetime',
    ];
}

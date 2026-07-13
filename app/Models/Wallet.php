<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['user_id', 'promoter_balance', 'referral_balance', 'balance', 'currency', 'level', 'usdt_wallet_address', 'currency_updated_at'];

    protected $casts = [
        'currency_updated_at'
    ];
}

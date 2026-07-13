<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WithdrawalMethod extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['user_id', 'country', 'currency', 'payment_method', 'bank_name', 'account_number', 'bank_code', 'account_name', 'recipient_code', 'paypal_email', 'usdt_wallet', 'is_active'];

    public function user(){
        return $this->belongsTo(User::class, 'user_id');
    }
}

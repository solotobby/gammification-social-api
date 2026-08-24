<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Withdrawals extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['user_id', 'withdrawal_method_id', 'amount', 'naira', 'currency', 'wallet_type', 'method', 'status'];

    public function user(){
        return $this->belongsTo(User::class);
    }

    public function withdrawalMethod(){
        return $this->belongsTo(WithdrawalMethod::class, 'withdrawal_method_id');
    }
}

<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserLevel extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['user_id', 'level_id', 'plan_name', 'plan_code', 'subscription_code', 'start_date', 'email_token', 'status', 'next_payment_date'];


    protected $casts = [
        'next_payment_date' => 'datetime',
    ];


    public function scopeActive($query)
    {
        return $query
            ->where('status', 'active')
            ->where('next_payment_date', '>', now());
    }

    public function user(){
        return $this->belongsTo(User::class, 'user_id');
    }

    public function wallet(){
        return $this->hasOne(Wallet::class, 'user_id', 'user_id');
    }
}

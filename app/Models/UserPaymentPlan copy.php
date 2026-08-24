<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserPaymentPlan extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [
        'user_id',
        'level_id',
        'name',
        'currency',
        'amount',
        'interval',
        'payment_gateway',
        'payment_plan_id',
        'payment_plan_token',
        'status',
    ];
}

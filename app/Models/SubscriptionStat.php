<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionStat extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['user_id', 'level_id', 'plan_name', 'amount', 'currency', 'start_date', 'end_date'];

    public function user(){
        return $this->belongsTo(User::class);
    }



}

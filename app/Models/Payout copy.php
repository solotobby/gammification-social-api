<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payout extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['user_id', 'engagement_monthly_stats_id', 'level', 'month', 'date', 'amount', 'total_engagement', 'currency', 'status', 'type'];

    public function user(){
        return $this->belongsTo(User::class, 'user_id');
    }


}

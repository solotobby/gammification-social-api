<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Level extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable =  ['name', 'amount', 'reg_bonus', 'ref_bonus', 'min_withdrawal', 'earning_per_view', 'earning_per_like', 'earning_per_comment'];

    public function planId(){
        return $this->hasOne(LevelPlanId::class, 'level_id');
    }
}

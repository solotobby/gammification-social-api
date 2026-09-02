<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Model;

class Level extends Model
{
    use UuidTrait;

    protected $fillable =  ['name', 'amount', 'reg_bonus', 'ref_bonus', 'min_withdrawal', 'earning_per_view', 'earning_per_like', 'earning_per_comment'];

    public function planIds()
    {
        return $this->hasMany(LevelPlanId::class, 'level_id');
    }
}

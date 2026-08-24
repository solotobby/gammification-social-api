<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LevelPlanId extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['level_id', 'level_name', 'provider', 'plan_code', 'amount', 'currency', 'status'];
}

<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [
        'country',
        'name',
        'code',
        'base_rate',
        'is_active',
    ];
}

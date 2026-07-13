<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminLogin extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['ip', 'country', 'city', 'code', 'status'];
}

<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Model;

class UserOTP extends Model
{
    use UuidTrait;

    protected $fillable = ['user_id', 'otp', 'expires_at'];
}

<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoginPoint extends Model
{
    use HasFactory, UuidTrait;

    protected $table = "login_points";

    protected $fillable = ['user_id', 'date', 'point', 'is_redeemed'];

    public function user(){
        return $this->belongsTo(User::class);
    }

}

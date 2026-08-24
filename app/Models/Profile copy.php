<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['user_id', 'about', 'date_of_birth', 'gender', 'location', 'username_updated_at'];

    protected $casts = [
    'username_updated_at' => 'datetime',
    ];

}

<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProfileViews extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['user_id', 'viewer_id'];

    
}

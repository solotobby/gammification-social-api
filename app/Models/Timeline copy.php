<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Timeline extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['user_id', 'content', 'views', 'clicks', 'likes_count', 'comment_count', 'status'];


}

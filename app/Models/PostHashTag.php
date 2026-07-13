<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PostHashTag extends Model
{
    use HasFactory, UuidTrait;

    protected $table = 'post_hashtag';

    protected $fillable = ['post_id', 'hashtag_id'];
    
}

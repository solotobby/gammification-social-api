<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommentExternalMessage extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['post_id', 'message'];
}

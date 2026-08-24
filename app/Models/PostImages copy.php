<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PostImages extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [ 'user_id', 'post_id', 'path', 'type'];


    public function post(){
        return $this->hasOne(Post::class);
    }

    public function user(){
        return $this->belongsTo(User::class);
    }

}

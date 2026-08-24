<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Follow extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['follower_id', 'following_id'];

    public function followers(){
        return $this->belongsTo(User::class, 'follower_id');
    }

    public function following(){
        return $this->belongsTo(User::class, 'following_id');
    }
}

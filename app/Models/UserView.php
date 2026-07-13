<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserView extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['user_id', 'post_id', 'is_paid', 'amount', 'poster_user_id', 'type'];

    public function user(){
        return $this->belongsTo(User::class);
    }

    public function post(){
        return $this->belongsTo(Post::class);
    }
    
}

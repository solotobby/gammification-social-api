<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [
        'user_id',
        'post_id',
        'parent_id',
        'message',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function parent()
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    public function replies()
    {
        return $this->hasMany(Comment::class, 'parent_id')->oldest('created_at');
    }

    public function isReply(): bool
    {
        return ! is_null($this->parent_id);
    }
}

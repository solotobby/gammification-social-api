<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommunityPostComment extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [
        'community_post_id',
        'user_id',
        'content',
        'parent_id',
    ];

    public function post()
    {
        return $this->belongsTo(CommunityPost::class, 'community_post_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function parent()
    {
        return $this->belongsTo(CommunityPostComment::class, 'parent_id');
    }

    public function replies()
    {
        return $this->hasMany(CommunityPostComment::class, 'parent_id')->oldest('created_at');
    }

    public function isReply(): bool
    {
        return ! is_null($this->parent_id);
    }
}

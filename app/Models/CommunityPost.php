<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommunityPost extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [
        'community_id',
        'user_id',
        'content',
    ];

    /**
     * views_count / likes_count / comments_count are cached counters —
     * kept in sync via increment()/decrement() in CommunityDetails rather
     * than recomputed with COUNT() subqueries on every feed render.
     */
    protected $casts = [
        'views_count' => 'integer',
        'likes_count' => 'integer',
        'comments_count' => 'integer',
    ];

    public function community()
    {
        return $this->belongsTo(Community::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function media()
    {
        return $this->hasMany(CommunityPostMedia::class)->orderBy('sort');
    }

    public function likes()
    {
        return $this->hasMany(CommunityPostLike::class);
    }

    public function isLikedBy(?string $userId): bool
    {
        if (! $userId) {
            return false;
        }

        return $this->likes()->where('user_id', $userId)->exists();
    }

    public function comments()
    {
        return $this->hasMany(CommunityPostComment::class)->latest();
    }

    public function views()
    {
        return $this->hasMany(CommunityPostView::class);
    }
}
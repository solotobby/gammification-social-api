<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [
        'user_id',
        'content',
        'views',
        'clicks',
        'likes',
        'likes_external',
        'views_external',
        'comments',
        'comment_external',
        'status',
        'unicode',
        'has_video',
        'has_images'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function images()
    {
        return $this->hasMany(PostImages::class);
    }

    public function video()
    {
        return $this->hasOne(PostVideo::class, 'post_id', 'id');
    }

    public function likes()
    {

        return $this->hasMany(UserLike::class);
    }

    public function unpaidLikes()
    {
        return $this->likes()->where('is_paid', false)->count();
    }

    public function isLikedBy(User $user)
    {
        // return $this->likes->contains('user_id', $user->id);
        return $this->likes()->where('user_id', $user->id)->exists();
    }

    public function userLikes()
    {
        return $this->belongsToMany(User::class, 'user_likes', 'post_id');
    }


    public function views()
    {

        return $this->hasMany(UserView::class);
    }

    public function unpaidViews()
    {
        return $this->views()->where('is_paid', false)->count();
    }

    public function userViews()
    {
        return $this->belongsToMany(User::class, 'user_views', 'post_id');
    }

    public function postComments()
    {
        return $this->hasMany(Comment::class)->orderBy('created_at', 'desc');
    }

    public function postCommentsExternal()
    {
        return $this->hasMany(CommentExternalMessage::class)->orderBy('created_at', 'desc');
    }

    public function comments()
    {
        return $this->hasMany(UserComment::class);
    }



    public function unpaidComments()
    {
        return $this->comments()->where('is_paid', false)->count();
    }

    public function externalComments()
    {
        return $this->hasMany(CommentExternal::class);
    }

    public function unpaidExternalComments()
    {
        return $this->externalComments()->where('is_paid', false)->count();
    }

    public function paidExternalComments()
    {
        return $this->externalComments()->where('is_paid', true)->count();
    }

    public function externalViews()
    {
        return $this->hasMany(ViewsExternal::class);
    }

    public function unpaidExternalViews()
    {
        return $this->externalViews()->where('is_paid', false)->count();
    }

    public function paidExternalViews()
    {
        return $this->externalViews()->where('is_paid', true)->count();
    }


    public function scopeVisibleToViewer($query, User $profileOwner, ?User $viewer)
    {
        if (
            $profileOwner->status === 'SHADOW_BANNED' &&
            (! $viewer || $viewer->id !== $profileOwner->id)
        ) {
            $query->where('status', 'LIVE');
        }

        return $query;
    }

    public function trends()
    {
        return $this->belongsToMany(Trend::class, 'post_trends', 'post_id', 'trend_id');
    }

    public function postTrends()
    {
        return $this->hasMany(PostTrend::class);
    }

    public function hashtags()
    {
        return $this->belongsToMany(
            Hashtag::class,
            'post_hashtag',   // 👈 pivot table name
            'post_id',        // 👈 foreign key on pivot table
            'hashtag_id'
        );
    }
}

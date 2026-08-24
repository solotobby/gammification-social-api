<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Traits\UuidTrait;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Testing\Fluent\Concerns\Has;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Contracts\Auth\MustVerifyEmail;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, UuidTrait;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'username',
        'phone',
        'email',
        'avatar',
        'banner',
        'referral_code',
        'password',
        'access_code_id',
        'level_id',
        'status'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'status' => 'string',
    ];

    public function profile(){
        return $this->hasOne(Profile::class, 'user_id');
    }

    public function withdrawalMethod(){
        return $this->hasOne(WithdrawalMethod::class, 'user_id');
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class, 'user_id');
    }

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function scopeByLevel($query, $level)
    {
        if ($level === null || $level === '' || $level === 'all') {
            return $query;
        }

        if ($level === 'Basic') {
            return $query->where(function ($q) {
                $q->whereDoesntHave('userLevel')
                    ->orWhereHas('userLevel', fn ($sub) => $sub->where('plan_name', 'Basic'));
            });
        }

        return $query->whereHas('userLevel', fn ($q) => $q->where('plan_name', $level));
    }

    public function userLevel()
    {
        return $this->hasOne(UserLevel::class, 'user_id');
    }

    public function activeLevel()
    {
        
       return $this->hasOne(UserLevel::class, 'user_id')
                ->where('status', 'active')
                ->where('next_payment_date', '>', Carbon::now());
    }

    public function social()
    {
        return $this->hasOne(Social::class, 'user_id');
    }

    public function scopeWithPostStatsByUsername(Builder $query, string $username)
    {
        return $query->where('username', $username)
            ->withCount([
                'posts as total_likes' => function ($query) {
                    $query->select(DB::raw('COALESCE(SUM(likes),0)'));
                },
                'posts as total_likes_external' => function ($query) {
                    $query->select(DB::raw('COALESCE(SUM(likes_external),0)'));
                },
                'posts as total_views_external' => function ($query) {
                    $query->select(DB::raw('COALESCE(SUM(views_external),0)'));
                },
                'posts as total_views' => function ($query) {
                    $query->select(DB::raw('COALESCE(SUM(views),0)'));
                },
                'posts as total_comments' => function ($query) {
                    $query->select(DB::raw('COUNT(comments)'));
                },
            ]);
    }


    // public function scopeWithPostStats(Builder $query, $userId)
    // {
    //     return $query->where('id', $userId)
    //         ->withCount(['posts as total_likes' => function ($query) {
    //             $query->select(DB::raw('sum(likes)'));
    //         }])
    //         ->withCount(['posts as total_likes_external' => function ($query) {

    //             $query->select(DB::raw('sum(likes_external)'));
    //         }])
    //         ->withCount(['posts as total_views_external' => function ($query) {

    //             $query->select(DB::raw('sum(views_external)'));
    //         }])
    //         ->withCount(['posts as total_views' => function ($query) {

    //             $query->select(DB::raw('sum(views)'));
    //         }])
    //         ->withCount(['posts as total_comments' => function ($query) {
    //             $query->select(DB::raw('count(comments)'));
    //         }]);
    // }



    public function getTotalLikesAttribute()
    {
        return $this->posts()->sum('likes');
    }

    public function getTotalViewsAttribute()
    {
        return $this->posts()->sum('views');
    }

    public function getTotalCommentsAttribute()
    {
        return $this->posts()->sum('comments');
    }

    //like methods 
    public function likes()
    {
        return $this->hasMany(UserLike::class);
    }

    public function like(Post $post)
    {
        return $this->likes()->create(['post_id' => $post->id]);
    }

    public function unlike(Post $post)
    {
        return $this->likes()->where('post_id', $post->id)->delete();
    }



    public function followingRelation()
    {
        return $this->hasMany(Follow::class, 'follower_id');
    }

    // Users that follow this user
    public function followersRelation()
    {
        return $this->hasMany(Follow::class, 'following_id');
    }



    public function following()
    {
        return $this->belongsToMany(
            User::class,
            'follows',
            'follower_id',
            'following_id',
            'id'
        )->withTimestamps();
    }

    // Users that follow me
    public function followers()
    {
        return $this->belongsToMany(
            User::class,
            'follows',
            'following_id',
            'follower_id',
            'id'
        )->withTimestamps();
    }

    public function isFollowing(User $user): bool
    {
        return $this->following()->where('following_id', $user->id)->exists();
    }

    public function bookmarkedPosts()
    {
        return $this->belongsToMany(
            Post::class,
            'post_bookmarks',
            'user_id',
            'post_id'
        )->withTimestamps();
    }


    // public function isFollowing($userId)
    // {
    //     return $this->followingRelation()->where('following_id', $userId)->exists();
    // }

    public function isFollowings($userId): bool
    {
        return $this->following()
            ->where('users.id', $userId)
            ->exists();
    }

}

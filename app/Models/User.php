<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Traits\UuidTrait;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, UuidTrait;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */

    protected $fillable = [
        'name',
        'username',
        'phone',
        'email',
        'avatar',
        'referral_code',
        'password',
        'access_code_id',
        'level_id',
        'status'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];


    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => 'string',
        ];
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class, 'user_id');
    }


    public function activeLevel()
    {

        return $this->hasOne(UserLevel::class, 'user_id')
            ->where('status', 'active')
            ->where('next_payment_date', '>', Carbon::now());
    }
    public function profile()
    {
        return $this->hasOne(Profile::class, 'user_id');
    }

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function social()
    {
        return $this->hasOne(Social::class, 'user_id');
    }

    public function bookmarks()
    {
        return $this->hasMany(PostBookmark::class);
    }

    public function bookmarkedPosts()
    {
        return $this->belongsToMany(Post::class, 'post_bookmarks', 'user_id', 'post_id')
            ->withTimestamps();
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

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        // Escape LIKE wildcard characters in user input so literal % or _ in a
        // search term doesn't get treated as a wildcard.
        $escaped = addcslashes($term, '%_\\');
        $like = '%' . $escaped . '%';

        return $query->where(function ($q) use ($like) {
            $q->where('name', 'like', $like)
                ->orWhere('username', 'like', $like)
                ->orWhere('email', 'like', $like);
        });
    }
}

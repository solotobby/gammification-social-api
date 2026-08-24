<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Model;

class CommunityPostView extends Model
{
    use UuidTrait;

    protected $fillable = [
        'community_post_id',
        'user_id',
        'ip_address',
    ];

    public function post()
    {
        return $this->belongsTo(CommunityPost::class, 'community_post_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostBoostClick extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [
        'post_boost_id',
        'post_id',
        'user_id',
        'platform',
        'ip',
        'country',
        'region',
        'city',
        'device',
        'browser',
        'os',
        'user_agent',
        'referrer',
    ];

    public function boost(): BelongsTo
    {
        return $this->belongsTo(PostBoost::class, 'post_boost_id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

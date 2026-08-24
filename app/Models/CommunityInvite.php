<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommunityInvite extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [
        'community_id',
        'invited_by',
        'user_id',
        'token',
        'type',
        'status',
        'expires_at',
        'accepted_at',
        'uses_count',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'uses_count' => 'integer',
    ];

    public function community()
    {
        return $this->belongsTo(Community::class);
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->status === 'pending' && ! $this->isExpired();
    }
}

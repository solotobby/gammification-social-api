<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminLogin extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [
        'ip',
        'country',
        'city',
        'code',
        'status',
        'expires_at',
        'used_at',
        'user_agent_hash',
    ];

    protected $casts = [
        'status' => 'boolean',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function isUsable(): bool
    {
        if (! $this->status || $this->used_at !== null) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}

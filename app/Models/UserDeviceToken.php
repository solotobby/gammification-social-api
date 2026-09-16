<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDeviceToken extends Model
{
    use HasFactory, UuidTrait;

    protected $table = 'user_device_tokens';

    protected $fillable = [
        'user_id',
        'token',
        'platform',
        'device_name',
        'ip_address',
        'location_type',
        'location',
        'is_logged_out',
        'is_active',
        'last_active_at',
    ];

    protected $casts = [
        'is_logged_out' => 'boolean',
        'is_active' => 'boolean',
        'last_active_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeLoggedIn($query)
    {
        return $query->where('is_logged_out', false);
    }
}

<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserOTP extends Model
{
    use UuidTrait;

    public const TYPE_VERIFICATION = 'verification';
    public const TYPE_PASSWORD_RESET = 'password_reset';

    protected $fillable = [
        'user_id',
        'otp',
        'type',
        'expires_at',
        'is_used',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_used' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeVerification(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_VERIFICATION);
    }

    public function scopePasswordReset(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_PASSWORD_RESET);
    }

    public function scopeValid(Builder $query, string $userId, string $otp, string $type = self::TYPE_VERIFICATION): Builder
    {
        return $query->where('user_id', $userId)
            ->where('otp', $otp)
            ->where('type', $type)
            ->where('is_used', false)
            ->where('expires_at', '>', now());
    }
}

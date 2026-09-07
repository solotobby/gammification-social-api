<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PostGift extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [
        'sender_id',
        'recipient_id',
        'giftable_type',
        'giftable_id',
        'artifact_id',
        'pk_amount',
        'ref',
        'meta',
    ];

    protected $casts = [
        'pk_amount' => 'integer',
        'meta' => 'array',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function giftable(): MorphTo
    {
        return $this->morphTo();
    }
}

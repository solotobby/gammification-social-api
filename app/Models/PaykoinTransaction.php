<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaykoinTransaction extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [
        'user_id',
        'type',
        'pk_amount',
        'fiat_amount',
        'currency',
        'ref',
        'description',
        'meta',
    ];

    protected $casts = [
        'pk_amount' => 'integer',
        'fiat_amount' => 'decimal:2',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

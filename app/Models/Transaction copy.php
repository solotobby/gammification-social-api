<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['user_id', 'ref', 'idempotency_key', 'provider', 'amount', 'currency', 'status', 'type', 'action', 'description', 'meta', 'customer'];

    protected $casts = [
        'meta' => 'array',
        'customer' => 'array',
    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

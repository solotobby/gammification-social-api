<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Webhook extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [
        'reference',
        'event',
        'content',
        'type', // individual or agent
        'name',
        'email',
        'level',
        'number_of_slot',
        'amount',
        'currency'
    ];
}

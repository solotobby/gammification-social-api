<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HashtagTrend extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['hashtag_id', 'score', 'time'];


}

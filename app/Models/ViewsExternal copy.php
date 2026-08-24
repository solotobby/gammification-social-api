<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ViewsExternal extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['post_id', 'ip', 'city', 'is_paid'];
}

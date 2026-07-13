<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PartnerSlot extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = ['partner_id', 'beginner', 'creator', 'influencer', 'status'];
}

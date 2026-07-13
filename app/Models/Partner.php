<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [ 'user_id', 'name', 'email', 'phone', 'identification', 'country', 'code', 'validation', 'status'];


    public function partnerSlot(){
        return $this->hasOne(PartnerSlot::class);
    }

    public function user(){
        return $this->belongsTo(User::class, 'user_id');
    }
}

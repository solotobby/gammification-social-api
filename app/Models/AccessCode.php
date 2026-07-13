<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Model;

class AccessCode extends Model
{
    use UuidTrait;

    protected $fillable = ['tx_id', 'name', 'email', 'code', 'amount', 'is_active', 'level_id', 'recepient_name', 'recepient_email'];

    public function level()
    {
        return $this->belongsTo(Level::class, 'level_id');
    }   

}

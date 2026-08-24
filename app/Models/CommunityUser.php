<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CommunityUser extends Pivot
{
    use UuidTrait;

    public $incrementing = false;

    protected $table = 'community_users';

    protected $keyType = 'string';

    protected $fillable = [
        'community_id',
        'user_id',
        'role',
        'status',
    ];
}

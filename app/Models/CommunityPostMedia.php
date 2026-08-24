<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class CommunityPostMedia extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [
        'community_post_id',
        'path',
        'type',
        'sort',
    ];

    public function post()
    {
        return $this->belongsTo(CommunityPost::class, 'community_post_id');
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('spaces')->url($this->path);
    }

    public function getIsVideoAttribute(): bool
    {
        return $this->type === 'video';
    }
}
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
        'processing_status',
        'thumbnail_path',
        'width',
        'height',
        'size_bytes',
        'failure_reason',
    ];

    public function post()
    {
        return $this->belongsTo(CommunityPost::class, 'community_post_id');
    }

    public function getUrlAttribute(): string
    {
        if ($this->processing_status !== 'completed') {
            return '';
        }

        if (str_starts_with((string) $this->path, 'http://') || str_starts_with((string) $this->path, 'https://')) {
            return $this->path;
        }

        return Storage::disk('spaces')->url($this->path);
    }

    public function getIsVideoAttribute(): bool
    {
        return $this->type === 'video';
    }
}
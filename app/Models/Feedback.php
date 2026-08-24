<?php

namespace App\Models;

use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Feedback extends Model
{
    use HasFactory, UuidTrait;

    protected $table = 'feedback';

    public const TYPES = [
        'complaint' => 'Complaint',
        'suggestion' => 'Suggestion',
        'improvement' => 'Improvement',
        'bug' => 'Bug report',
        'other' => 'Other',
    ];

    public const STATUSES = [
        'new' => 'New',
        'reviewed' => 'In review',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];

    protected $fillable = [
        'user_id',
        'type',
        'subject',
        'message',
        'status',
        'admin_note',
        'reviewed_by',
        'reviewed_at',
        'last_message_at',
        'last_message_by',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(FeedbackMessage::class)->orderBy('created_at');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst((string) $this->type);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, ['closed'], true);
    }

    public function addMessage(string $body, string $userId, bool $isStaff = false): FeedbackMessage
    {
        $message = $this->messages()->create([
            'user_id' => $userId,
            'body' => $body,
            'is_staff' => $isStaff,
        ]);

        $updates = [
            'last_message_at' => now(),
            'last_message_by' => $isStaff ? 'staff' : 'user',
        ];

        if ($isStaff) {
            $updates['reviewed_by'] = $userId;
            $updates['reviewed_at'] = now();
            if ($this->status === 'new') {
                $updates['status'] = 'reviewed';
            }
        } elseif ($this->status === 'resolved') {
            $updates['status'] = 'reviewed';
        }

        $this->update($updates);

        return $message;
    }
}

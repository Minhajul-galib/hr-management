<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationParticipant extends Model
{
    protected $fillable = [
        'conversation_id',
        'user_id',
        'role',
        'last_read_message_id',
        'last_read_at',
        'muted_until',
        'is_pinned',
        'joined_at',
        'left_at',
    ];

    protected $casts = [
        'last_read_at' => 'datetime',
        'muted_until' => 'datetime',
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'is_pinned' => 'boolean',
        'last_read_message_id' => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function hasLeft(): bool
    {
        return $this->left_at !== null;
    }

    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }
}

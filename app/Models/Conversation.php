<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Conversation extends Model
{
    use SoftDeletes;

    public const TYPE_DIRECT = 'direct';

    public const TYPE_GROUP = 'group';

    protected $fillable = [
        'type',
        'name',
        'avatar_path',
        'direct_key',
        'created_by',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /** Participants who have not left the thread. */
    public function activeParticipants(): HasMany
    {
        return $this->participants()->whereNull('left_at');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot(['role', 'last_read_message_id', 'last_read_at', 'muted_until', 'is_pinned', 'left_at'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isGroup(): bool
    {
        return $this->type === self::TYPE_GROUP;
    }

    public function isDirect(): bool
    {
        return $this->type === self::TYPE_DIRECT;
    }

    /** Threads the given user still belongs to. */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->whereHas('participants', function (Builder $q) use ($userId) {
            $q->where('user_id', $userId)->whereNull('left_at');
        });
    }

    public function participantFor(int $userId): ?ConversationParticipant
    {
        return $this->participants->firstWhere('user_id', $userId)
            ?? $this->participants()->where('user_id', $userId)->first();
    }

    public function hasActiveParticipant(int $userId): bool
    {
        return $this->participants()
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->exists();
    }

    public function isAdmin(int $userId): bool
    {
        return $this->participants()
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->where('role', 'admin')
            ->exists();
    }

    /**
     * Stable key for a 1:1 thread so the same pair can never open two threads.
     */
    public static function directKeyFor(int $userA, int $userB): string
    {
        $ids = [$userA, $userB];
        sort($ids);

        return implode(':', $ids);
    }

    public function broadcastChannel(): string
    {
        return 'clifto-talk.conversation.'.$this->id;
    }
}

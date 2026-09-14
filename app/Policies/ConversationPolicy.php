<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    /** Reading a thread requires current, non-departed membership. */
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->hasActiveParticipant($user->id);
    }

    public function send(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    /**
     * Group metadata (name, avatar) is admin-only. A 1:1 thread has no
     * settings to change, so nobody may update it.
     */
    public function update(User $user, Conversation $conversation): bool
    {
        return $conversation->isGroup() && $conversation->isAdmin($user->id);
    }

    /** Anyone in a group may pull colleagues in; 1:1 threads are fixed. */
    public function addParticipants(User $user, Conversation $conversation): bool
    {
        return $conversation->isGroup() && $this->view($user, $conversation);
    }

    /** You can always remove yourself; removing others is an admin action. */
    public function removeParticipant(User $user, Conversation $conversation, int $targetUserId): bool
    {
        if (! $conversation->isGroup() || ! $this->view($user, $conversation)) {
            return false;
        }

        return $user->id === $targetUserId || $conversation->isAdmin($user->id);
    }
}

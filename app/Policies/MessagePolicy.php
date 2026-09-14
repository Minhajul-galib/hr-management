<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

class MessagePolicy
{
    /** Reacting and replying only need thread membership. */
    public function view(User $user, Message $message): bool
    {
        return $message->conversation !== null
            && $message->conversation->hasActiveParticipant($user->id);
    }

    /** Editing is limited to your own text, within a configurable window. */
    public function update(User $user, Message $message): bool
    {
        $window = (int) config('clifto-talk.edit_window_minutes', 1440);

        return $message->user_id === $user->id
            && ! $message->isSystem()
            && $this->view($user, $message)
            && $message->created_at?->gt(now()->subMinutes($window));
    }

    /** Senders delete their own; group admins can clear anyone's. */
    public function delete(User $user, Message $message): bool
    {
        if ($message->isSystem() || ! $this->view($user, $message)) {
            return false;
        }

        if ($message->user_id === $user->id) {
            return true;
        }

        return $message->conversation->isGroup()
            && $message->conversation->isAdmin($user->id);
    }
}

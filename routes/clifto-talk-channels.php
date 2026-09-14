<?php

use App\Models\Conversation;
use App\Models\User;
use App\Services\CliftoTalk;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Clifto Talk broadcast channels
|--------------------------------------------------------------------------
| Channel names are intentionally fixed, independent of the URL prefix — they
| are a wire protocol shared with the JS client, not a route.
*/

// Personal channel: conversation list updates and the unread badge.
Broadcast::channel('clifto-talk.user.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id;
});

// Thread channel: messages, edits, reactions, read receipts and typing
// whispers. Only current members may listen, so leaving a group cuts you off.
Broadcast::channel('clifto-talk.conversation.{conversationId}', function (User $user, $conversationId) {
    $conversation = Conversation::find($conversationId);

    return $conversation !== null && $conversation->hasActiveParticipant($user->id);
});

// Presence channel backing the green online dots.
Broadcast::channel('clifto-talk.online', function (User $user) {
    if (! CliftoTalk::allows($user)) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
        'initials' => $user->initials,
    ];
});

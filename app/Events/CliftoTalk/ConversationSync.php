<?php

namespace App\Events\CliftoTalk;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Membership or metadata changed (thread created, renamed, member added or
 * removed). Carries no conversation body because that view is viewer-specific;
 * the client refetches the single thread instead.
 */
class ConversationSync implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  list<int>  $participantIds
     * @param  'created'|'updated'|'removed'  $action
     */
    public function __construct(
        public array $participantIds,
        public int $conversationId,
        public string $action = 'updated',
    ) {}

    public function broadcastOn(): array
    {
        return array_map(
            fn (int $userId) => new PrivateChannel('clifto-talk.user.'.$userId),
            $this->participantIds
        );
    }

    public function broadcastAs(): string
    {
        return 'conversation.sync';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'action' => $this->action,
        ];
    }
}

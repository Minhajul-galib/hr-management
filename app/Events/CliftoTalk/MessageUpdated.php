<?php

namespace App\Events\CliftoTalk;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Covers edits, deletions (tombstone) and reaction changes. */
class MessageUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $conversationId,
        public array $message,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('clifto-talk.conversation.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'message.updated';
    }

    public function broadcastWith(): array
    {
        return ['message' => $this->message];
    }
}

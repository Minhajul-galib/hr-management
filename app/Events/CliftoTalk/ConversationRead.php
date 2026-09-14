<?php

namespace App\Events\CliftoTalk;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Moves one participant's read watermark; drives the ✓✓ / "Seen by" row. */
class ConversationRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $conversationId,
        public int $userId,
        public ?int $lastReadMessageId,
        public string $readAt,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('clifto-talk.conversation.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'conversation.read';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'user_id' => $this->userId,
            'last_read_message_id' => $this->lastReadMessageId,
            'read_at' => $this->readAt,
        ];
    }
}

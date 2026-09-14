<?php

namespace App\Events\CliftoTalk;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new message in a thread. Broadcast to the conversation channel, which only
 * clients with that thread open are subscribed to. Everyone else learns about
 * it from {@see ConversationActivity} on their personal channel.
 *
 * Payloads are pre-serialised rather than carrying models, so the event stays
 * valid even if the record changes before the broadcast leaves the process.
 */
class MessageSent implements ShouldBroadcastNow
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
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return ['message' => $this->message];
    }
}

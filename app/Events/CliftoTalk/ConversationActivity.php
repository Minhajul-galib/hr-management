<?php

namespace App\Events\CliftoTalk;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fan-out to every participant's personal channel so the conversation list and
 * the unread badge stay live even for threads the client has not subscribed to.
 *
 * The payload is deliberately viewer-agnostic (one event, many channels): the
 * client skips its own unread bump by comparing `sender_id` to itself.
 */
class ConversationActivity implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  list<int>  $participantIds
     */
    public function __construct(
        public array $participantIds,
        public int $conversationId,
        public ?int $senderId,
        public array $message,
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
        return 'conversation.activity';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'sender_id' => $this->senderId,
            'message' => $this->message,
        ];
    }
}

<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Single source of truth for the JSON shape of Clifto Talk payloads.
 *
 * Both the HTTP controller and the broadcast events serialise through here, so
 * a message that arrives over the websocket is byte-identical to the same
 * message fetched over HTTP. Without that the client would need two code paths.
 */
class CliftoTalkPresenter
{
    /**
     * Viewer-agnostic message payload — safe to broadcast to a whole thread.
     */
    public static function message(Message $message): array
    {
        $message->loadMissing(['user:id,name', 'attachments', 'reactions', 'parent.user:id,name']);

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'type' => $message->type,
            'body' => $message->trashed() ? null : $message->body,
            'metadata' => $message->metadata,
            'is_deleted' => $message->trashed(),
            'edited_at' => optional($message->edited_at)->toIso8601String(),
            'created_at' => optional($message->created_at)->toIso8601String(),
            'user' => $message->user ? self::user($message->user) : null,
            'parent' => $message->parent ? self::messageQuote($message->parent) : null,
            'attachments' => $message->trashed()
                ? []
                : $message->attachments->map(fn (MessageAttachment $a) => self::attachment($a))->values()->all(),
            'reactions' => self::reactions($message),
        ];
    }

    /** Compact form of the message being replied to. */
    public static function messageQuote(Message $message): array
    {
        return [
            'id' => $message->id,
            'body' => $message->trashed() ? null : $message->body,
            'type' => $message->type,
            'is_deleted' => $message->trashed(),
            'user_name' => $message->user?->name,
            'has_attachments' => ! $message->trashed() && $message->attachments()->exists(),
        ];
    }

    /**
     * Grouped by emoji so the client can render "👍 3" without post-processing.
     * `user_ids` lets it decide whether the viewer's own reaction is active.
     */
    public static function reactions(Message $message): array
    {
        return $message->reactions
            ->groupBy('emoji')
            ->map(fn ($group, $emoji) => [
                'emoji' => $emoji,
                'count' => $group->count(),
                'user_ids' => $group->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all(),
            ])
            ->values()
            ->all();
    }

    public static function attachment(MessageAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->size,
            'is_image' => $attachment->isImage(),
            'width' => $attachment->width,
            'height' => $attachment->height,
            'url' => route(CliftoTalk::routeName('attachments.show'), $attachment->id),
            'download_url' => route(CliftoTalk::routeName('attachments.show'), ['attachment' => $attachment->id, 'download' => 1]),
        ];
    }

    public static function user(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'initials' => $user->initials,
            'email' => $user->email,
            // Optional in a host app whose User model has no such relation.
            'department' => CliftoTalk::subtitleFor($user),
        ];
    }

    /**
     * Conversation as seen by one particular viewer: the title of a 1:1 thread
     * is the *other* person, and unread counts differ per participant.
     */
    public static function conversation(Conversation $conversation, int $viewerId): array
    {
        $conversation->loadMissing(['participants.user:id,name,email', 'latestMessage.user:id,name', 'latestMessage.attachments']);

        $me = $conversation->participants->firstWhere('user_id', $viewerId);
        $others = $conversation->participants
            ->where('user_id', '!=', $viewerId)
            ->whereNull('left_at')
            ->filter(fn (ConversationParticipant $p) => $p->user !== null);

        $counterpart = $conversation->isDirect() ? $others->first()?->user : null;

        return [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'title' => $conversation->isGroup()
                ? ($conversation->name ?: 'Untitled group')
                : ($counterpart?->name ?? 'Unknown user'),
            'avatar_url' => $conversation->avatar_path
                ? Storage::disk(CliftoTalk::avatarDisk())->url($conversation->avatar_path)
                : null,
            'initials' => $conversation->isGroup()
                ? self::groupInitials($conversation->name)
                : ($counterpart?->initials ?? '?'),
            'counterpart_id' => $counterpart?->id,
            'is_group' => $conversation->isGroup(),
            'created_by' => $conversation->created_by,
            'last_message_at' => optional($conversation->last_message_at)->toIso8601String(),
            'last_message' => $conversation->latestMessage
                ? self::messagePreview($conversation->latestMessage)
                : null,
            'unread_count' => self::unreadCountFor($conversation, $viewerId),
            'is_pinned' => (bool) ($me?->is_pinned),
            'is_muted' => (bool) ($me?->isMuted()),
            'muted_until' => optional($me?->muted_until)->toIso8601String(),
            'my_role' => $me?->role ?? 'member',
            'last_read_message_id' => $me?->last_read_message_id,
            'participants' => $conversation->participants
                ->whereNull('left_at')
                ->filter(fn (ConversationParticipant $p) => $p->user !== null)
                ->map(fn (ConversationParticipant $p) => [
                    ...self::user($p->user),
                    'role' => $p->role,
                    'last_read_message_id' => $p->last_read_message_id,
                ])
                ->values()
                ->all(),
        ];
    }

    /** One-line summary shown in the conversation list. */
    public static function messagePreview(Message $message): array
    {
        $text = match (true) {
            $message->trashed() => 'This message was deleted',
            filled($message->body) => $message->body,
            $message->attachments->isNotEmpty() => $message->attachments->first()->isImage()
                ? '📷 Photo'
                : '📎 '.$message->attachments->first()->original_name,
            default => '',
        };

        return [
            'id' => $message->id,
            'type' => $message->type,
            'text' => $text,
            'user_id' => $message->user_id,
            'user_name' => $message->user?->name,
            'created_at' => optional($message->created_at)->toIso8601String(),
        ];
    }

    public static function unreadCountFor(Conversation $conversation, int $viewerId): int
    {
        $me = $conversation->participants->firstWhere('user_id', $viewerId);

        if (! $me || $me->left_at !== null) {
            return 0;
        }

        return $conversation->messages()
            ->where('id', '>', $me->last_read_message_id ?? 0)
            ->where(function ($q) use ($viewerId) {
                $q->whereNull('user_id')->orWhere('user_id', '!=', $viewerId);
            })
            ->count();
    }

    private static function groupInitials(?string $name): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim((string) $name)) ?: []));

        if ($parts === []) {
            return '#';
        }

        return mb_strtoupper(mb_substr($parts[0], 0, 1).(count($parts) > 1 ? mb_substr($parts[1], 0, 1) : ''));
    }
}

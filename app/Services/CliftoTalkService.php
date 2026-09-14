<?php

namespace App\Services;

use App\Events\CliftoTalk\ConversationActivity;
use App\Events\CliftoTalk\ConversationRead;
use App\Events\CliftoTalk\ConversationSync;
use App\Events\CliftoTalk\MessageSent;
use App\Events\CliftoTalk\MessageUpdated;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * All writes for Clifto Talk. Keeping them here means the broadcast that
 * follows a write can never be forgotten by a caller.
 */
class CliftoTalkService
{
    /**
     * Get the 1:1 thread between two people, creating it on first contact.
     *
     * The unique `direct_key` is what actually prevents duplicates: two
     * simultaneous "message Rakib" clicks race, one insert loses, and we read
     * the winner's row back instead of ending up with two threads.
     */
    public function findOrCreateDirect(User $user, User $other): Conversation
    {
        $key = Conversation::directKeyFor($user->id, $other->id);

        if ($existing = Conversation::where('direct_key', $key)->first()) {
            $this->restoreParticipants($existing, [$user->id, $other->id]);

            return $existing;
        }

        try {
            $conversation = DB::transaction(function () use ($user, $other, $key) {
                $conversation = Conversation::create([
                    'type' => Conversation::TYPE_DIRECT,
                    'direct_key' => $key,
                    'created_by' => $user->id,
                ]);

                $this->attachParticipants($conversation, [$user->id, $other->id], 'member');

                return $conversation;
            });
        } catch (QueryException $e) {
            // Lost the race on the unique index — the other request created it.
            $conversation = Conversation::where('direct_key', $key)->first();

            if (! $conversation) {
                throw $e;
            }

            return $conversation;
        }

        ConversationSync::dispatch([$user->id, $other->id], $conversation->id, 'created');

        return $conversation;
    }

    /**
     * @param  list<int>  $memberIds
     */
    public function createGroup(User $creator, string $name, array $memberIds): Conversation
    {
        $memberIds = collect($memberIds)->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === $creator->id)
            ->unique()
            ->values();

        $conversation = DB::transaction(function () use ($creator, $name, $memberIds) {
            $conversation = Conversation::create([
                'type' => Conversation::TYPE_GROUP,
                'name' => $name,
                'created_by' => $creator->id,
            ]);

            $this->attachParticipants($conversation, [$creator->id], 'admin');
            $this->attachParticipants($conversation, $memberIds->all(), 'member');

            return $conversation;
        });

        $this->postSystemMessage($conversation, $creator, 'group.created', [
            'actor' => $creator->name,
            'name' => $name,
        ]);

        ConversationSync::dispatch(
            $conversation->activeParticipants()->pluck('user_id')->map(fn ($id) => (int) $id)->all(),
            $conversation->id,
            'created'
        );

        return $conversation;
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function sendMessage(
        Conversation $conversation,
        User $sender,
        ?string $body,
        ?int $parentId = null,
        array $files = [],
    ): Message {
        $message = DB::transaction(function () use ($conversation, $sender, $body, $parentId, $files) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'user_id' => $sender->id,
                'parent_id' => $this->resolveParentId($conversation, $parentId),
                'type' => $files !== [] ? Message::TYPE_ATTACHMENT : Message::TYPE_TEXT,
                'body' => $body !== null && trim($body) !== '' ? trim($body) : null,
            ]);

            foreach ($files as $file) {
                $this->storeAttachment($conversation, $message, $file);
            }

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            // The sender has by definition read their own message.
            ConversationParticipant::where('conversation_id', $conversation->id)
                ->where('user_id', $sender->id)
                ->update(['last_read_message_id' => $message->id, 'last_read_at' => now()]);

            return $message;
        });

        $this->broadcastNewMessage($conversation, $message, $sender->id);

        return $message;
    }

    /** Announcements like "Rakib added Karim" — rendered centred, not as a bubble. */
    public function postSystemMessage(Conversation $conversation, ?User $actor, string $action, array $metadata = []): Message
    {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => null,
            'type' => Message::TYPE_SYSTEM,
            'body' => null,
            'metadata' => ['action' => $action] + $metadata,
        ]);

        $conversation->forceFill(['last_message_at' => $message->created_at])->save();

        $this->broadcastNewMessage($conversation, $message, $actor?->id);

        return $message;
    }

    public function editMessage(Message $message, string $body): Message
    {
        $message->update([
            'body' => trim($body),
            'edited_at' => now(),
        ]);

        $this->broadcastMessageChange($message);

        return $message->fresh();
    }

    public function deleteMessage(Message $message): void
    {
        // Soft delete so replies quoting it keep their thread context; the
        // client renders a "message was deleted" tombstone.
        $message->delete();

        $message->refresh();

        $this->broadcastMessageChange($message);
    }

    public function toggleReaction(Message $message, User $user, string $emoji): Message
    {
        $existing = MessageReaction::where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->where('emoji', $emoji)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            MessageReaction::create([
                'message_id' => $message->id,
                'user_id' => $user->id,
                'emoji' => $emoji,
            ]);
        }

        $message->load('reactions');

        $this->broadcastMessageChange($message);

        return $message;
    }

    /**
     * Total unread messages across every thread the user is still in — the
     * number on the top-bar badge. One aggregate query rather than one per
     * thread, because this runs on every page load.
     */
    public function unreadTotalFor(User $user): int
    {
        return (int) Message::query()
            ->join('conversation_participants as cp', function ($join) use ($user) {
                $join->on('cp.conversation_id', '=', 'messages.conversation_id')
                    ->where('cp.user_id', '=', $user->id)
                    ->whereNull('cp.left_at');
            })
            ->whereNull('messages.deleted_at')
            ->whereRaw('messages.id > coalesce(cp.last_read_message_id, 0)')
            ->where(function ($q) use ($user) {
                $q->whereNull('messages.user_id')->orWhere('messages.user_id', '!=', $user->id);
            })
            ->count();
    }

    /** Advance a participant's read watermark; never moves it backwards. */
    public function markRead(Conversation $conversation, User $user, ?int $upToMessageId = null): ?int
    {
        $participant = $conversation->participants()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();

        if (! $participant) {
            return null;
        }

        $target = $upToMessageId
            ?? $conversation->messages()->max('id');

        if ($target === null) {
            return null;
        }

        $target = max((int) $target, (int) $participant->last_read_message_id);

        $participant->update([
            'last_read_message_id' => $target,
            'last_read_at' => now(),
        ]);

        ConversationRead::dispatch($conversation->id, $user->id, $target, now()->toIso8601String());

        return $target;
    }

    /**
     * @param  list<int>  $userIds
     */
    public function addParticipants(Conversation $conversation, User $actor, array $userIds): Collection
    {
        $existing = $conversation->participants()
            ->whereNull('left_at')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id);

        $toAdd = collect($userIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn (int $id) => $existing->contains($id))
            ->values();

        if ($toAdd->isEmpty()) {
            return collect();
        }

        $added = User::whereIn('id', $toAdd)->get();

        $this->attachParticipants($conversation, $added->pluck('id')->all(), 'member');

        $this->postSystemMessage($conversation, $actor, 'participants.added', [
            'actor' => $actor->name,
            'names' => $added->pluck('name')->values()->all(),
        ]);

        ConversationSync::dispatch(
            $conversation->activeParticipants()->pluck('user_id')->map(fn ($id) => (int) $id)->all(),
            $conversation->id,
            'updated'
        );

        return $added;
    }

    public function removeParticipant(Conversation $conversation, User $actor, User $target): void
    {
        $participant = $conversation->participants()
            ->where('user_id', $target->id)
            ->whereNull('left_at')
            ->first();

        if (! $participant) {
            return;
        }

        $notifyIds = $conversation->activeParticipants()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $participant->update(['left_at' => now()]);

        $selfRemoval = $actor->id === $target->id;

        $this->postSystemMessage($conversation, $actor, $selfRemoval ? 'participant.left' : 'participant.removed', [
            'actor' => $actor->name,
            'target' => $target->name,
        ]);

        // A group can't be left leaderless — promote the longest-standing member.
        $this->ensureGroupHasAdmin($conversation);

        ConversationSync::dispatch($notifyIds, $conversation->id, 'updated');
    }

    public function renameGroup(Conversation $conversation, User $actor, string $name): Conversation
    {
        $previous = $conversation->name;
        $conversation->update(['name' => $name]);

        if ($previous !== $name) {
            $this->postSystemMessage($conversation, $actor, 'group.renamed', [
                'actor' => $actor->name,
                'from' => $previous,
                'to' => $name,
            ]);
        }

        ConversationSync::dispatch(
            $conversation->activeParticipants()->pluck('user_id')->map(fn ($id) => (int) $id)->all(),
            $conversation->id,
            'updated'
        );

        return $conversation;
    }

    public function setParticipantRole(Conversation $conversation, User $actor, User $target, string $role): void
    {
        $conversation->participants()
            ->where('user_id', $target->id)
            ->whereNull('left_at')
            ->update(['role' => $role]);

        $this->postSystemMessage($conversation, $actor, $role === 'admin' ? 'participant.promoted' : 'participant.demoted', [
            'actor' => $actor->name,
            'target' => $target->name,
        ]);

        ConversationSync::dispatch(
            $conversation->activeParticipants()->pluck('user_id')->map(fn ($id) => (int) $id)->all(),
            $conversation->id,
            'updated'
        );
    }

    // ----- internals -----

    /**
     * @param  list<int>  $userIds
     */
    private function attachParticipants(Conversation $conversation, array $userIds, string $role): void
    {
        foreach ($userIds as $userId) {
            ConversationParticipant::updateOrCreate(
                ['conversation_id' => $conversation->id, 'user_id' => (int) $userId],
                ['role' => $role, 'joined_at' => now(), 'left_at' => null],
            );
        }
    }

    /** Re-opening a 1:1 thread someone previously hid should bring them back. */
    private function restoreParticipants(Conversation $conversation, array $userIds): void
    {
        $conversation->participants()
            ->whereIn('user_id', $userIds)
            ->whereNotNull('left_at')
            ->update(['left_at' => null, 'joined_at' => now()]);
    }

    /** Replies may only point at messages inside the same thread. */
    private function resolveParentId(Conversation $conversation, ?int $parentId): ?int
    {
        if (! $parentId) {
            return null;
        }

        $belongs = Message::where('id', $parentId)
            ->where('conversation_id', $conversation->id)
            ->exists();

        return $belongs ? $parentId : null;
    }

    private function storeAttachment(Conversation $conversation, Message $message, UploadedFile $file): void
    {
        $disk = CliftoTalk::attachmentDisk();
        $root = config('clifto-talk.attachments.path', 'clifto-talk');

        $extension = $file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin';
        $path = $file->storeAs(
            "{$root}/{$conversation->id}",
            Str::uuid()->toString().'.'.$extension,
            $disk
        );

        [$width, $height] = $this->imageDimensions($file);

        $message->attachments()->create([
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize() ?: 0,
            'width' => $width,
            'height' => $height,
        ]);
    }

    /** @return array{0: ?int, 1: ?int} */
    private function imageDimensions(UploadedFile $file): array
    {
        if (! str_starts_with((string) $file->getClientMimeType(), 'image/')) {
            return [null, null];
        }

        $size = @getimagesize($file->getRealPath() ?: '');

        return $size ? [$size[0], $size[1]] : [null, null];
    }

    private function broadcastNewMessage(Conversation $conversation, Message $message, ?int $senderId): void
    {
        $payload = CliftoTalkPresenter::message($message->fresh());
        $participantIds = $conversation->activeParticipants()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        MessageSent::dispatch($conversation->id, $payload);
        ConversationActivity::dispatch($participantIds, $conversation->id, $senderId, $payload);
    }

    private function broadcastMessageChange(Message $message): void
    {
        MessageUpdated::dispatch(
            $message->conversation_id,
            CliftoTalkPresenter::message($message)
        );
    }

    private function ensureGroupHasAdmin(Conversation $conversation): void
    {
        if (! $conversation->isGroup()) {
            return;
        }

        if ($conversation->activeParticipants()->where('role', 'admin')->exists()) {
            return;
        }

        $successor = $conversation->activeParticipants()
            ->orderBy('joined_at')
            ->orderBy('id')
            ->first();

        $successor?->update(['role' => 'admin']);
    }
}

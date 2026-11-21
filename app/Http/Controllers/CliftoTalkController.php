<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\CliftoTalk;
use App\Services\CliftoTalkPresenter;
use App\Services\CliftoTalkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CliftoTalkController extends Controller
{
    public function __construct(private readonly CliftoTalkService $talk) {}

    /** Messages per page when scrolling back through history. */
    private function pageSize(): int
    {
        return (int) config('clifto-talk.page_size', 30);
    }

    // ----- pages -----

    public function index(Request $request): Response
    {
        return Inertia::render('CliftoTalk/Index', [
            'initialConversations' => $this->conversationsFor($request->user()),
            'openConversationId' => $request->integer('conversation') ?: null,
        ]);
    }

    // ----- conversations -----

    public function conversations(Request $request): JsonResponse
    {
        return response()->json([
            'conversations' => $this->conversationsFor($request->user()),
        ]);
    }

    public function showConversation(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        return response()->json([
            'conversation' => CliftoTalkPresenter::conversation($conversation, $request->user()->id),
        ]);
    }

    public function storeConversation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in([Conversation::TYPE_DIRECT, Conversation::TYPE_GROUP])],
            'user_id' => ['required_if:type,direct', 'integer', 'exists:users,id'],
            'name' => ['required_if:type,group', 'nullable', 'string', 'max:120'],
            'user_ids' => ['required_if:type,group', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $user = $request->user();

        if ($data['type'] === Conversation::TYPE_DIRECT) {
            $other = User::findOrFail($data['user_id']);

            if ($other->id === $user->id) {
                return response()->json(['message' => 'You cannot start a conversation with yourself.'], 422);
            }

            $conversation = $this->talk->findOrCreateDirect($user, $other);
        } else {
            $conversation = $this->talk->createGroup($user, $data['name'], $data['user_ids']);
        }

        return response()->json([
            'conversation' => CliftoTalkPresenter::conversation($conversation->fresh(), $user->id),
        ], 201);
    }

    public function updateConversation(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ]);

        if ($request->hasFile('avatar')) {
            $disk = CliftoTalk::avatarDisk();

            if ($conversation->avatar_path) {
                Storage::disk($disk)->delete($conversation->avatar_path);
            }

            $conversation->update([
                'avatar_path' => $request->file('avatar')->store(
                    config('clifto-talk.attachments.path', 'clifto-talk').'/avatars',
                    $disk
                ),
            ]);
        }

        $this->talk->renameGroup($conversation, $request->user(), $data['name']);

        return response()->json([
            'conversation' => CliftoTalkPresenter::conversation($conversation->fresh(), $request->user()->id),
        ]);
    }

    /** Mute / pin are per-participant preferences, not thread settings. */
    public function updatePreferences(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $data = $request->validate([
            'is_pinned' => ['sometimes', 'boolean'],
            'muted' => ['sometimes', 'boolean'],
        ]);

        $participant = $conversation->participants()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $changes = [];

        if (array_key_exists('is_pinned', $data)) {
            $changes['is_pinned'] = $data['is_pinned'];
        }

        if (array_key_exists('muted', $data)) {
            // "Muted" has no UI for a duration yet, so treat it as indefinite.
            $changes['muted_until'] = $data['muted'] ? now()->addYears(10) : null;
        }

        $participant->update($changes);

        return response()->json([
            'conversation' => CliftoTalkPresenter::conversation($conversation->fresh(), $request->user()->id),
        ]);
    }

    // ----- messages -----

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $before = $request->integer('before') ?: null;

        $pageSize = $this->pageSize();

        $query = $conversation->messages()
            ->withTrashed()
            ->with(['user:id,name', 'attachments', 'reactions', 'parent.user:id,name'])
            ->orderByDesc('id')
            ->limit($pageSize + 1);

        if ($before) {
            $query->where('id', '<', $before);
        }

        $rows = $query->get();
        $hasMore = $rows->count() > $pageSize;

        $messages = $rows->take($pageSize)
            ->sortBy('id')
            ->values()
            ->map(fn (Message $m) => CliftoTalkPresenter::message($m))
            ->all();

        return response()->json([
            'messages' => $messages,
            'has_more' => $hasMore,
        ]);
    }

    public function storeMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('send', $conversation);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer', 'exists:messages,id'],
            'attachments' => ['nullable', 'array', 'max:'.config('clifto-talk.attachments.max_per_message', 10)],
            'attachments.*' => ['file', 'max:'.config('clifto-talk.attachments.max_kb', 20480)],
        ]);

        $files = $request->file('attachments', []);

        if (blank($data['body'] ?? null) && $files === []) {
            return response()->json(['message' => 'Write something or attach a file.'], 422);
        }

        $message = $this->talk->sendMessage(
            $conversation,
            $request->user(),
            $data['body'] ?? null,
            $data['parent_id'] ?? null,
            $files,
        );

        return response()->json([
            'message' => CliftoTalkPresenter::message($message->fresh()),
        ], 201);
    }

    public function updateMessage(Request $request, Message $message): JsonResponse
    {
        $this->authorize('update', $message);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $updated = $this->talk->editMessage($message, $data['body']);

        return response()->json([
            'message' => CliftoTalkPresenter::message($updated),
        ]);
    }

    public function destroyMessage(Request $request, Message $message): JsonResponse
    {
        $this->authorize('delete', $message);

        $this->talk->deleteMessage($message);

        return response()->json([
            'message' => CliftoTalkPresenter::message($message->fresh()),
        ]);
    }

    public function toggleReaction(Request $request, Message $message): JsonResponse
    {
        $this->authorize('view', $message);

        $data = $request->validate([
            // Short enough to reject pasted text, long enough for emoji that
            // are several codepoints (skin tones, ZWJ sequences).
            'emoji' => ['required', 'string', 'max:16'],
        ]);

        $updated = $this->talk->toggleReaction($message, $request->user(), $data['emoji']);

        return response()->json([
            'message' => CliftoTalkPresenter::message($updated),
        ]);
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $data = $request->validate([
            'message_id' => ['nullable', 'integer'],
        ]);

        $lastRead = $this->talk->markRead($conversation, $request->user(), $data['message_id'] ?? null);

        return response()->json([
            'last_read_message_id' => $lastRead,
            'unread_total' => $this->unreadTotalFor($request->user()),
        ]);
    }

    // ----- participants -----

    public function addParticipants(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('addParticipants', $conversation);

        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $this->talk->addParticipants($conversation, $request->user(), $data['user_ids']);

        return response()->json([
            'conversation' => CliftoTalkPresenter::conversation($conversation->fresh(), $request->user()->id),
        ]);
    }

    public function removeParticipant(Request $request, Conversation $conversation, User $user): JsonResponse
    {
        $this->authorize('removeParticipant', [$conversation, $user->id]);

        $this->talk->removeParticipant($conversation, $request->user(), $user);

        $left = $request->user()->id === $user->id;

        return response()->json([
            'left' => $left,
            'conversation' => $left
                ? null
                : CliftoTalkPresenter::conversation($conversation->fresh(), $request->user()->id),
        ]);
    }

    public function updateParticipantRole(Request $request, Conversation $conversation, User $user): JsonResponse
    {
        $this->authorize('update', $conversation);

        $data = $request->validate([
            'role' => ['required', Rule::in(['admin', 'member'])],
        ]);

        $this->talk->setParticipantRole($conversation, $request->user(), $user, $data['role']);

        return response()->json([
            'conversation' => CliftoTalkPresenter::conversation($conversation->fresh(), $request->user()->id),
        ]);
    }

    // ----- lookups -----

    /** Directory used by "New chat" and "Add members". */
    public function users(Request $request): JsonResponse
    {
        $search = trim((string) $request->string('q'));

        $subtitleRelation = config('clifto-talk.directory.subtitle_relation');

        $users = User::query()
            ->whereKeyNot($request->user()->id)
            ->when(
                $subtitleRelation && method_exists(User::class, $subtitleRelation),
                fn ($q) => $q->with($subtitleRelation)
            )
            ->when($search !== '', fn ($q) => CliftoTalk::applySearch(
                $q,
                $search,
                (array) config('clifto-talk.directory.search_columns', ['name', 'email'])
            ))
            ->orderBy('name')
            ->limit((int) config('clifto-talk.directory.limit', 30))
            ->get()
            ->map(fn (User $u) => CliftoTalkPresenter::user($u));

        return response()->json(['users' => $users]);
    }

    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->string('q'));

        if (mb_strlen($term) < 2) {
            return response()->json(['results' => []]);
        }

        $userId = $request->user()->id;

        $results = Message::query()
            ->whereNotNull('body')
            ->where('body', CliftoTalk::likeOperator(), CliftoTalk::likeTerm($term))
            ->whereHas('conversation', fn ($q) => $q->forUser($userId))
            ->with(['user:id,name', 'conversation.participants.user:id,name'])
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->map(fn (Message $m) => [
                'message_id' => $m->id,
                'conversation_id' => $m->conversation_id,
                'conversation_title' => CliftoTalkPresenter::conversation($m->conversation, $userId)['title'],
                'body' => $m->body,
                'user_name' => $m->user?->name,
                'created_at' => optional($m->created_at)->toIso8601String(),
            ]);

        return response()->json(['results' => $results]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread_total' => $this->unreadTotalFor($request->user())]);
    }

    // ----- attachments -----

    public function showAttachment(Request $request, MessageAttachment $attachment): StreamedResponse
    {
        $message = $attachment->message;

        abort_if($message === null, 404);
        $this->authorize('view', $message);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        // Uploads accept any file type (see storeMessage's validation), so a
        // member could attach an .html/.svg file that a browser would happily
        // execute as a document if it were ever handed back inline with its
        // own claimed type. Only a small allowlist of formats that browsers
        // cannot execute as active content is ever rendered inline; anything
        // else is always forced to download, and its Content-Type is not
        // trusted verbatim, so a spoofed mime can never be reflected back and
        // rendered as a page on this origin.
        $safeInlineMimes = [
            'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp',
            'application/pdf',
            'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/webm',
            'video/mp4', 'video/webm', 'video/ogg',
        ];

        $canInline = in_array($attachment->mime_type, $safeInlineMimes, true);

        return $disk->response(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => $canInline ? $attachment->mime_type : 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ],
            ($canInline && !$request->boolean('download')) ? 'inline' : 'attachment',
        );
    }

    // ----- helpers -----

    private function conversationsFor(User $user): array
    {
        return Conversation::query()
            ->forUser($user->id)
            ->with([
                'participants.user:id,name,email',
                'latestMessage.user:id,name',
                'latestMessage.attachments',
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit((int) config('clifto-talk.conversation_limit', 100))
            ->get()
            ->map(fn (Conversation $c) => CliftoTalkPresenter::conversation($c, $user->id))
            // Pinned threads float to the top, then most recent activity.
            ->sortByDesc(fn (array $c) => [$c['is_pinned'] ? 1 : 0, $c['last_message_at'] ?? ''])
            ->values()
            ->all();
    }

    private function unreadTotalFor(User $user): int
    {
        return $this->talk->unreadTotalFor($user);
    }
}

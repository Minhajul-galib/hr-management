import { computed, reactive, readonly } from 'vue';
import axios from 'axios';

/**
 * Clifto Talk client store.
 *
 * This is a module-level singleton on purpose: the floating dock and the full
 * /clifto-talk page are two views onto the same conversations, and both must
 * see the same unread counts, the same websocket subscriptions and the same
 * in-flight messages. Two stores would double every subscription.
 */

/**
 * URL prefix, published by the server so the host app can remount the module
 * under a different path without touching any JS.
 */
let prefix = 'clifto-talk';

const base = () => `/${prefix}`;

const API = {
    conversations: () => `${base()}/conversations`,
    conversation: (id) => `${base()}/conversations/${id}`,
    preferences: (id) => `${base()}/conversations/${id}/preferences`,
    messages: (id) => `${base()}/conversations/${id}/messages`,
    read: (id) => `${base()}/conversations/${id}/read`,
    message: (id) => `${base()}/messages/${id}`,
    reactions: (id) => `${base()}/messages/${id}/reactions`,
    participants: (id) => `${base()}/conversations/${id}/participants`,
    participant: (id, userId) => `${base()}/conversations/${id}/participants/${userId}`,
    users: () => `${base()}/users`,
    search: () => `${base()}/search`,
};

/** How long a "typing…" bubble survives without a refresh whisper. */
const TYPING_TTL = 4000;
/** Dock windows past this count get closed oldest-first. */
const MAX_DOCK_WINDOWS = 3;

const state = reactive({
    booted: false,
    currentUserId: null,
    currentUserName: '',
    loadingConversations: false,
    conversations: [],
    /** conversationId -> { items, hasMore, loading, loaded } */
    threads: {},
    /** conversationId -> { userId: { name, at } } */
    typing: {},
    onlineIds: [],
    unreadTotal: 0,
    /** Dock windows, most recently opened last. */
    dockWindows: [],
    minimized: {},
    drafts: {},
    replyTo: {},
    /** Set by the full page so it can suppress its own dock windows. */
    pageConversationId: null,
    soundEnabled: true,
});

let userChannel = null;
let presenceChannel = null;
const threadChannels = new Map();
let typingSweeper = null;
let lastTypingSentAt = 0;

// ---------- helpers ----------

const echo = () => (typeof window !== 'undefined' ? window.Echo : null);

function emptyThread() {
    return { items: [], hasMore: true, loading: false, loaded: false };
}

function ensureThread(conversationId) {
    if (!state.threads[conversationId]) {
        state.threads[conversationId] = emptyThread();
    }
    return state.threads[conversationId];
}

function findConversation(id) {
    return state.conversations.find((c) => c.id === Number(id)) || null;
}

/** Pinned first, then most recent activity. Mirrors the server ordering. */
function sortConversations() {
    state.conversations.sort((a, b) => {
        if (a.is_pinned !== b.is_pinned) return a.is_pinned ? -1 : 1;
        return new Date(b.last_message_at || 0) - new Date(a.last_message_at || 0);
    });
}

function upsertConversation(conversation) {
    const index = state.conversations.findIndex((c) => c.id === conversation.id);
    if (index === -1) {
        state.conversations.push(conversation);
    } else {
        state.conversations[index] = { ...state.conversations[index], ...conversation };
    }
    sortConversations();
    return findConversation(conversation.id);
}

function recomputeUnreadTotal() {
    state.unreadTotal = state.conversations.reduce((sum, c) => sum + (c.unread_count || 0), 0);
}

/**
 * Insert or replace a message, keeping the thread ordered by id.
 *
 * Messages can arrive twice — once as the POST response and once over the
 * websocket — so identity is always the server id, never array position.
 */
function upsertMessage(conversationId, message) {
    const thread = ensureThread(conversationId);
    const existing = thread.items.findIndex((m) => m.id === message.id);

    if (existing !== -1) {
        thread.items[existing] = { ...thread.items[existing], ...message, pending: false };
        return thread.items[existing];
    }

    thread.items.push(message);
    thread.items.sort((a, b) => {
        // Optimistic rows have a temp string id and always belong last.
        if (typeof a.id === 'string') return 1;
        if (typeof b.id === 'string') return -1;
        return a.id - b.id;
    });

    return message;
}

function playPing() {
    if (!state.soundEnabled || typeof window === 'undefined') return;

    // Synthesised so the feature ships without a binary asset.
    try {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return;
        const ctx = new Ctx();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.type = 'sine';
        osc.frequency.setValueAtTime(660, ctx.currentTime);
        osc.frequency.setValueAtTime(880, ctx.currentTime + 0.08);
        gain.gain.setValueAtTime(0.0001, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.08, ctx.currentTime + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.35);
        osc.start();
        osc.stop(ctx.currentTime + 0.36);
        osc.onended = () => ctx.close();
    } catch {
        // Audio is a nicety; never let it break message delivery.
    }
}

function notifyDesktop(conversation, message) {
    if (typeof window === 'undefined' || !('Notification' in window)) return;
    if (document.visibilityState === 'visible') return;
    if (Notification.permission !== 'granted') return;

    try {
        const title = conversation?.is_group
            ? `${message.user?.name || 'Someone'} in ${conversation.title}`
            : message.user?.name || conversation?.title || 'Clifto Talk';

        new Notification(title, {
            body: message.body || (message.attachments?.length ? '📎 Attachment' : ''),
            tag: `clifto-talk-${conversation?.id}`,
        });
    } catch {
        // Ignore — notifications are best-effort.
    }
}

// ---------- realtime ----------

function bindUserChannel() {
    const Echo = echo();
    if (!Echo || !state.currentUserId || userChannel) return;

    userChannel = Echo.private(`clifto-talk.user.${state.currentUserId}`)
        .listen('.conversation.activity', ({ conversation_id: id, sender_id: senderId, message }) => {
            const conversation = findConversation(id);

            // A thread we don't know about yet (someone just messaged us).
            if (!conversation) {
                fetchConversation(id);
                if (senderId !== state.currentUserId) {
                    playPing();
                }
                return;
            }

            // Only append to a thread we already hold; otherwise the first
            // open would show a gap between this message and older history.
            const thread = state.threads[id];
            if (thread?.loaded) {
                upsertMessage(id, message);
            }

            conversation.last_message_at = message.created_at;
            conversation.last_message = {
                id: message.id,
                type: message.type,
                text: previewTextFor(message),
                user_id: message.user?.id ?? null,
                user_name: message.user?.name ?? null,
                created_at: message.created_at,
            };

            const isMine = senderId === state.currentUserId;
            const isOpen = isConversationVisible(id);

            if (!isMine && !isOpen) {
                conversation.unread_count = (conversation.unread_count || 0) + 1;
            }

            if (!isMine) {
                clearTyping(id, message.user?.id);
                if (!conversation.is_muted) {
                    playPing();
                    notifyDesktop(conversation, message);
                }
            }

            if (!isMine && isOpen) {
                markRead(id, message.id);
            }

            sortConversations();
            recomputeUnreadTotal();
        })
        .listen('.conversation.sync', ({ conversation_id: id, action }) => {
            if (action === 'removed') {
                dropConversation(id);
                return;
            }
            fetchConversation(id);
        });
}

function bindPresenceChannel() {
    const Echo = echo();
    if (!Echo || presenceChannel) return;

    presenceChannel = Echo.join('clifto-talk.online')
        .here((users) => {
            state.onlineIds = users.map((u) => Number(u.id));
        })
        .joining((user) => {
            if (!state.onlineIds.includes(Number(user.id))) {
                state.onlineIds.push(Number(user.id));
            }
        })
        .leaving((user) => {
            state.onlineIds = state.onlineIds.filter((id) => id !== Number(user.id));
        })
        .error(() => {
            // Presence is decorative; a failed join must not break chat.
        });
}

function subscribeToThread(conversationId) {
    const Echo = echo();
    const id = Number(conversationId);
    if (!Echo || threadChannels.has(id)) return;

    const channel = Echo.private(`clifto-talk.conversation.${id}`)
        .listen('.message.sent', ({ message }) => {
            upsertMessage(id, message);
            clearTyping(id, message.user?.id);
        })
        .listen('.message.updated', ({ message }) => {
            upsertMessage(id, message);
        })
        .listen('.conversation.read', ({ user_id: userId, last_read_message_id: lastRead }) => {
            const conversation = findConversation(id);
            const participant = conversation?.participants?.find((p) => p.id === userId);
            if (participant) {
                participant.last_read_message_id = lastRead;
            }
        })
        .listenForWhisper('typing', ({ user_id: userId, name }) => {
            if (userId === state.currentUserId) return;
            if (!state.typing[id]) state.typing[id] = {};
            state.typing[id][userId] = { name, at: Date.now() };
        })
        .listenForWhisper('stopped-typing', ({ user_id: userId }) => {
            clearTyping(id, userId);
        });

    threadChannels.set(id, channel);
}

function unsubscribeFromThread(conversationId) {
    const Echo = echo();
    const id = Number(conversationId);
    if (!Echo || !threadChannels.has(id)) return;

    Echo.leave(`clifto-talk.conversation.${id}`);
    threadChannels.delete(id);
    delete state.typing[id];
}

function clearTyping(conversationId, userId) {
    if (!userId || !state.typing[conversationId]) return;
    delete state.typing[conversationId][userId];
}

/** Expire stale "typing…" bubbles when someone closes their tab mid-sentence. */
function startTypingSweeper() {
    if (typingSweeper) return;

    typingSweeper = setInterval(() => {
        const now = Date.now();
        Object.keys(state.typing).forEach((conversationId) => {
            const entries = state.typing[conversationId];
            Object.keys(entries).forEach((userId) => {
                if (now - entries[userId].at > TYPING_TTL) {
                    delete entries[userId];
                }
            });
        });
    }, 1500);
}

// ---------- data ----------

function previewTextFor(message) {
    if (message.is_deleted) return 'This message was deleted';
    if (message.body) return message.body;
    if (message.attachments?.length) {
        const first = message.attachments[0];
        return first.is_image ? '📷 Photo' : `📎 ${first.name}`;
    }
    return '';
}

async function loadConversations() {
    state.loadingConversations = true;
    try {
        const { data } = await axios.get(API.conversations());
        state.conversations = data.conversations;
        sortConversations();
        recomputeUnreadTotal();
    } finally {
        state.loadingConversations = false;
    }
}

async function fetchConversation(id) {
    try {
        const { data } = await axios.get(API.conversation(id));
        upsertConversation(data.conversation);
        recomputeUnreadTotal();
        return data.conversation;
    } catch (error) {
        // 403 means we were removed from the thread while it was open.
        if (error?.response?.status === 403 || error?.response?.status === 404) {
            dropConversation(id);
        }
        return null;
    }
}

function dropConversation(id) {
    const numeric = Number(id);
    state.conversations = state.conversations.filter((c) => c.id !== numeric);
    state.dockWindows = state.dockWindows.filter((w) => w !== numeric);
    delete state.threads[numeric];
    delete state.typing[numeric];
    unsubscribeFromThread(numeric);
    if (state.pageConversationId === numeric) {
        state.pageConversationId = null;
    }
    recomputeUnreadTotal();
}

async function loadMessages(conversationId, { before = null } = {}) {
    const thread = ensureThread(conversationId);
    if (thread.loading) return;

    thread.loading = true;
    try {
        const { data } = await axios.get(API.messages(conversationId), {
            params: before ? { before } : {},
        });

        if (before) {
            // Prepend history without disturbing anything already on screen.
            const known = new Set(thread.items.map((m) => m.id));
            thread.items = [...data.messages.filter((m) => !known.has(m.id)), ...thread.items];
        } else {
            thread.items = data.messages;
        }

        thread.hasMore = data.has_more;
        thread.loaded = true;
    } finally {
        thread.loading = false;
    }
}

async function loadOlderMessages(conversationId) {
    const thread = ensureThread(conversationId);
    if (!thread.hasMore || thread.loading || thread.items.length === 0) return;

    const oldest = thread.items.find((m) => typeof m.id === 'number');
    if (!oldest) return;

    await loadMessages(conversationId, { before: oldest.id });
}

async function sendMessage(conversationId, { body = '', files = [] } = {}) {
    const text = (body || '').trim();
    const replyTo = state.replyTo[conversationId] || null;

    if (!text && files.length === 0) return null;

    // Optimistic bubble so the UI feels instant on a slow connection.
    const tempId = `tmp-${Date.now()}-${Math.random().toString(36).slice(2)}`;
    const optimistic = {
        id: tempId,
        conversation_id: Number(conversationId),
        type: files.length ? 'attachment' : 'text',
        body: text || null,
        metadata: null,
        is_deleted: false,
        edited_at: null,
        created_at: new Date().toISOString(),
        user: { id: state.currentUserId, name: 'You', initials: 'You' },
        parent: replyTo
            ? {
                  id: replyTo.id,
                  body: replyTo.body,
                  type: replyTo.type,
                  is_deleted: false,
                  user_name: replyTo.user?.name,
                  has_attachments: (replyTo.attachments?.length || 0) > 0,
              }
            : null,
        attachments: [],
        reactions: [],
        pending: true,
        failed: false,
        // Local-only hint so the composer can show "Uploading 2 files…".
        pending_files: files.length,
    };

    const thread = ensureThread(conversationId);
    thread.items.push(optimistic);

    state.drafts[conversationId] = '';
    state.replyTo[conversationId] = null;

    const form = new FormData();
    if (text) form.append('body', text);
    if (replyTo) form.append('parent_id', replyTo.id);
    files.forEach((file) => form.append('attachments[]', file));

    try {
        const { data } = await axios.post(API.messages(conversationId), form, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        // Swap the placeholder for the authoritative row.
        const index = thread.items.findIndex((m) => m.id === tempId);
        if (index !== -1) thread.items.splice(index, 1);
        upsertMessage(conversationId, data.message);

        const conversation = findConversation(conversationId);
        if (conversation) {
            conversation.last_message_at = data.message.created_at;
            conversation.last_message = {
                id: data.message.id,
                type: data.message.type,
                text: previewTextFor(data.message),
                user_id: state.currentUserId,
                user_name: data.message.user?.name,
                created_at: data.message.created_at,
            };
            sortConversations();
        }

        return data.message;
    } catch (error) {
        const failed = thread.items.find((m) => m.id === tempId);
        if (failed) {
            failed.pending = false;
            failed.failed = true;
            failed.error = error?.response?.data?.message || 'Message failed to send.';
        }
        // Put the text back so nothing is lost.
        if (text) state.drafts[conversationId] = text;
        return null;
    }
}

function retryMessage(conversationId, tempId) {
    const thread = ensureThread(conversationId);
    const index = thread.items.findIndex((m) => m.id === tempId);
    if (index === -1) return;

    const [failed] = thread.items.splice(index, 1);
    return sendMessage(conversationId, { body: failed.body || '' });
}

function discardMessage(conversationId, tempId) {
    const thread = ensureThread(conversationId);
    thread.items = thread.items.filter((m) => m.id !== tempId);
}

async function editMessage(conversationId, messageId, body) {
    const { data } = await axios.patch(API.message(messageId), { body });
    upsertMessage(conversationId, data.message);
    return data.message;
}

async function deleteMessage(conversationId, messageId) {
    const { data } = await axios.delete(API.message(messageId));
    upsertMessage(conversationId, data.message);
    return data.message;
}

async function toggleReaction(conversationId, messageId, emoji) {
    const thread = ensureThread(conversationId);
    const message = thread.items.find((m) => m.id === messageId);

    // Optimistically flip so the emoji responds under the cursor.
    if (message) {
        const bucket = message.reactions.find((r) => r.emoji === emoji);
        if (bucket?.user_ids.includes(state.currentUserId)) {
            bucket.user_ids = bucket.user_ids.filter((id) => id !== state.currentUserId);
            bucket.count -= 1;
            if (bucket.count <= 0) {
                message.reactions = message.reactions.filter((r) => r.emoji !== emoji);
            }
        } else if (bucket) {
            bucket.user_ids.push(state.currentUserId);
            bucket.count += 1;
        } else {
            message.reactions.push({ emoji, count: 1, user_ids: [state.currentUserId] });
        }
    }

    const { data } = await axios.post(API.reactions(messageId), { emoji });
    upsertMessage(conversationId, data.message);
    return data.message;
}

async function markRead(conversationId, messageId = null) {
    const conversation = findConversation(conversationId);
    if (conversation) {
        conversation.unread_count = 0;
        recomputeUnreadTotal();
    }

    try {
        const { data } = await axios.post(API.read(conversationId), { message_id: messageId });
        if (conversation) {
            conversation.last_read_message_id = data.last_read_message_id;
        }
        state.unreadTotal = data.unread_total;
    } catch {
        // The watermark will be corrected by the next successful read.
    }
}

function sendTyping(conversationId) {
    const channel = threadChannels.get(Number(conversationId));
    if (!channel) return;

    // Whisper at most every 1.5s; keystrokes are far faster than that.
    const now = Date.now();
    if (now - lastTypingSentAt < 1500) return;
    lastTypingSentAt = now;

    try {
        channel.whisper('typing', { user_id: state.currentUserId, name: state.currentUserName });
    } catch {
        // Client events may be disabled on the broker; typing is optional.
    }
}

function sendStoppedTyping(conversationId) {
    const channel = threadChannels.get(Number(conversationId));
    if (!channel) return;
    lastTypingSentAt = 0;
    try {
        channel.whisper('stopped-typing', { user_id: state.currentUserId });
    } catch {
        // Ignore.
    }
}

// ---------- conversation management ----------

async function startDirect(userId) {
    const { data } = await axios.post(API.conversations(), { type: 'direct', user_id: userId });
    const conversation = upsertConversation(data.conversation);
    recomputeUnreadTotal();
    return conversation;
}

async function createGroup(name, userIds) {
    const { data } = await axios.post(API.conversations(), { type: 'group', name, user_ids: userIds });
    const conversation = upsertConversation(data.conversation);
    recomputeUnreadTotal();
    return conversation;
}

async function renameGroup(conversationId, name, avatar = null) {
    const form = new FormData();
    form.append('name', name);
    if (avatar) form.append('avatar', avatar);

    const { data } = await axios.post(API.conversation(conversationId), form, {
        headers: { 'Content-Type': 'multipart/form-data' },
    });
    return upsertConversation(data.conversation);
}

async function addParticipants(conversationId, userIds) {
    const { data } = await axios.post(API.participants(conversationId), { user_ids: userIds });
    return upsertConversation(data.conversation);
}

async function removeParticipant(conversationId, userId) {
    const { data } = await axios.delete(API.participant(conversationId, userId));

    if (data.left) {
        dropConversation(conversationId);
        return null;
    }

    return upsertConversation(data.conversation);
}

async function setParticipantRole(conversationId, userId, role) {
    const { data } = await axios.patch(API.participant(conversationId, userId), { role });
    return upsertConversation(data.conversation);
}

async function setPreferences(conversationId, preferences) {
    const { data } = await axios.patch(API.preferences(conversationId), preferences);
    const conversation = upsertConversation(data.conversation);
    recomputeUnreadTotal();
    return conversation;
}

async function searchUsers(term = '') {
    const { data } = await axios.get(API.users(), { params: { q: term } });
    return data.users;
}

async function searchMessages(term) {
    const { data } = await axios.get(API.search(), { params: { q: term } });
    return data.results;
}

// ---------- view management ----------

/** True when the thread is on screen somewhere, so it shouldn't count unread. */
function isConversationVisible(conversationId) {
    const id = Number(conversationId);

    if (state.pageConversationId === id) return true;

    return state.dockWindows.includes(id) && !state.minimized[id];
}

async function openConversation(conversationId, { dock = true } = {}) {
    const id = Number(conversationId);

    if (!findConversation(id)) {
        await fetchConversation(id);
    }

    subscribeToThread(id);

    const thread = ensureThread(id);
    if (!thread.loaded) {
        await loadMessages(id);
    }

    if (dock) {
        if (!state.dockWindows.includes(id)) {
            state.dockWindows.push(id);
        }
        state.minimized[id] = false;

        // Keep the dock from marching across the screen.
        while (state.dockWindows.length > MAX_DOCK_WINDOWS) {
            const evicted = state.dockWindows.shift();
            if (evicted !== id) closeDockWindow(evicted, { keepSubscription: false });
        }
    }

    await markRead(id);

    return findConversation(id);
}

function closeDockWindow(conversationId, { keepSubscription = false } = {}) {
    const id = Number(conversationId);
    state.dockWindows = state.dockWindows.filter((w) => w !== id);
    delete state.minimized[id];

    if (!keepSubscription && state.pageConversationId !== id) {
        unsubscribeFromThread(id);
    }
}

function toggleMinimized(conversationId) {
    const id = Number(conversationId);
    state.minimized[id] = !state.minimized[id];
    if (!state.minimized[id]) markRead(id);
}

/** Called by the full page so the dock knows the thread is already on screen. */
async function setPageConversation(conversationId) {
    const previous = state.pageConversationId;
    const id = conversationId === null ? null : Number(conversationId);

    state.pageConversationId = id;

    if (previous && previous !== id && !state.dockWindows.includes(previous)) {
        unsubscribeFromThread(previous);
    }

    if (id === null) return null;

    subscribeToThread(id);

    const thread = ensureThread(id);
    if (!thread.loaded) {
        await loadMessages(id);
    }

    await markRead(id);

    return findConversation(id);
}

function setDraft(conversationId, value) {
    state.drafts[conversationId] = value;
}

function setReplyTo(conversationId, message) {
    state.replyTo[conversationId] = message;
}

// ---------- boot ----------

function boot(currentUserId, { conversations = null, currentUserName = '', routePrefix = null } = {}) {
    // Set before anything can issue a request, so no call uses a stale path.
    if (routePrefix) prefix = String(routePrefix).replace(/^\/+|\/+$/g, '');

    if (state.booted) {
        // A subsequent Inertia page can still hand us fresher server data.
        if (conversations) {
            state.conversations = conversations;
            sortConversations();
            recomputeUnreadTotal();
        }
        return;
    }

    state.booted = true;
    state.currentUserId = Number(currentUserId);
    state.currentUserName = currentUserName;
    state.soundEnabled = localStorage.getItem('cliftoTalk.sound') !== 'off';

    if (conversations) {
        state.conversations = conversations;
        sortConversations();
        recomputeUnreadTotal();
    } else {
        loadConversations();
    }

    bindUserChannel();
    bindPresenceChannel();
    startTypingSweeper();
}

function setSoundEnabled(enabled) {
    state.soundEnabled = enabled;
    localStorage.setItem('cliftoTalk.sound', enabled ? 'on' : 'off');
}

function requestDesktopPermission() {
    if (typeof window === 'undefined' || !('Notification' in window)) return;
    if (Notification.permission === 'default') {
        Notification.requestPermission().catch(() => {});
    }
}

export function useCliftoTalk() {
    return {
        state: readonly(state),
        // Mutable views the components bind to directly.
        conversations: computed(() => state.conversations),
        unreadTotal: computed(() => state.unreadTotal),
        dockWindows: computed(() => state.dockWindows),
        onlineIds: computed(() => state.onlineIds),
        currentUserId: computed(() => state.currentUserId),

        thread: (id) => ensureThread(id),
        conversation: (id) => findConversation(id),
        typingIn: (id) => Object.values(state.typing[id] || {}),
        draft: (id) => state.drafts[id] || '',
        replyTo: (id) => state.replyTo[id] || null,
        isMinimized: (id) => Boolean(state.minimized[id]),
        isOnline: (userId) => state.onlineIds.includes(Number(userId)),

        boot,
        loadConversations,
        fetchConversation,
        loadMessages,
        loadOlderMessages,
        openConversation,
        closeDockWindow,
        toggleMinimized,
        setPageConversation,
        sendMessage,
        retryMessage,
        discardMessage,
        editMessage,
        deleteMessage,
        toggleReaction,
        markRead,
        sendTyping,
        sendStoppedTyping,
        startDirect,
        createGroup,
        renameGroup,
        addParticipants,
        removeParticipant,
        setParticipantRole,
        setPreferences,
        searchUsers,
        searchMessages,
        setDraft,
        setReplyTo,
        setSoundEnabled,
        requestDesktopPermission,
        subscribeToThread,
        unsubscribeFromThread,
    };
}

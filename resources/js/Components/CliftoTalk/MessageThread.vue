<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Loader2, MessagesSquare, X } from 'lucide-vue-next';
import MessageBubble from './MessageBubble.vue';
import MessageComposer from './MessageComposer.vue';
import { useCliftoTalk } from '@/composables/useCliftoTalk';
import { formatDayLabel, sameDay } from './format';

const props = defineProps({
    conversationId: { type: Number, required: true },
    compact: { type: Boolean, default: false },
});

const talk = useCliftoTalk();

const scroller = ref(null);
const lightbox = ref(null);
/** Only auto-scroll when the reader is already at the bottom. */
const pinnedToBottom = ref(true);
const stopTypingTimer = ref(null);

const conversation = computed(() => talk.conversation(props.conversationId));
const thread = computed(() => talk.thread(props.conversationId));
const messages = computed(() => thread.value.items);
const currentUserId = computed(() => talk.currentUserId.value);
const draft = computed(() => talk.draft(props.conversationId));
const replyTo = computed(() => talk.replyTo(props.conversationId));

const typingNames = computed(() => talk.typingIn(props.conversationId).map((t) => t.name).filter(Boolean));

const typingLabel = computed(() => {
    const names = typingNames.value;
    if (names.length === 0) return '';
    if (!conversation.value?.is_group) return 'typing…';
    if (names.length === 1) return `${names[0]} is typing…`;
    if (names.length === 2) return `${names[0]} and ${names[1]} are typing…`;
    return 'Several people are typing…';
});

const canModerate = computed(
    () => Boolean(conversation.value?.is_group && conversation.value?.my_role === 'admin'),
);

/** The newest message I sent — the only one that carries a ✓✓ receipt. */
const lastOwnMessageId = computed(() => {
    for (let i = messages.value.length - 1; i >= 0; i -= 1) {
        const message = messages.value[i];
        if (message.user?.id === currentUserId.value && typeof message.id === 'number') {
            return message.id;
        }
    }
    return null;
});

function seenBy(message) {
    if (typeof message.id !== 'number') return [];

    return (conversation.value?.participants || [])
        .filter((p) => p.id !== currentUserId.value && (p.last_read_message_id || 0) >= message.id)
        .map((p) => p.name);
}

/** Stacked bubbles: hide the avatar/name when the same person continues. */
function showAuthor(index) {
    if (index === 0) return true;

    const current = messages.value[index];
    const previous = messages.value[index - 1];

    if (previous.type === 'system' || current.type === 'system') return true;
    if (previous.user?.id !== current.user?.id) return true;

    return new Date(current.created_at) - new Date(previous.created_at) > 5 * 60 * 1000;
}

function showDaySeparator(index) {
    if (index === 0) return true;
    return !sameDay(messages.value[index - 1].created_at, messages.value[index].created_at);
}

function isNearBottom() {
    const el = scroller.value;
    if (!el) return true;
    return el.scrollHeight - el.scrollTop - el.clientHeight < 120;
}

async function scrollToBottom(smooth = false) {
    await nextTick();
    const el = scroller.value;
    if (!el) return;
    el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
}

async function onScroll() {
    const el = scroller.value;
    if (!el) return;

    pinnedToBottom.value = isNearBottom();

    if (el.scrollTop < 80 && thread.value.hasMore && !thread.value.loading) {
        // Hold the reader's place while older messages are spliced in above.
        const previousHeight = el.scrollHeight;
        await talk.loadOlderMessages(props.conversationId);
        await nextTick();
        el.scrollTop = el.scrollHeight - previousHeight + el.scrollTop;
    }
}

watch(
    () => messages.value.length,
    async () => {
        if (pinnedToBottom.value) await scrollToBottom();
    },
);

watch(
    () => props.conversationId,
    async () => {
        pinnedToBottom.value = true;
        await scrollToBottom();
    },
);

onMounted(async () => {
    if (!thread.value.loaded) {
        await talk.loadMessages(props.conversationId);
    }
    await scrollToBottom();
});

function onTyping() {
    talk.sendTyping(props.conversationId);

    clearTimeout(stopTypingTimer.value);
    stopTypingTimer.value = setTimeout(() => talk.sendStoppedTyping(props.conversationId), 2500);
}

async function onSend({ body, files }) {
    pinnedToBottom.value = true;
    await talk.sendMessage(props.conversationId, { body, files });
    await scrollToBottom(true);
}

function onEdit(message, body) {
    talk.editMessage(props.conversationId, message.id, body);
}

async function onDelete(message) {
    const confirmed = window.confirm('Delete this message for everyone?');
    if (confirmed) await talk.deleteMessage(props.conversationId, message.id);
}
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col bg-white dark:bg-gray-800">
        <div
            ref="scroller"
            class="flex-1 overflow-y-auto overscroll-contain py-2"
            @scroll.passive="onScroll"
        >
            <div v-if="thread.loading && messages.length === 0" class="flex h-full items-center justify-center">
                <Loader2 class="h-5 w-5 animate-spin text-gray-400" />
            </div>

            <div
                v-else-if="messages.length === 0"
                class="flex h-full flex-col items-center justify-center gap-2 px-6 text-center"
            >
                <MessagesSquare class="h-8 w-8 text-gray-300 dark:text-gray-600" />
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">No messages yet</p>
                <p class="text-xs text-gray-400 dark:text-gray-500">Say hello to start the conversation.</p>
            </div>

            <template v-else>
                <div v-if="thread.loading" class="flex justify-center py-2">
                    <Loader2 class="h-4 w-4 animate-spin text-gray-400" />
                </div>
                <p v-else-if="!thread.hasMore" class="py-3 text-center text-[11px] text-gray-400 dark:text-gray-500">
                    This is the beginning of the conversation
                </p>

                <template v-for="(message, index) in messages" :key="message.id">
                    <div v-if="showDaySeparator(index)" class="my-3 flex items-center gap-3 px-4">
                        <span class="h-px flex-1 bg-gray-200 dark:bg-gray-700" />
                        <span class="text-[11px] font-medium text-gray-400 dark:text-gray-500">
                            {{ formatDayLabel(message.created_at) }}
                        </span>
                        <span class="h-px flex-1 bg-gray-200 dark:bg-gray-700" />
                    </div>

                    <MessageBubble
                        :message="message"
                        :current-user-id="currentUserId"
                        :is-group="Boolean(conversation?.is_group)"
                        :show-author="showAuthor(index)"
                        :can-moderate="canModerate"
                        :seen-by="seenBy(message)"
                        :show-receipt="message.id === lastOwnMessageId"
                        :compact="compact"
                        @reply="talk.setReplyTo(conversationId, $event)"
                        @react="(m, emoji) => talk.toggleReaction(conversationId, m.id, emoji)"
                        @edit="onEdit"
                        @delete="onDelete"
                        @retry="talk.retryMessage(conversationId, $event.id)"
                        @discard="talk.discardMessage(conversationId, $event.id)"
                        @preview-image="lightbox = $event"
                    />
                </template>
            </template>
        </div>

        <div v-if="typingLabel" class="flex items-center gap-2 px-4 pb-1 text-[11px] text-gray-500 dark:text-gray-400">
            <span class="flex gap-0.5">
                <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-gray-400 [animation-delay:-0.3s]" />
                <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-gray-400 [animation-delay:-0.15s]" />
                <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-gray-400" />
            </span>
            {{ typingLabel }}
        </div>

        <MessageComposer
            :model-value="draft"
            :reply-to="replyTo"
            :compact="compact"
            @update:model-value="talk.setDraft(conversationId, $event)"
            @send="onSend"
            @typing="onTyping"
            @stopped-typing="talk.sendStoppedTyping(conversationId)"
            @cancel-reply="talk.setReplyTo(conversationId, null)"
        />

        <!-- Image lightbox -->
        <Teleport to="body">
            <div
                v-if="lightbox"
                class="fixed inset-0 z-[70] flex items-center justify-center bg-black/80 p-6"
                @click="lightbox = null"
            >
                <button
                    type="button"
                    class="absolute right-4 top-4 rounded-full bg-white/10 p-2 text-white transition hover:bg-white/20"
                    @click.stop="lightbox = null"
                >
                    <X class="h-5 w-5" />
                </button>
                <img :src="lightbox.url" :alt="lightbox.name" class="max-h-full max-w-full rounded-lg object-contain" @click.stop />
                <a
                    :href="lightbox.download_url"
                    class="absolute bottom-6 rounded-full bg-white/10 px-4 py-2 text-sm text-white transition hover:bg-white/20"
                    @click.stop
                >
                    Download {{ lightbox.name }}
                </a>
            </div>
        </Teleport>
    </div>
</template>

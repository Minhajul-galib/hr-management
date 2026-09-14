<script setup>
import { computed, ref } from 'vue';
import { BellOff, Loader2, Pin, Search, X } from 'lucide-vue-next';
import ChatAvatar from './ChatAvatar.vue';
import { useCliftoTalk } from '@/composables/useCliftoTalk';
import { formatListTimestamp } from './format';

const props = defineProps({
    activeId: { type: Number, default: null },
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['select']);

const talk = useCliftoTalk();
const filter = ref('');

const conversations = computed(() => {
    const term = filter.value.trim().toLowerCase();
    if (!term) return talk.conversations.value;

    return talk.conversations.value.filter((c) => {
        const inTitle = c.title.toLowerCase().includes(term);
        const inPreview = (c.last_message?.text || '').toLowerCase().includes(term);
        const inMembers = c.participants.some((p) => p.name.toLowerCase().includes(term));
        return inTitle || inPreview || inMembers;
    });
});

function previewFor(conversation) {
    const last = conversation.last_message;
    if (!last) return 'No messages yet';
    if (last.type === 'system') return last.text || 'Conversation updated';

    const mine = last.user_id === talk.currentUserId.value;
    const prefix = mine ? 'You: ' : conversation.is_group && last.user_name ? `${last.user_name.split(' ')[0]}: ` : '';

    return `${prefix}${last.text || ''}`;
}

function typingIn(conversation) {
    return talk.typingIn(conversation.id).length > 0;
}

function isOnline(conversation) {
    return !conversation.is_group && talk.isOnline(conversation.counterpart_id);
}
</script>

<template>
    <div class="flex min-h-0 flex-col">
        <div class="shrink-0 px-3 pb-2">
            <div class="relative">
                <Search class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                <input
                    v-model="filter"
                    type="text"
                    placeholder="Search conversations"
                    class="w-full rounded-lg border-gray-200 bg-gray-50 py-1.5 pl-8 pr-8 text-sm text-gray-900 placeholder-gray-400 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:placeholder-gray-500"
                />
                <button
                    v-if="filter"
                    type="button"
                    class="absolute right-2 top-1/2 -translate-y-1/2 rounded p-0.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                    @click="filter = ''"
                >
                    <X class="h-3.5 w-3.5" />
                </button>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto">
            <div v-if="talk.state.loadingConversations && conversations.length === 0" class="flex justify-center py-6">
                <Loader2 class="h-5 w-5 animate-spin text-gray-400" />
            </div>

            <p
                v-else-if="conversations.length === 0"
                class="px-4 py-6 text-center text-sm text-gray-400 dark:text-gray-500"
            >
                {{ filter ? 'No conversations match that search.' : 'No conversations yet. Start one below.' }}
            </p>

            <button
                v-for="conversation in conversations"
                :key="conversation.id"
                type="button"
                class="flex w-full items-center gap-3 px-3 py-2.5 text-left transition"
                :class="conversation.id === activeId
                    ? 'bg-indigo-50 dark:bg-indigo-900/30'
                    : 'hover:bg-gray-50 dark:hover:bg-gray-700/50'"
                @click="emit('select', conversation.id)"
            >
                <ChatAvatar
                    :name="conversation.title"
                    :initials="conversation.initials"
                    :src="conversation.avatar_url"
                    :group="conversation.is_group"
                    :online="isOnline(conversation)"
                    :show-presence="!conversation.is_group"
                    size="md"
                />

                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-1.5">
                        <p
                            class="min-w-0 flex-1 truncate text-sm"
                            :class="conversation.unread_count > 0
                                ? 'font-semibold text-gray-900 dark:text-white'
                                : 'font-medium text-gray-700 dark:text-gray-200'"
                        >
                            {{ conversation.title }}
                        </p>
                        <Pin v-if="conversation.is_pinned" class="h-3 w-3 shrink-0 text-gray-400" />
                        <BellOff v-if="conversation.is_muted" class="h-3 w-3 shrink-0 text-gray-400" />
                        <span class="shrink-0 text-[11px] text-gray-400 dark:text-gray-500">
                            {{ formatListTimestamp(conversation.last_message_at) }}
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <p
                            v-if="typingIn(conversation)"
                            class="min-w-0 flex-1 truncate text-xs italic text-indigo-600 dark:text-indigo-400"
                        >
                            typing…
                        </p>
                        <p
                            v-else
                            class="min-w-0 flex-1 truncate text-xs"
                            :class="conversation.unread_count > 0
                                ? 'font-medium text-gray-700 dark:text-gray-200'
                                : 'text-gray-400 dark:text-gray-500'"
                        >
                            {{ previewFor(conversation) }}
                        </p>

                        <span
                            v-if="conversation.unread_count > 0"
                            class="shrink-0 rounded-full bg-indigo-600 px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white"
                        >
                            {{ conversation.unread_count > 99 ? '99+' : conversation.unread_count }}
                        </span>
                    </div>
                </div>
            </button>
        </div>
    </div>
</template>

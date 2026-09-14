<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import {
    Bell, BellOff, Info, Loader2, MessagesSquare, PenSquare, Pin, PinOff,
    Search, Users, Volume2, VolumeX, X,
} from 'lucide-vue-next';
import ChatAvatar from '@/Components/CliftoTalk/ChatAvatar.vue';
import ConversationList from '@/Components/CliftoTalk/ConversationList.vue';
import MessageThread from '@/Components/CliftoTalk/MessageThread.vue';
import NewConversationModal from '@/Components/CliftoTalk/NewConversationModal.vue';
import GroupSettingsModal from '@/Components/CliftoTalk/GroupSettingsModal.vue';
import { formatListTimestamp } from '@/Components/CliftoTalk/format';
import { useCliftoTalk } from '@/composables/useCliftoTalk';

const props = defineProps({
    initialConversations: { type: Array, default: () => [] },
    openConversationId: { type: Number, default: null },
});

const page = usePage();
const talk = useCliftoTalk();

const activeId = ref(null);
const showNew = ref(false);
const newMode = ref('direct');
const showSettings = ref(false);
const showDetails = ref(true);
const searchTerm = ref('');
const searchResults = ref([]);
const searching = ref(false);

let searchTimer = null;

const user = computed(() => page.props.auth?.user);
const conversation = computed(() => (activeId.value ? talk.conversation(activeId.value) : null));
const soundOn = computed(() => talk.state.soundEnabled);
const brand = computed(() => page.props.cliftoTalk?.brand || 'Clifto Talk');

const online = computed(
    () => !conversation.value?.is_group && talk.isOnline(conversation.value?.counterpart_id),
);

const subtitle = computed(() => {
    if (!conversation.value) return '';
    if (conversation.value.is_group) return `${conversation.value.participants.length} members`;
    return online.value ? 'Active now' : 'Offline';
});

const sharedFiles = computed(() => {
    const thread = activeId.value ? talk.thread(activeId.value) : null;
    if (!thread) return [];

    return thread.items
        .flatMap((m) => m.attachments || [])
        .slice(-12)
        .reverse();
});

onMounted(async () => {
    if (!user.value) return;

    talk.boot(user.value.id, {
        currentUserName: user.value.name,
        routePrefix: page.props.cliftoTalk?.prefix,
        conversations: props.initialConversations,
    });

    const first = props.openConversationId || talk.conversations.value[0]?.id || null;
    if (first) await select(first);
});

onBeforeUnmount(() => {
    // Hand the thread back to the dock when navigating away.
    talk.setPageConversation(null);
});

watch(searchTerm, (term) => {
    clearTimeout(searchTimer);

    if (term.trim().length < 2) {
        searchResults.value = [];
        return;
    }

    searching.value = true;
    searchTimer = setTimeout(async () => {
        try {
            searchResults.value = await talk.searchMessages(term.trim());
        } finally {
            searching.value = false;
        }
    }, 300);
});

async function select(id) {
    activeId.value = Number(id);
    await talk.setPageConversation(activeId.value);
}

function openNew(mode) {
    newMode.value = mode;
    showNew.value = true;
}

async function onCreated(created) {
    if (created) await select(created.id);
}

async function openSearchResult(result) {
    searchTerm.value = '';
    searchResults.value = [];
    await select(result.conversation_id);
}

function togglePin() {
    talk.setPreferences(activeId.value, { is_pinned: !conversation.value.is_pinned });
}

function toggleMute() {
    talk.setPreferences(activeId.value, { muted: !conversation.value.is_muted });
}

function onLeftGroup() {
    activeId.value = null;
    const next = talk.conversations.value[0]?.id;
    if (next) select(next);
}
</script>

<template>
    <Head :title="brand" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-100">{{ brand }}</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        Message colleagues one to one or in groups
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-gray-200 p-2 text-gray-500 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-400 dark:hover:bg-gray-700"
                        :title="soundOn ? 'Mute notification sound' : 'Unmute notification sound'"
                        @click="talk.setSoundEnabled(!soundOn); talk.requestDesktopPermission()"
                    >
                        <component :is="soundOn ? Volume2 : VolumeX" class="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700"
                        @click="openNew('group')"
                    >
                        <Users class="h-4 w-4" /> New group
                    </button>
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-indigo-700"
                        @click="openNew('direct')"
                    >
                        <PenSquare class="h-4 w-4" /> New chat
                    </button>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-[100rem] px-4 sm:px-6 lg:px-8">
                <div
                    class="flex h-[calc(100vh-13rem)] min-h-[32rem] overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800"
                >
                    <!-- Conversations -->
                    <aside class="flex w-72 shrink-0 flex-col border-r border-gray-200 dark:border-gray-700">
                        <div class="shrink-0 px-3 pb-1 pt-3">
                            <div class="relative">
                                <Search class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                                <input
                                    v-model="searchTerm"
                                    type="text"
                                    placeholder="Search all messages"
                                    class="w-full rounded-lg border-gray-200 bg-gray-50 py-1.5 pl-8 pr-8 text-sm text-gray-900 placeholder-gray-400 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                />
                                <button
                                    v-if="searchTerm"
                                    type="button"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 rounded p-0.5 text-gray-400 hover:text-gray-600"
                                    @click="searchTerm = ''"
                                >
                                    <X class="h-3.5 w-3.5" />
                                </button>
                            </div>
                        </div>

                        <!-- Message search results replace the list while searching -->
                        <div v-if="searchTerm.trim().length >= 2" class="min-h-0 flex-1 overflow-y-auto px-2 py-2">
                            <div v-if="searching" class="flex justify-center py-6">
                                <Loader2 class="h-5 w-5 animate-spin text-gray-400" />
                            </div>
                            <p v-else-if="searchResults.length === 0" class="py-6 text-center text-sm text-gray-400">
                                No messages found.
                            </p>
                            <button
                                v-for="result in searchResults"
                                :key="result.message_id"
                                type="button"
                                class="mb-1 w-full rounded-lg px-2 py-2 text-left transition hover:bg-gray-50 dark:hover:bg-gray-700"
                                @click="openSearchResult(result)"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <span class="truncate text-xs font-semibold text-gray-700 dark:text-gray-200">
                                        {{ result.conversation_title }}
                                    </span>
                                    <span class="shrink-0 text-[10px] text-gray-400">
                                        {{ formatListTimestamp(result.created_at) }}
                                    </span>
                                </div>
                                <p class="line-clamp-2 text-xs text-gray-500 dark:text-gray-400">
                                    <span class="font-medium">{{ result.user_name }}:</span> {{ result.body }}
                                </p>
                            </button>
                        </div>

                        <ConversationList v-else class="min-h-0 flex-1 pt-1" :active-id="activeId" @select="select" />
                    </aside>

                    <!-- Thread -->
                    <section class="flex min-w-0 flex-1 flex-col">
                        <template v-if="conversation">
                            <header class="flex shrink-0 items-center gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                                <ChatAvatar
                                    :name="conversation.title"
                                    :initials="conversation.initials"
                                    :src="conversation.avatar_url"
                                    :group="conversation.is_group"
                                    :online="online"
                                    :show-presence="!conversation.is_group"
                                    size="md"
                                />
                                <div class="min-w-0 flex-1">
                                    <h3 class="truncate text-sm font-semibold text-gray-900 dark:text-white">
                                        {{ conversation.title }}
                                    </h3>
                                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ subtitle }}</p>
                                </div>

                                <button
                                    type="button"
                                    class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700"
                                    :title="conversation.is_pinned ? 'Unpin' : 'Pin to top'"
                                    @click="togglePin"
                                >
                                    <component :is="conversation.is_pinned ? PinOff : Pin" class="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700"
                                    :title="conversation.is_muted ? 'Unmute' : 'Mute'"
                                    @click="toggleMute"
                                >
                                    <component :is="conversation.is_muted ? Bell : BellOff" class="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg p-2 transition"
                                    :class="showDetails
                                        ? 'bg-indigo-50 text-indigo-600 dark:bg-indigo-900/40 dark:text-indigo-300'
                                        : 'text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700'"
                                    title="Conversation details"
                                    @click="showDetails = !showDetails"
                                >
                                    <Info class="h-4 w-4" />
                                </button>
                            </header>

                            <MessageThread :key="conversation.id" :conversation-id="conversation.id" />
                        </template>

                        <div v-else class="flex flex-1 flex-col items-center justify-center gap-3 px-6 text-center">
                            <MessagesSquare class="h-12 w-12 text-gray-200 dark:text-gray-600" />
                            <p class="text-base font-medium text-gray-500 dark:text-gray-400">
                                Select a conversation
                            </p>
                            <p class="max-w-xs text-sm text-gray-400 dark:text-gray-500">
                                Pick a thread on the left, or start a new chat with a colleague.
                            </p>
                            <button
                                type="button"
                                class="mt-1 flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700"
                                @click="openNew('direct')"
                            >
                                <PenSquare class="h-4 w-4" /> New chat
                            </button>
                        </div>
                    </section>

                    <!-- Details -->
                    <aside
                        v-if="showDetails && conversation"
                        class="hidden w-64 shrink-0 flex-col overflow-y-auto border-l border-gray-200 p-4 xl:flex dark:border-gray-700"
                    >
                        <div class="flex flex-col items-center text-center">
                            <ChatAvatar
                                :name="conversation.title"
                                :initials="conversation.initials"
                                :src="conversation.avatar_url"
                                :group="conversation.is_group"
                                size="lg"
                            />
                            <p class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">{{ conversation.title }}</p>
                            <p class="text-xs text-gray-400">{{ subtitle }}</p>

                            <button
                                v-if="conversation.is_group"
                                type="button"
                                class="mt-3 w-full rounded-lg border border-gray-200 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700"
                                @click="showSettings = true"
                            >
                                Group settings
                            </button>
                        </div>

                        <div class="mt-5">
                            <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                                Members ({{ conversation.participants.length }})
                            </h4>
                            <ul class="space-y-1.5">
                                <li v-for="participant in conversation.participants" :key="participant.id" class="flex items-center gap-2">
                                    <ChatAvatar
                                        :name="participant.name"
                                        :initials="participant.initials"
                                        size="xs"
                                        :online="talk.isOnline(participant.id)"
                                        show-presence
                                    />
                                    <span class="min-w-0 flex-1 truncate text-xs text-gray-600 dark:text-gray-300">
                                        {{ participant.name }}
                                    </span>
                                    <span
                                        v-if="participant.role === 'admin' && conversation.is_group"
                                        class="shrink-0 text-[9px] font-semibold uppercase text-amber-600 dark:text-amber-400"
                                    >
                                        admin
                                    </span>
                                </li>
                            </ul>
                        </div>

                        <div v-if="sharedFiles.length" class="mt-5">
                            <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                                Recent files
                            </h4>
                            <ul class="space-y-1">
                                <li v-for="file in sharedFiles" :key="file.id">
                                    <a
                                        :href="file.download_url"
                                        class="block truncate rounded px-1.5 py-1 text-xs text-indigo-600 transition hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-indigo-900/30"
                                    >
                                        {{ file.is_image ? '📷' : '📎' }} {{ file.name }}
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </aside>
                </div>
            </div>
        </div>

        <NewConversationModal :show="showNew" :mode="newMode" @close="showNew = false" @created="onCreated" />
        <GroupSettingsModal
            :show="showSettings"
            :conversation-id="activeId"
            @close="showSettings = false"
            @left="onLeftGroup"
        />
    </AuthenticatedLayout>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { MessageCircle, Maximize2, PenSquare, Users, Volume2, VolumeX, X } from 'lucide-vue-next';
import ChatWindow from './ChatWindow.vue';
import ConversationList from './ConversationList.vue';
import NewConversationModal from './NewConversationModal.vue';
import { useCliftoTalk } from '@/composables/useCliftoTalk';

/**
 * The always-on chat surface. Mounted once in AuthenticatedLayout, so it
 * survives Inertia page changes and keeps its websocket subscriptions.
 */

const page = usePage();
const talk = useCliftoTalk();

const showList = ref(false);
const showNew = ref(false);
const newMode = ref('direct');

const enabled = computed(() => Boolean(page.props.cliftoTalk?.enabled));
const user = computed(() => page.props.auth?.user);
const unread = computed(() => talk.unreadTotal.value);
const soundOn = computed(() => talk.state.soundEnabled);
const brand = computed(() => page.props.cliftoTalk?.brand || 'Clifto Talk');

/** Server-published, so remounting the module elsewhere needs no JS change. */
const fullPageUrl = computed(() => `/${page.props.cliftoTalk?.prefix || 'clifto-talk'}`);

/** The full page renders its own thread, so the dock hides there. */
const onFullPage = computed(() => (page.url || '').split('?')[0] === fullPageUrl.value);

const openWindows = computed(() => talk.dockWindows.value);

onMounted(() => {
    if (!enabled.value || !user.value) return;

    talk.boot(user.value.id, {
        currentUserName: user.value.name,
        routePrefix: page.props.cliftoTalk?.prefix,
        conversations: null,
    });
});

// A user can log in/out within a session; boot when the props appear.
watch(
    () => user.value?.id,
    (id) => {
        if (id && enabled.value) {
            talk.boot(id, {
                currentUserName: user.value.name,
                routePrefix: page.props.cliftoTalk?.prefix,
            });
        }
    },
);

async function openConversation(id) {
    showList.value = false;
    await talk.openConversation(id, { dock: true });
}

function openNew(mode) {
    newMode.value = mode;
    showNew.value = true;
    showList.value = false;
}

async function onCreated(conversation) {
    if (conversation) await openConversation(conversation.id);
}

function toggleSound() {
    talk.setSoundEnabled(!soundOn.value);
    talk.requestDesktopPermission();
}
</script>

<template>
    <div v-if="enabled && user && !onFullPage" class="pointer-events-none fixed bottom-20 right-0 z-[60] flex items-end gap-3 p-4 print:hidden">
        <!-- Open chat windows -->
        <ChatWindow
            v-for="id in openWindows"
            :key="id"
            :conversation-id="id"
            @close="talk.closeDockWindow(id)"
        />

        <!-- Conversation launcher -->
        <div class="pointer-events-auto flex flex-col items-end gap-2">
            <div
                v-if="showList"
                class="flex h-[27rem] w-[20rem] flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-800"
            >
                <div class="flex shrink-0 items-center justify-between px-3 pb-2 pt-3">
                    <div>
                        <h2 class="text-sm font-bold text-gray-900 dark:text-white">{{ brand }}</h2>
                        <p class="text-[11px] text-gray-400">{{ unread ? `${unread} unread` : 'All caught up' }}</p>
                    </div>
                    <div class="flex items-center gap-0.5">
                        <button
                            type="button"
                            class="rounded p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700"
                            :title="soundOn ? 'Mute notification sound' : 'Unmute notification sound'"
                            @click="toggleSound"
                        >
                            <component :is="soundOn ? Volume2 : VolumeX" class="h-4 w-4" />
                        </button>
                        <Link
                            :href="fullPageUrl"
                            class="rounded p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700"
                            title="Open full view"
                        >
                            <Maximize2 class="h-4 w-4" />
                        </Link>
                        <button
                            type="button"
                            class="rounded p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700"
                            title="Close"
                            @click="showList = false"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                </div>

                <ConversationList class="flex-1" @select="openConversation" />

                <div class="grid shrink-0 grid-cols-2 gap-2 border-t border-gray-200 p-2 dark:border-gray-700">
                    <button
                        type="button"
                        class="flex items-center justify-center gap-1.5 rounded-lg bg-indigo-600 py-2 text-xs font-medium text-white transition hover:bg-indigo-700"
                        @click="openNew('direct')"
                    >
                        <PenSquare class="h-3.5 w-3.5" /> New chat
                    </button>
                    <button
                        type="button"
                        class="flex items-center justify-center gap-1.5 rounded-lg border border-gray-200 py-2 text-xs font-medium text-gray-600 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700"
                        @click="openNew('group')"
                    >
                        <Users class="h-3.5 w-3.5" /> New group
                    </button>
                </div>
            </div>

            <!-- Floating launcher button -->
            <button
                type="button"
                class="relative flex h-14 w-14 items-center justify-center rounded-full bg-indigo-600 text-white shadow-lg transition hover:bg-indigo-700 hover:shadow-xl focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:focus:ring-indigo-800"
                :title="`${brand}${unread ? ` — ${unread} unread` : ''}`"
                @click="showList = !showList"
            >
                <MessageCircle class="h-6 w-6" />
                <span
                    v-if="unread > 0"
                    class="absolute -right-0.5 -top-0.5 flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold ring-2 ring-white dark:ring-gray-900"
                >
                    {{ unread > 99 ? '99+' : unread }}
                </span>
            </button>
        </div>

        <NewConversationModal :show="showNew" :mode="newMode" @close="showNew = false" @created="onCreated" />
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { BellOff, Bell, Maximize2, Minus, Pin, PinOff, Settings2, Users, X } from 'lucide-vue-next';
import ChatAvatar from './ChatAvatar.vue';
import MessageThread from './MessageThread.vue';
import GroupSettingsModal from './GroupSettingsModal.vue';
import { useCliftoTalk } from '@/composables/useCliftoTalk';

const props = defineProps({
    conversationId: { type: Number, required: true },
});

const emit = defineEmits(['close']);

const page = usePage();
const talk = useCliftoTalk();
const showSettings = ref(false);
const showMenu = ref(false);

const conversation = computed(() => talk.conversation(props.conversationId));
const fullViewUrl = computed(
    () => `/${page.props.cliftoTalk?.prefix || 'clifto-talk'}?conversation=${props.conversationId}`,
);
const minimized = computed(() => talk.isMinimized(props.conversationId));

const online = computed(
    () => !conversation.value?.is_group && talk.isOnline(conversation.value?.counterpart_id),
);

const subtitle = computed(() => {
    if (!conversation.value) return '';
    if (conversation.value.is_group) {
        return `${conversation.value.participants.length} members`;
    }
    return online.value ? 'Active now' : 'Offline';
});

const unread = computed(() => conversation.value?.unread_count || 0);

function togglePin() {
    showMenu.value = false;
    talk.setPreferences(props.conversationId, { is_pinned: !conversation.value.is_pinned });
}

function toggleMute() {
    showMenu.value = false;
    talk.setPreferences(props.conversationId, { muted: !conversation.value.is_muted });
}
</script>

<template>
    <div
        v-if="conversation"
        class="pointer-events-auto flex w-[21rem] flex-col overflow-hidden rounded-t-xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-800"
        :class="minimized ? 'h-auto' : 'h-[27rem]'"
    >
        <!-- Header -->
        <div
            class="flex shrink-0 cursor-pointer items-center gap-2 border-b border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-700/50"
            @click="talk.toggleMinimized(conversationId)"
        >
            <ChatAvatar
                :name="conversation.title"
                :initials="conversation.initials"
                :src="conversation.avatar_url"
                :group="conversation.is_group"
                :online="online"
                :show-presence="!conversation.is_group"
                size="sm"
            />

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">
                    {{ conversation.title }}
                </p>
                <p class="truncate text-[11px] text-gray-500 dark:text-gray-400">{{ subtitle }}</p>
            </div>

            <span
                v-if="minimized && unread > 0"
                class="rounded-full bg-indigo-600 px-1.5 py-0.5 text-[10px] font-semibold text-white"
            >
                {{ unread > 99 ? '99+' : unread }}
            </span>

            <div class="relative flex shrink-0 items-center gap-0.5" @click.stop>
                <button
                    type="button"
                    class="rounded p-1 text-gray-400 transition hover:bg-gray-200 hover:text-gray-600 dark:hover:bg-gray-600 dark:hover:text-gray-200"
                    title="More"
                    @click="showMenu = !showMenu"
                >
                    <Settings2 class="h-4 w-4" />
                </button>
                <button
                    type="button"
                    class="rounded p-1 text-gray-400 transition hover:bg-gray-200 hover:text-gray-600 dark:hover:bg-gray-600 dark:hover:text-gray-200"
                    :title="minimized ? 'Expand' : 'Minimise'"
                    @click="talk.toggleMinimized(conversationId)"
                >
                    <Minus class="h-4 w-4" />
                </button>
                <button
                    type="button"
                    class="rounded p-1 text-gray-400 transition hover:bg-gray-200 hover:text-red-500 dark:hover:bg-gray-600"
                    title="Close"
                    @click="emit('close')"
                >
                    <X class="h-4 w-4" />
                </button>

                <div
                    v-if="showMenu"
                    class="absolute right-0 top-full z-30 mt-1 w-44 overflow-hidden rounded-lg border border-gray-200 bg-white py-1 text-xs shadow-lg dark:border-gray-700 dark:bg-gray-800"
                >
                    <Link
                        :href="fullViewUrl"
                        class="flex w-full items-center gap-2 px-3 py-2 text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700"
                        @click="showMenu = false"
                    >
                        <Maximize2 class="h-3.5 w-3.5" /> Open full view
                    </Link>
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700"
                        @click="togglePin"
                    >
                        <component :is="conversation.is_pinned ? PinOff : Pin" class="h-3.5 w-3.5" />
                        {{ conversation.is_pinned ? 'Unpin' : 'Pin to top' }}
                    </button>
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700"
                        @click="toggleMute"
                    >
                        <component :is="conversation.is_muted ? Bell : BellOff" class="h-3.5 w-3.5" />
                        {{ conversation.is_muted ? 'Unmute' : 'Mute' }}
                    </button>
                    <button
                        v-if="conversation.is_group"
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700"
                        @click="showMenu = false; showSettings = true"
                    >
                        <Users class="h-3.5 w-3.5" /> Group settings
                    </button>
                </div>
            </div>
        </div>

        <MessageThread v-if="!minimized" :conversation-id="conversationId" compact />

        <GroupSettingsModal
            :show="showSettings"
            :conversation-id="conversationId"
            @close="showSettings = false"
            @left="emit('close')"
        />
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { Crown, Loader2, LogOut, Plus, Search, Shield, Trash2, X } from 'lucide-vue-next';
import ChatAvatar from './ChatAvatar.vue';
import { useCliftoTalk } from '@/composables/useCliftoTalk';

const props = defineProps({
    show: { type: Boolean, default: false },
    conversationId: { type: Number, default: null },
});

const emit = defineEmits(['close', 'left']);

const talk = useCliftoTalk();

const name = ref('');
const avatar = ref(null);
const saving = ref(false);
const error = ref('');
const adding = ref(false);
const term = ref('');
const candidates = ref([]);
const searching = ref(false);

let searchTimer = null;

const conversation = computed(() => (props.conversationId ? talk.conversation(props.conversationId) : null));
const isAdmin = computed(() => conversation.value?.my_role === 'admin');
const currentUserId = computed(() => talk.currentUserId.value);

const memberIds = computed(() => new Set((conversation.value?.participants || []).map((p) => p.id)));
const available = computed(() => candidates.value.filter((u) => !memberIds.value.has(u.id)));

watch(
    () => props.show,
    (open) => {
        if (!open) return;
        name.value = conversation.value?.title || '';
        avatar.value = null;
        error.value = '';
        adding.value = false;
        term.value = '';
        candidates.value = [];
    },
);

watch(term, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(async () => {
        searching.value = true;
        try {
            candidates.value = await talk.searchUsers(term.value);
        } finally {
            searching.value = false;
        }
    }, 250);
});

watch(adding, (open) => {
    if (open && candidates.value.length === 0) {
        term.value = '';
        talk.searchUsers('').then((users) => {
            candidates.value = users;
        });
    }
});

async function saveDetails() {
    if (!isAdmin.value || !name.value.trim()) return;

    saving.value = true;
    error.value = '';
    try {
        await talk.renameGroup(props.conversationId, name.value.trim(), avatar.value);
        avatar.value = null;
    } catch (e) {
        error.value = e?.response?.data?.message || 'Could not save group details.';
    } finally {
        saving.value = false;
    }
}

async function addMember(user) {
    try {
        await talk.addParticipants(props.conversationId, [user.id]);
    } catch (e) {
        error.value = e?.response?.data?.message || 'Could not add that person.';
    }
}

async function removeMember(participant) {
    if (!window.confirm(`Remove ${participant.name} from this group?`)) return;

    try {
        await talk.removeParticipant(props.conversationId, participant.id);
    } catch (e) {
        error.value = e?.response?.data?.message || 'Could not remove that person.';
    }
}

async function toggleAdmin(participant) {
    try {
        await talk.setParticipantRole(
            props.conversationId,
            participant.id,
            participant.role === 'admin' ? 'member' : 'admin',
        );
    } catch (e) {
        error.value = e?.response?.data?.message || 'Could not change that role.';
    }
}

async function leaveGroup() {
    if (!window.confirm('Leave this group? You will stop receiving its messages.')) return;

    await talk.removeParticipant(props.conversationId, currentUserId.value);
    emit('left');
    emit('close');
}
</script>

<template>
    <Teleport to="body">
        <div v-if="show && conversation" class="fixed inset-0 z-[65] flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-gray-900/50" @click="emit('close')" />

            <div class="relative flex max-h-[85vh] w-full max-w-md flex-col overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-gray-800">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">Group settings</h2>
                    <button
                        type="button"
                        class="rounded-full p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700"
                        @click="emit('close')"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-4 py-4">
                    <div class="flex items-center gap-3">
                        <ChatAvatar
                            :name="conversation.title"
                            :initials="conversation.initials"
                            :src="conversation.avatar_url"
                            group
                            size="lg"
                        />
                        <div class="min-w-0 flex-1">
                            <input
                                v-model="name"
                                type="text"
                                maxlength="120"
                                :disabled="!isAdmin"
                                class="w-full rounded-lg border-gray-200 bg-gray-50 px-3 py-2 text-sm font-medium text-gray-900 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 disabled:opacity-70 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                            />
                            <label
                                v-if="isAdmin"
                                class="mt-1 inline-block cursor-pointer text-xs text-indigo-600 hover:underline dark:text-indigo-400"
                            >
                                {{ avatar ? avatar.name : 'Change group photo' }}
                                <input type="file" accept="image/*" class="hidden" @change="avatar = $event.target.files[0]" />
                            </label>
                        </div>
                    </div>

                    <button
                        v-if="isAdmin"
                        type="button"
                        class="mt-3 flex w-full items-center justify-center gap-2 rounded-lg bg-indigo-600 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 disabled:opacity-50"
                        :disabled="saving"
                        @click="saveDetails"
                    >
                        <Loader2 v-if="saving" class="h-4 w-4 animate-spin" />
                        Save changes
                    </button>

                    <p v-if="error" class="mt-2 text-xs text-red-500">{{ error }}</p>

                    <div class="mt-5 flex items-center justify-between">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400">
                            Members ({{ conversation.participants.length }})
                        </h3>
                        <button
                            type="button"
                            class="flex items-center gap-1 text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                            @click="adding = !adding"
                        >
                            <Plus class="h-3.5 w-3.5" /> Add people
                        </button>
                    </div>

                    <!-- Add member picker -->
                    <div v-if="adding" class="mt-2 rounded-lg border border-gray-200 p-2 dark:border-gray-600">
                        <div class="relative">
                            <Search class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                            <input
                                v-model="term"
                                type="text"
                                placeholder="Search people"
                                class="w-full rounded-lg border-gray-200 bg-gray-50 py-1.5 pl-8 pr-3 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                            />
                        </div>

                        <div class="mt-2 max-h-40 overflow-y-auto">
                            <div v-if="searching" class="flex justify-center py-3">
                                <Loader2 class="h-4 w-4 animate-spin text-gray-400" />
                            </div>
                            <p v-else-if="available.length === 0" class="py-3 text-center text-xs text-gray-400">
                                Everyone matching is already in this group.
                            </p>
                            <button
                                v-for="user in available"
                                :key="user.id"
                                type="button"
                                class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm transition hover:bg-gray-50 dark:hover:bg-gray-700"
                                @click="addMember(user)"
                            >
                                <ChatAvatar :name="user.name" :initials="user.initials" size="xs" />
                                <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-200">{{ user.name }}</span>
                                <Plus class="h-4 w-4 text-indigo-500" />
                            </button>
                        </div>
                    </div>

                    <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                        <li
                            v-for="participant in conversation.participants"
                            :key="participant.id"
                            class="flex items-center gap-3 py-2"
                        >
                            <ChatAvatar
                                :name="participant.name"
                                :initials="participant.initials"
                                size="sm"
                                :online="talk.isOnline(participant.id)"
                                show-presence
                            />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-gray-800 dark:text-gray-100">
                                    {{ participant.name }}
                                    <span v-if="participant.id === currentUserId" class="text-xs font-normal text-gray-400">(you)</span>
                                </p>
                                <p class="truncate text-xs text-gray-400">{{ participant.department || participant.email }}</p>
                            </div>

                            <span
                                v-if="participant.role === 'admin'"
                                class="flex shrink-0 items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"
                            >
                                <Crown class="h-3 w-3" /> Admin
                            </span>

                            <div v-if="isAdmin && participant.id !== currentUserId" class="flex shrink-0 gap-1">
                                <button
                                    type="button"
                                    class="rounded p-1 text-gray-400 transition hover:bg-gray-100 hover:text-amber-600 dark:hover:bg-gray-700"
                                    :title="participant.role === 'admin' ? 'Remove admin' : 'Make admin'"
                                    @click="toggleAdmin(participant)"
                                >
                                    <Shield class="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded p-1 text-gray-400 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30"
                                    title="Remove from group"
                                    @click="removeMember(participant)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </div>
                        </li>
                    </ul>
                </div>

                <div class="shrink-0 border-t border-gray-200 px-4 py-3 dark:border-gray-700">
                    <button
                        type="button"
                        class="flex w-full items-center justify-center gap-2 rounded-lg border border-red-200 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/30"
                        @click="leaveGroup"
                    >
                        <LogOut class="h-4 w-4" /> Leave group
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Check, Loader2, Search, Users, X } from 'lucide-vue-next';
import ChatAvatar from './ChatAvatar.vue';
import { useCliftoTalk } from '@/composables/useCliftoTalk';

const props = defineProps({
    show: { type: Boolean, default: false },
    /** Preselects group mode when opened from "New group". */
    mode: { type: String, default: 'direct' },
});

const emit = defineEmits(['close', 'created']);

const talk = useCliftoTalk();

const tab = ref(props.mode);
const term = ref('');
const users = ref([]);
const selected = ref([]);
const groupName = ref('');
const loading = ref(false);
const submitting = ref(false);
const error = ref('');

let searchTimer = null;

const canSubmit = computed(() => {
    if (submitting.value) return false;
    return tab.value === 'direct' ? selected.value.length === 1 : selected.value.length >= 1 && groupName.value.trim().length > 0;
});

async function fetchUsers() {
    loading.value = true;
    try {
        users.value = await talk.searchUsers(term.value);
    } finally {
        loading.value = false;
    }
}

watch(term, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(fetchUsers, 250);
});

watch(
    () => props.show,
    (open) => {
        if (!open) return;
        tab.value = props.mode;
        term.value = '';
        selected.value = [];
        groupName.value = '';
        error.value = '';
        fetchUsers();
    },
);

watch(tab, () => {
    // A 1:1 chat has exactly one counterpart; drop extras when switching back.
    if (tab.value === 'direct' && selected.value.length > 1) {
        selected.value = selected.value.slice(0, 1);
    }
});

onMounted(() => {
    if (props.show) fetchUsers();
});

function isSelected(user) {
    return selected.value.some((u) => u.id === user.id);
}

function toggle(user) {
    if (isSelected(user)) {
        selected.value = selected.value.filter((u) => u.id !== user.id);
        return;
    }

    if (tab.value === 'direct') {
        selected.value = [user];
        return;
    }

    selected.value.push(user);
}

async function submit() {
    if (!canSubmit.value) return;

    submitting.value = true;
    error.value = '';

    try {
        const conversation = tab.value === 'direct'
            ? await talk.startDirect(selected.value[0].id)
            : await talk.createGroup(groupName.value.trim(), selected.value.map((u) => u.id));

        emit('created', conversation);
        emit('close');
    } catch (e) {
        error.value = e?.response?.data?.message || 'Could not start the conversation.';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-[65] flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-gray-900/50" @click="emit('close')" />

            <div class="relative flex max-h-[85vh] w-full max-w-md flex-col overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-gray-800">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">
                        {{ tab === 'direct' ? 'New chat' : 'New group' }}
                    </h2>
                    <button
                        type="button"
                        class="rounded-full p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700"
                        @click="emit('close')"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div class="flex shrink-0 gap-1 border-b border-gray-200 px-4 pb-2 pt-2 dark:border-gray-700">
                    <button
                        v-for="option in [{ key: 'direct', label: 'Direct message' }, { key: 'group', label: 'Group' }]"
                        :key="option.key"
                        type="button"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
                        :class="tab === option.key
                            ? 'bg-indigo-600 text-white'
                            : 'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700'"
                        @click="tab = option.key"
                    >
                        {{ option.label }}
                    </button>
                </div>

                <div v-if="tab === 'group'" class="shrink-0 px-4 pt-3">
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Group name</label>
                    <input
                        v-model="groupName"
                        type="text"
                        maxlength="120"
                        placeholder="e.g. Yarn Store Coordination"
                        class="w-full rounded-lg border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                    />
                </div>

                <div class="shrink-0 px-4 pt-3">
                    <div class="relative">
                        <Search class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                        <input
                            v-model="term"
                            type="text"
                            placeholder="Search people by name or email"
                            class="w-full rounded-lg border-gray-200 bg-gray-50 py-2 pl-8 pr-3 text-sm text-gray-900 placeholder-gray-400 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                        />
                    </div>
                </div>

                <div v-if="selected.length" class="flex shrink-0 flex-wrap gap-1.5 px-4 pt-3">
                    <span
                        v-for="user in selected"
                        :key="user.id"
                        class="flex items-center gap-1 rounded-full bg-indigo-50 py-1 pl-1 pr-2 text-xs text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200"
                    >
                        <ChatAvatar :name="user.name" :initials="user.initials" size="xs" />
                        {{ user.name }}
                        <button type="button" class="rounded-full hover:text-indigo-900 dark:hover:text-white" @click="toggle(user)">
                            <X class="h-3 w-3" />
                        </button>
                    </span>
                </div>

                <div class="mt-2 min-h-0 flex-1 overflow-y-auto px-2 pb-2">
                    <div v-if="loading" class="flex justify-center py-6">
                        <Loader2 class="h-5 w-5 animate-spin text-gray-400" />
                    </div>

                    <p v-else-if="users.length === 0" class="py-6 text-center text-sm text-gray-400">
                        No people found.
                    </p>

                    <button
                        v-for="user in users"
                        :key="user.id"
                        type="button"
                        class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition hover:bg-gray-50 dark:hover:bg-gray-700"
                        @click="toggle(user)"
                    >
                        <ChatAvatar
                            :name="user.name"
                            :initials="user.initials"
                            size="sm"
                            :online="talk.isOnline(user.id)"
                            show-presence
                        />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-gray-800 dark:text-gray-100">{{ user.name }}</span>
                            <span class="block truncate text-xs text-gray-400">{{ user.department || user.email }}</span>
                        </span>
                        <span
                            class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border"
                            :class="isSelected(user)
                                ? 'border-indigo-600 bg-indigo-600 text-white'
                                : 'border-gray-300 dark:border-gray-500'"
                        >
                            <Check v-if="isSelected(user)" class="h-3 w-3" />
                        </span>
                    </button>
                </div>

                <div class="shrink-0 border-t border-gray-200 px-4 py-3 dark:border-gray-700">
                    <p v-if="error" class="mb-2 text-xs text-red-500">{{ error }}</p>
                    <button
                        type="button"
                        class="flex w-full items-center justify-center gap-2 rounded-lg bg-indigo-600 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="!canSubmit"
                        @click="submit"
                    >
                        <Loader2 v-if="submitting" class="h-4 w-4 animate-spin" />
                        <Users v-else-if="tab === 'group'" class="h-4 w-4" />
                        {{ tab === 'direct' ? 'Start chat' : `Create group${selected.length ? ` (${selected.length + 1})` : ''}` }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

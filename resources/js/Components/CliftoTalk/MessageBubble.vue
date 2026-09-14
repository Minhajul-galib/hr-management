<script setup>
import { computed, nextTick, ref } from 'vue';
import { CornerUpLeft, Download, FileText, MoreHorizontal, Pencil, RotateCw, SmilePlus, Trash2, X } from 'lucide-vue-next';
import ChatAvatar from './ChatAvatar.vue';
import EmojiPicker from './EmojiPicker.vue';
import { formatBytes, formatTime, renderBody, systemMessageText } from './format';

const props = defineProps({
    message: { type: Object, required: true },
    currentUserId: { type: Number, required: true },
    isGroup: { type: Boolean, default: false },
    /** False when the previous bubble is from the same person in the same minute. */
    showAuthor: { type: Boolean, default: true },
    canModerate: { type: Boolean, default: false },
    /** Names of people whose read watermark has passed this message. */
    seenBy: { type: Array, default: () => [] },
    /** True only for the newest message I sent, where ✓✓ belongs. */
    showReceipt: { type: Boolean, default: false },
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['reply', 'react', 'edit', 'delete', 'retry', 'discard', 'preview-image']);

const isMine = computed(() => props.message.user?.id === props.currentUserId);
const isSystem = computed(() => props.message.type === 'system');
const isPending = computed(() => Boolean(props.message.pending));
const isFailed = computed(() => Boolean(props.message.failed));

const showEmoji = ref(false);
const showMenu = ref(false);
const editing = ref(false);
const editText = ref('');
const editBox = ref(null);

const images = computed(() => (props.message.attachments || []).filter((a) => a.is_image));
const files = computed(() => (props.message.attachments || []).filter((a) => !a.is_image));

const bubbleClasses = computed(() => {
    if (props.message.is_deleted) {
        return 'bg-transparent border border-dashed border-gray-300 text-gray-400 dark:border-gray-600 dark:text-gray-500 italic';
    }
    if (isFailed.value) {
        return 'bg-red-50 text-red-700 border border-red-200 dark:bg-red-900/30 dark:text-red-200 dark:border-red-800';
    }
    return isMine.value
        ? 'bg-indigo-600 text-white'
        : 'bg-gray-100 text-gray-900 dark:bg-gray-700 dark:text-gray-100';
});

const canEdit = computed(() => {
    if (!isMine.value || props.message.is_deleted || isSystem.value || isPending.value) return false;
    // Mirrors MessagePolicy::update — a day-long edit window.
    return Date.now() - new Date(props.message.created_at).getTime() < 86400000;
});

const canDelete = computed(
    () => !props.message.is_deleted && !isSystem.value && !isPending.value && (isMine.value || props.canModerate),
);

const hasActions = computed(() => !isSystem.value && !props.message.is_deleted && !isPending.value && !isFailed.value);

async function startEdit() {
    showMenu.value = false;
    editing.value = true;
    editText.value = props.message.body || '';
    await nextTick();
    editBox.value?.focus();
}

function submitEdit() {
    const value = editText.value.trim();
    if (!value || value === props.message.body) {
        editing.value = false;
        return;
    }
    emit('edit', props.message, value);
    editing.value = false;
}

function react(emoji) {
    showEmoji.value = false;
    emit('react', props.message, emoji);
}

function myReaction(bucket) {
    return bucket.user_ids.includes(props.currentUserId);
}
</script>

<template>
    <!-- System announcements sit centred between bubbles. -->
    <div v-if="isSystem" class="my-2 flex justify-center px-4">
        <span class="rounded-full bg-gray-100 px-3 py-1 text-[11px] text-gray-500 dark:bg-gray-700/60 dark:text-gray-400">
            {{ systemMessageText(message) }}
        </span>
    </div>

    <div
        v-else
        class="group flex w-full gap-2 px-3"
        :class="[isMine ? 'flex-row-reverse' : 'flex-row', showAuthor ? 'mt-3' : 'mt-0.5']"
    >
        <!-- Avatar column: kept as a spacer on stacked messages so bubbles stay aligned. -->
        <div class="w-8 shrink-0" :class="isMine ? 'hidden' : ''">
            <ChatAvatar
                v-if="!isMine && showAuthor"
                :name="message.user?.name"
                :initials="message.user?.initials"
                size="sm"
            />
        </div>

        <div class="flex min-w-0 max-w-[78%] flex-col" :class="isMine ? 'items-end' : 'items-start'">
            <p
                v-if="isGroup && !isMine && showAuthor"
                class="mb-0.5 px-1 text-[11px] font-medium text-gray-500 dark:text-gray-400"
            >
                {{ message.user?.name }}
            </p>

            <div class="relative flex items-end gap-1" :class="isMine ? 'flex-row-reverse' : 'flex-row'">
                <div
                    class="relative rounded-2xl px-3 py-2 text-sm shadow-sm transition"
                    :class="[bubbleClasses, isPending ? 'opacity-60' : '']"
                >
                    <!-- Quoted parent -->
                    <button
                        v-if="message.parent"
                        type="button"
                        class="mb-1.5 flex w-full flex-col rounded-lg border-l-2 px-2 py-1 text-left text-[11px] leading-snug"
                        :class="isMine
                            ? 'border-white/50 bg-white/15 text-white/80'
                            : 'border-indigo-400 bg-black/5 text-gray-600 dark:bg-white/5 dark:text-gray-300'"
                    >
                        <span class="font-semibold">{{ message.parent.user_name || 'Unknown' }}</span>
                        <span class="line-clamp-2 opacity-90">
                            {{ message.parent.is_deleted
                                ? 'Deleted message'
                                : (message.parent.body || (message.parent.has_attachments ? '📎 Attachment' : '')) }}
                        </span>
                    </button>

                    <!-- Images -->
                    <div v-if="images.length" class="mb-1 grid gap-1" :class="images.length > 1 ? 'grid-cols-2' : 'grid-cols-1'">
                        <button
                            v-for="image in images"
                            :key="image.id"
                            type="button"
                            class="overflow-hidden rounded-lg"
                            @click="emit('preview-image', image)"
                        >
                            <img
                                :src="image.url"
                                :alt="image.name"
                                loading="lazy"
                                class="max-h-64 w-full cursor-zoom-in object-cover transition hover:opacity-90"
                            />
                        </button>
                    </div>

                    <!-- Non-image attachments -->
                    <a
                        v-for="file in files"
                        :key="file.id"
                        :href="file.download_url"
                        class="mb-1 flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs transition"
                        :class="isMine ? 'bg-white/15 hover:bg-white/25' : 'bg-black/5 hover:bg-black/10 dark:bg-white/10 dark:hover:bg-white/20'"
                    >
                        <FileText class="h-4 w-4 shrink-0" />
                        <span class="min-w-0 flex-1 truncate">{{ file.name }}</span>
                        <span class="shrink-0 opacity-70">{{ formatBytes(file.size) }}</span>
                        <Download class="h-3.5 w-3.5 shrink-0 opacity-70" />
                    </a>

                    <!-- Body -->
                    <div v-if="editing" class="min-w-[12rem]">
                        <textarea
                            ref="editBox"
                            v-model="editText"
                            rows="2"
                            class="w-full resize-none rounded-lg border-0 bg-white/90 p-2 text-sm text-gray-900 focus:ring-2 focus:ring-indigo-400 dark:bg-gray-800 dark:text-gray-100"
                            @keydown.enter.exact.prevent="submitEdit"
                            @keydown.esc.prevent="editing = false"
                        />
                        <div class="mt-1 flex justify-end gap-2 text-[11px]">
                            <button type="button" class="opacity-80 hover:opacity-100" @click="editing = false">Cancel</button>
                            <button type="button" class="font-semibold" @click="submitEdit">Save</button>
                        </div>
                    </div>

                    <p v-else-if="message.is_deleted" class="text-xs">This message was deleted</p>

                    <p
                        v-else-if="message.body"
                        class="whitespace-pre-wrap break-words"
                        v-html="renderBody(message.body)"
                    />

                    <!-- Meta line -->
                    <div
                        class="mt-0.5 flex items-center justify-end gap-1 text-[10px]"
                        :class="isMine && !message.is_deleted ? 'text-white/70' : 'text-gray-400 dark:text-gray-500'"
                    >
                        <span v-if="message.edited_at && !message.is_deleted">edited</span>
                        <span>{{ formatTime(message.created_at) }}</span>
                        <span v-if="isPending" title="Sending">🕐</span>
                        <span v-else-if="isMine && !message.is_deleted && showReceipt" :title="seenBy.length ? `Seen by ${seenBy.join(', ')}` : 'Delivered'">
                            {{ seenBy.length ? '✓✓' : '✓' }}
                        </span>
                    </div>
                </div>

                <!-- Hover actions -->
                <div
                    v-if="hasActions"
                    class="relative flex shrink-0 items-center gap-0.5 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100"
                >
                    <button
                        type="button"
                        class="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                        title="React"
                        @click="showEmoji = !showEmoji; showMenu = false"
                    >
                        <SmilePlus class="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        class="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                        title="Reply"
                        @click="emit('reply', message)"
                    >
                        <CornerUpLeft class="h-4 w-4" />
                    </button>
                    <button
                        v-if="canEdit || canDelete"
                        type="button"
                        class="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                        title="More"
                        @click="showMenu = !showMenu; showEmoji = false"
                    >
                        <MoreHorizontal class="h-4 w-4" />
                    </button>

                    <div v-if="showEmoji" class="absolute bottom-full z-30 mb-1" :class="isMine ? 'right-0' : 'left-0'">
                        <EmojiPicker reactions-only @select="react" />
                    </div>

                    <div
                        v-if="showMenu"
                        class="absolute bottom-full z-30 mb-1 w-36 overflow-hidden rounded-lg border border-gray-200 bg-white py-1 text-xs shadow-lg dark:border-gray-700 dark:bg-gray-800"
                        :class="isMine ? 'right-0' : 'left-0'"
                    >
                        <button
                            v-if="canEdit"
                            type="button"
                            class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700"
                            @click="startEdit"
                        >
                            <Pencil class="h-3.5 w-3.5" /> Edit
                        </button>
                        <button
                            v-if="canDelete"
                            type="button"
                            class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30"
                            @click="showMenu = false; emit('delete', message)"
                        >
                            <Trash2 class="h-3.5 w-3.5" /> Delete
                        </button>
                    </div>
                </div>
            </div>

            <!-- Reactions -->
            <div v-if="message.reactions?.length" class="mt-1 flex flex-wrap gap-1" :class="isMine ? 'justify-end' : ''">
                <button
                    v-for="bucket in message.reactions"
                    :key="bucket.emoji"
                    type="button"
                    class="flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-[11px] transition"
                    :class="myReaction(bucket)
                        ? 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-500 dark:bg-indigo-900/40 dark:text-indigo-200'
                        : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'"
                    @click="emit('react', message, bucket.emoji)"
                >
                    <span>{{ bucket.emoji }}</span>
                    <span class="font-medium">{{ bucket.count }}</span>
                </button>
            </div>

            <!-- Failed send -->
            <div v-if="isFailed" class="mt-1 flex items-center gap-2 text-[11px] text-red-600 dark:text-red-400">
                <span>{{ message.error || 'Not sent' }}</span>
                <button type="button" class="inline-flex items-center gap-1 font-medium underline" @click="emit('retry', message)">
                    <RotateCw class="h-3 w-3" /> Retry
                </button>
                <button type="button" class="inline-flex items-center gap-1 underline" @click="emit('discard', message)">
                    <X class="h-3 w-3" /> Discard
                </button>
            </div>
        </div>
    </div>
</template>

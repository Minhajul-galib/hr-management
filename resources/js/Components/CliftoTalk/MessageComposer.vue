<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Paperclip, SendHorizonal, Smile, X } from 'lucide-vue-next';
import EmojiPicker from './EmojiPicker.vue';
import { formatBytes } from './format';

const props = defineProps({
    modelValue: { type: String, default: '' },
    replyTo: { type: Object, default: null },
    disabled: { type: Boolean, default: false },
    placeholder: { type: String, default: 'Write a message…' },
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'send', 'typing', 'stopped-typing', 'cancel-reply']);

const input = ref(null);
const fileInput = ref(null);
const pendingFiles = ref([]);
const showEmoji = ref(false);
const dragging = ref(false);

const MAX_FILES = 10;
const MAX_BYTES = 20 * 1024 * 1024;
const fileError = ref('');

const canSend = computed(() => !props.disabled && (props.modelValue.trim().length > 0 || pendingFiles.value.length > 0));

/** Grow with the text, but stop before the composer eats the thread. */
function autoResize() {
    const el = input.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, props.compact ? 90 : 140)}px`;
}

watch(() => props.modelValue, () => nextTick(autoResize));

watch(
    () => props.replyTo,
    (value) => {
        if (value) nextTick(() => input.value?.focus());
    },
);

function onInput(event) {
    emit('update:modelValue', event.target.value);
    emit('typing');
    autoResize();
}

function addFiles(list) {
    fileError.value = '';
    const incoming = Array.from(list || []);

    for (const file of incoming) {
        if (pendingFiles.value.length >= MAX_FILES) {
            fileError.value = `Up to ${MAX_FILES} files per message.`;
            break;
        }
        if (file.size > MAX_BYTES) {
            fileError.value = `"${file.name}" is larger than 20 MB.`;
            continue;
        }
        pendingFiles.value.push(file);
    }
}

function removeFile(index) {
    pendingFiles.value.splice(index, 1);
}

function onDrop(event) {
    dragging.value = false;
    addFiles(event.dataTransfer?.files);
}

/** Screenshot pasted straight from the clipboard becomes an attachment. */
function onPaste(event) {
    const items = Array.from(event.clipboardData?.items || []);
    const files = items.filter((i) => i.kind === 'file').map((i) => i.getAsFile()).filter(Boolean);

    if (files.length) {
        event.preventDefault();
        addFiles(files);
    }
}

function insertEmoji(emoji) {
    const el = input.value;
    const value = props.modelValue || '';

    if (!el) {
        emit('update:modelValue', value + emoji);
    } else {
        const start = el.selectionStart ?? value.length;
        const end = el.selectionEnd ?? value.length;
        emit('update:modelValue', value.slice(0, start) + emoji + value.slice(end));
        nextTick(() => {
            el.focus();
            el.selectionStart = el.selectionEnd = start + emoji.length;
        });
    }

    showEmoji.value = false;
}

function submit() {
    if (!canSend.value) return;

    emit('send', { body: props.modelValue, files: [...pendingFiles.value] });

    pendingFiles.value = [];
    fileError.value = '';
    emit('stopped-typing');
    nextTick(() => {
        autoResize();
        input.value?.focus();
    });
}

function onKeydown(event) {
    // Enter sends; Shift+Enter adds a line — the messenger convention.
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        submit();
    }
}

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div
        class="border-t border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800"
        :class="dragging ? 'ring-2 ring-inset ring-indigo-400' : ''"
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop.prevent="onDrop"
    >
        <!-- Reply preview -->
        <div
            v-if="replyTo"
            class="flex items-center gap-2 border-b border-gray-100 bg-gray-50 px-3 py-1.5 text-xs dark:border-gray-700 dark:bg-gray-700/40"
        >
            <div class="h-8 w-0.5 shrink-0 rounded bg-indigo-500" />
            <div class="min-w-0 flex-1">
                <p class="font-medium text-indigo-600 dark:text-indigo-400">
                    Replying to {{ replyTo.user?.name || 'message' }}
                </p>
                <p class="truncate text-gray-500 dark:text-gray-400">
                    {{ replyTo.body || (replyTo.attachments?.length ? '📎 Attachment' : '') }}
                </p>
            </div>
            <button
                type="button"
                class="rounded p-1 text-gray-400 hover:bg-gray-200 hover:text-gray-600 dark:hover:bg-gray-600"
                @click="emit('cancel-reply')"
            >
                <X class="h-3.5 w-3.5" />
            </button>
        </div>

        <!-- Staged attachments -->
        <div v-if="pendingFiles.length" class="flex flex-wrap gap-2 px-3 pt-2">
            <div
                v-for="(file, index) in pendingFiles"
                :key="`${file.name}-${index}`"
                class="flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 py-1 pl-2 pr-1 text-xs dark:border-gray-600 dark:bg-gray-700"
            >
                <span class="max-w-[10rem] truncate text-gray-700 dark:text-gray-200">{{ file.name }}</span>
                <span class="text-gray-400">{{ formatBytes(file.size) }}</span>
                <button
                    type="button"
                    class="rounded p-0.5 text-gray-400 hover:bg-gray-200 hover:text-red-500 dark:hover:bg-gray-600"
                    @click="removeFile(index)"
                >
                    <X class="h-3 w-3" />
                </button>
            </div>
        </div>

        <p v-if="fileError" class="px-3 pt-1 text-[11px] text-red-500">{{ fileError }}</p>

        <div class="flex items-end gap-1 px-2 py-2">
            <button
                type="button"
                class="rounded-full p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                title="Attach files"
                :disabled="disabled"
                @click="fileInput?.click()"
            >
                <Paperclip class="h-5 w-5" />
            </button>
            <input ref="fileInput" type="file" multiple class="hidden" @change="addFiles($event.target.files); $event.target.value = ''" />

            <div class="relative">
                <button
                    type="button"
                    class="rounded-full p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                    title="Emoji"
                    :disabled="disabled"
                    @click="showEmoji = !showEmoji"
                >
                    <Smile class="h-5 w-5" />
                </button>
                <div v-if="showEmoji" class="absolute bottom-full left-0 z-30 mb-2">
                    <EmojiPicker @select="insertEmoji" />
                </div>
            </div>

            <textarea
                ref="input"
                :value="modelValue"
                :placeholder="placeholder"
                :disabled="disabled"
                rows="1"
                class="max-h-36 min-h-[2.5rem] flex-1 resize-none rounded-2xl border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 disabled:opacity-60 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:placeholder-gray-500"
                @input="onInput"
                @keydown="onKeydown"
                @paste="onPaste"
                @blur="emit('stopped-typing')"
            />

            <button
                type="button"
                class="rounded-full p-2 transition"
                :class="canSend
                    ? 'bg-indigo-600 text-white hover:bg-indigo-700'
                    : 'text-gray-300 dark:text-gray-600'"
                :disabled="!canSend"
                title="Send"
                @click="submit"
            >
                <SendHorizonal class="h-5 w-5" />
            </button>
        </div>
    </div>
</template>

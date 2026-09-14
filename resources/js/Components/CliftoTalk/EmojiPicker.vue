<script setup>
defineProps({
    /** Compact mode shows only the quick-reaction row. */
    reactionsOnly: { type: Boolean, default: false },
});

const emit = defineEmits(['select']);

/** The row that appears on message hover. */
const quick = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

const groups = [
    { name: 'Reactions', emojis: ['👍', '👎', '❤️', '🔥', '🎉', '👏', '🙏', '💯', '✅', '❌', '⚠️', '👀'] },
    { name: 'Smileys', emojis: ['😀', '😃', '😄', '😁', '😅', '😂', '🙂', '😉', '😊', '😍', '😘', '😜', '🤔', '😐', '😴', '😎', '😢', '😭', '😡', '🤯', '🥳', '🤝'] },
    { name: 'Work', emojis: ['📌', '📎', '📅', '📈', '📉', '📊', '🧵', '🧶', '🚚', '🏭', '📦', '🕐', '💰', '📝', '🔍', '⚡'] },
];
</script>

<template>
    <div
        class="rounded-xl border border-gray-200 bg-white p-2 shadow-lg dark:border-gray-700 dark:bg-gray-800"
        :class="reactionsOnly ? '' : 'w-64'"
    >
        <div v-if="reactionsOnly" class="flex items-center gap-0.5">
            <button
                v-for="emoji in quick"
                :key="emoji"
                type="button"
                class="rounded-full p-1 text-lg leading-none transition hover:scale-125 hover:bg-gray-100 dark:hover:bg-gray-700"
                @click="emit('select', emoji)"
            >
                {{ emoji }}
            </button>
        </div>

        <div v-else class="max-h-64 space-y-2 overflow-y-auto">
            <div v-for="group in groups" :key="group.name">
                <p class="px-1 pb-1 text-[10px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                    {{ group.name }}
                </p>
                <div class="grid grid-cols-8 gap-0.5">
                    <button
                        v-for="emoji in group.emojis"
                        :key="emoji"
                        type="button"
                        class="rounded p-1 text-lg leading-none transition hover:bg-gray-100 dark:hover:bg-gray-700"
                        @click="emit('select', emoji)"
                    >
                        {{ emoji }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

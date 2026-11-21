<script setup>
/** Footer for a server-paged table: "Showing x–y of z", rows per page, page links. */
import { computed } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

const props = defineProps({
    pagination: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['page', 'per-page']);

const current = computed(() => props.pagination.current_page);
const last = computed(() => props.pagination.last_page);

// First, last, and two either side of the current page, with gaps marked.
const pages = computed(() => {
    const wanted = [1, last.value, ...[-2, -1, 0, 1, 2].map((d) => current.value + d)];
    const list = [...new Set(wanted)].filter((p) => p >= 1 && p <= last.value).sort((a, b) => a - b);

    const out = [];
    list.forEach((page, i) => {
        if (i > 0 && page - list[i - 1] > 1) out.push({ gap: true, key: `gap-${page}` });
        out.push({ page, key: page });
    });
    return out;
});

const go = (page) => {
    if (!props.disabled && page >= 1 && page <= last.value && page !== current.value) emit('page', page);
};

const number = (n) => Number(n ?? 0).toLocaleString();

const buttonClass =
    'inline-flex h-8 min-w-8 items-center justify-center rounded-md px-2 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40';
</script>

<template>
    <div class="flex flex-col items-center justify-between gap-3 border-t border-gray-200 px-1 pt-4 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-400 sm:flex-row">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <span v-if="pagination.total">
                Showing <b class="text-gray-900 dark:text-gray-100">{{ number(pagination.from) }}–{{ number(pagination.to) }}</b>
                of <b class="text-gray-900 dark:text-gray-100">{{ number(pagination.total) }}</b>
            </span>
            <label class="flex items-center gap-2">
                Rows per page
                <select
                    :value="pagination.per_page"
                    :disabled="disabled"
                    class="rounded-md border-gray-300 py-1 pl-2 pr-8 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                    @change="emit('per-page', Number($event.target.value))"
                >
                    <option v-for="size in pagination.per_page_options" :key="size" :value="size">{{ size }}</option>
                </select>
            </label>
        </div>

        <nav v-if="last > 1" class="flex items-center gap-1" aria-label="Pagination">
            <button
                type="button"
                :class="[buttonClass, 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700']"
                :disabled="disabled || current === 1"
                aria-label="Previous page"
                @click="go(current - 1)"
            >
                <ChevronLeft :size="16" />
            </button>

            <template v-for="item in pages" :key="item.key">
                <span v-if="item.gap" class="px-1 text-gray-400">…</span>
                <button
                    v-else
                    type="button"
                    :class="[
                        buttonClass,
                        item.page === current
                            ? 'bg-indigo-600 text-white'
                            : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700',
                    ]"
                    :aria-current="item.page === current ? 'page' : undefined"
                    :disabled="disabled"
                    @click="go(item.page)"
                >
                    {{ number(item.page) }}
                </button>
            </template>

            <button
                type="button"
                :class="[buttonClass, 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700']"
                :disabled="disabled || current === last"
                aria-label="Next page"
                @click="go(current + 1)"
            >
                <ChevronRight :size="16" />
            </button>
        </nav>
    </div>
</template>

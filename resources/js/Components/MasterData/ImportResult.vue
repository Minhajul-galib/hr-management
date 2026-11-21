<script setup>
/** What the last Excel import did: counts, and the rows that need fixing. */
import { computed } from 'vue';
import { AlertTriangle, CheckCircle2, FileSpreadsheet, RefreshCw, X } from 'lucide-vue-next';

const props = defineProps({
    result: { type: Object, required: true },
    matchLabel: { type: String, default: 'Code' },
    plural: { type: String, default: 'records' },
});

defineEmits(['dismiss']);

const saved = computed(() => props.result.created_count + props.result.updated_count);
const failed = computed(() => props.result.fatal || (props.result.total && !saved.value && props.result.error_count));
const hiddenErrors = computed(() => Math.max(0, props.result.error_count - props.result.errors.length));
const number = (n) => Number(n ?? 0).toLocaleString();
</script>

<template>
    <section
        :class="[
            'rounded-lg border-l-4 bg-white p-5 shadow-sm dark:bg-gray-800',
            failed ? 'border-red-500' : result.error_count ? 'border-amber-500' : 'border-green-500',
        ]"
        aria-live="polite"
    >
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <h3 class="flex items-center gap-2 font-semibold text-gray-900 dark:text-gray-100">
                    <FileSpreadsheet :size="18" class="shrink-0 text-gray-400" />
                    <span class="truncate">Import result — {{ result.file }}</span>
                </h3>

                <p v-if="result.fatal" class="mt-2 text-sm text-red-700 dark:text-red-400">{{ result.fatal }}</p>
                <p v-else-if="!result.total" class="mt-2 text-sm text-gray-600 dark:text-gray-400">The file has no {{ plural }} to import.</p>
                <div v-else class="mt-2 flex flex-wrap gap-2 text-sm">
                    <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-0.5 font-medium text-green-800 dark:bg-green-900/40 dark:text-green-300">
                        <CheckCircle2 :size="14" /> {{ number(result.created_count) }} added
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2.5 py-0.5 font-medium text-blue-800 dark:bg-blue-900/40 dark:text-blue-300">
                        <RefreshCw :size="14" /> {{ number(result.updated_count) }} updated
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-0.5 font-medium text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                        {{ number(result.unchanged_count) }} unchanged
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-0.5 font-medium text-red-800 dark:bg-red-900/40 dark:text-red-300">
                        <AlertTriangle :size="14" /> {{ number(result.error_count) }} need attention
                    </span>
                    <span
                        v-if="result.sample_count"
                        class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300"
                    >
                        {{ number(result.sample_count) }} sample {{ result.sample_count === 1 ? 'row' : 'rows' }} skipped
                    </span>
                    <span class="self-center text-gray-500 dark:text-gray-400">of {{ number(result.total) }} rows</span>
                </div>
            </div>
            <button
                type="button"
                class="shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                aria-label="Dismiss import result"
                @click="$emit('dismiss')"
            >
                <X :size="18" />
            </button>
        </div>

        <div v-if="result.errors.length" class="mt-4">
            <h4 class="text-sm font-medium text-red-700 dark:text-red-400">Skipped rows — fix them in the file and upload it again</h4>
            <div class="mt-2 max-h-72 overflow-y-auto rounded-md border border-gray-200 dark:border-gray-700">
                <table class="min-w-full text-sm">
                    <thead class="sticky top-0 bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                        <tr>
                            <th class="w-20 px-3 py-2 font-medium">Row</th>
                            <th class="w-32 px-3 py-2 font-medium">{{ matchLabel }}</th>
                            <th class="px-3 py-2 font-medium">Problem</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="item in result.errors" :key="item.row">
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ item.row }}</td>
                            <td class="px-3 py-2 font-medium text-gray-900 dark:text-gray-100">{{ item.key || '—' }}</td>
                            <td class="px-3 py-2 text-gray-800 dark:text-gray-200">{{ item.message }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="hiddenErrors" class="mt-1 text-xs text-gray-500 dark:text-gray-400">…and {{ number(hiddenErrors) }} more.</p>
        </div>
    </section>
</template>

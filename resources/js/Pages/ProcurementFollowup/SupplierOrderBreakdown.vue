<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Users, ClipboardList, TrendingUp } from 'lucide-vue-next';

const props = defineProps({
    breakdown: { type: Array, default: () => [] },
});

const fmtNum = (n) => {
    if (n === null || n === undefined || n === '') return '-';
    const x = Number(n);
    return Number.isNaN(x) ? '-' : x.toLocaleString(undefined, { maximumFractionDigits: 3 });
};

const fmtPct = (n) => {
    if (n === null || n === undefined || n === '') return '-';
    const x = Number(n);
    return Number.isNaN(x) ? '-' : x.toFixed(1) + '%';
};

const statusClass = (s) => {
    if (s === 'Completed') return 'bg-emerald-100 border-emerald-200 text-emerald-800 dark:bg-emerald-900/30 dark:border-emerald-900/60 dark:text-emerald-200';
    if (s === 'Partial') return 'bg-amber-100 border-amber-200 text-amber-800 dark:bg-amber-900/30 dark:border-amber-900/60 dark:text-amber-200';
    return 'bg-gray-100 border-gray-200 text-gray-800 dark:bg-gray-900/30 dark:border-gray-700 dark:text-gray-200';
};

const rows = computed(() => props.breakdown ?? []);
</script>

<template>
    <Head title="Supplier Order Breakdown" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <div class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600 text-white">
                        <Users :size="20" />
                    </div>
                    <div>
                        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                            Supplier Order Breakdown
                        </h2>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Supplier-wise ordered vs received, fulfilment, and lead time review.
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Link
                        :href="route('procurement-followup.index')"
                        class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 shadow-sm hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-100 dark:border-gray-600 dark:hover:bg-gray-700"
                    >
                        <ClipboardList :size="18" /> Procurement Follow Up
                    </Link>
                    <Link
                        :href="route('procurement-followup.pi-wise-analysis')"
                        class="inline-flex items-center gap-2 rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-700"
                    >
                        <TrendingUp :size="18" /> PI Research & Analysis
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-4 xl:max-w-[1600px] space-y-5">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                                Supplier Summary
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                Rows: {{ rows.length }}
                            </div>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-gray-300">Supplier</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-gray-300">Ordered (kg)</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-gray-300">Received (kg)</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-gray-300">Balance (kg)</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-gray-300">Fulfilment</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-gray-300">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-if="rows.length === 0">
                                    <td colspan="6" class="px-6 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                                        No data found.
                                    </td>
                                </tr>
                                <tr v-for="r in rows" :key="r.supplier" class="hover:bg-gray-50 dark:hover:bg-gray-900/30">
                                    <td class="px-6 py-4 text-sm font-semibold text-gray-800 dark:text-gray-100">
                                        {{ r.supplier }}
                                    </td>
                                    <td class="px-6 py-4 text-right text-sm text-gray-700 dark:text-gray-200">
                                        {{ fmtNum(r.ordered_kg) }}
                                    </td>
                                    <td class="px-6 py-4 text-right text-sm text-gray-700 dark:text-gray-200">
                                        {{ fmtNum(r.received_kg) }}
                                    </td>
                                    <td class="px-6 py-4 text-right text-sm text-gray-700 dark:text-gray-200">
                                        {{ fmtNum(r.balance_kg) }}
                                    </td>
                                    <td class="px-6 py-4 text-right text-sm text-gray-700 dark:text-gray-200">
                                        <div class="flex flex-col items-end gap-1">
                                            <div class="h-2 w-28 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                                <div
                                                    class="h-full rounded-full bg-indigo-600"
                                                    :style="{ width: `${Math.max(0, Math.min(100, Number(r.fulfilment_pct ?? 0)))}%` }"
                                                />
                                            </div>
                                            <div class="text-xs font-semibold tabular-nums text-gray-700 dark:text-gray-200">
                                                {{ fmtPct(r.fulfilment_pct) }}
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold" :class="statusClass(r.status)">
                                            {{ r.status }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

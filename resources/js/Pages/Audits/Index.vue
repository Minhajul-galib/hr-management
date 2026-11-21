<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { ClipboardCheck, FileText, AlertTriangle, Clock, Eye, Pencil, Printer, Trash2, CopyPlus, Plus, Search, X } from 'lucide-vue-next';
import { useSweetAlert } from '@/composables/useSweetAlert';
import { ratingClasses, ratingShort, resultClasses, statusClasses, formatDate, useAuditPermissions } from './Composables/useAuditMeta';

const props = defineProps({
    audits: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    units: { type: Array, default: () => [] },
    template: { type: Object, required: true },
});

const { canManage } = useAuditPermissions();

const search = ref('');
const unitFilter = ref('');
const statusFilter = ref('');
const resultFilter = ref('');

const filteredAudits = computed(() => {
    const words = search.value.toLowerCase().split(/\s+/).filter(Boolean);

    return props.audits.filter((audit) => {
        if (unitFilter.value && audit.unit_id !== Number(unitFilter.value)) return false;
        if (statusFilter.value && audit.status !== statusFilter.value) return false;
        if (resultFilter.value && audit.overall_result !== resultFilter.value) return false;
        if (!words.length) return true;

        const haystack = [
            audit.reference_no,
            audit.facility_name,
            audit.assessment_standard,
            ...(audit.auditors || []),
        ].join(' ').toLowerCase();

        return words.every((word) => haystack.includes(word));
    });
});

const hasFilters = computed(() => search.value || unitFilter.value || statusFilter.value || resultFilter.value);

const clearFilters = () => {
    search.value = '';
    unitFilter.value = '';
    statusFilter.value = '';
    resultFilter.value = '';
};

const ratingCounts = (audit) =>
    Object.keys(ratingShort)
        .map((key) => ({ key, label: ratingShort[key], count: audit[`${key}_count`] }))
        .filter((r) => r.count > 0);

const capProgress = (audit) => {
    const closed = audit.issues_count - audit.open_count;
    return {
        closed,
        percent: audit.issues_count ? Math.round((closed / audit.issues_count) * 100) : 100,
    };
};

const deleteAudit = async (audit) => {
    const { showDeleteConfirm } = useSweetAlert();
    const result = await showDeleteConfirm(`Delete draft audit <strong>${audit.reference_no}</strong> for ${audit.facility_name}?`);
    if (result.isConfirmed) {
        router.delete(route('audits.destroy', audit.id), { preserveScroll: true });
    }
};

const statCards = computed(() => [
    { label: 'Total Audits', value: props.stats.total, icon: ClipboardCheck, tone: 'text-indigo-600 bg-indigo-50 dark:bg-indigo-900/40 dark:text-indigo-300' },
    { label: 'Drafts', value: props.stats.drafts, icon: FileText, tone: 'text-gray-600 bg-gray-100 dark:bg-gray-700 dark:text-gray-300' },
    { label: 'Open CAPs', value: props.stats.open_caps, icon: Clock, tone: 'text-amber-600 bg-amber-50 dark:bg-amber-900/40 dark:text-amber-300' },
    {
        label: 'Overdue CAPs',
        value: props.stats.overdue_caps,
        icon: AlertTriangle,
        tone: props.stats.overdue_caps > 0
            ? 'text-red-600 bg-red-50 dark:bg-red-900/40 dark:text-red-300'
            : 'text-green-600 bg-green-50 dark:bg-green-900/40 dark:text-green-300',
    },
]);
</script>

<template>
    <Head title="Internal Audit" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2>Internal Audit Report &amp; CAP</h2>
                    <p>Social compliance audits of our factories and their corrective action plans.</p>
                </div>
                <Link
                    v-if="canManage"
                    :href="route('audits.create')"
                    class="inline-flex items-center gap-2 self-start rounded-md bg-indigo-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-indigo-500 sm:self-auto"
                >
                    <Plus :size="16" /> New Audit
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 xl:max-w-[1440px] 2xl:max-w-[1600px]">
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div
                        v-for="card in statCards"
                        :key="card.label"
                        class="flex items-center gap-4 rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800"
                    >
                        <div :class="['rounded-lg p-3', card.tone]">
                            <component :is="card.icon" :size="22" />
                        </div>
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ card.label }}</p>
                            <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ card.value }}</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-lg bg-white shadow-sm dark:bg-gray-800">
                    <div class="flex flex-col gap-3 border-b border-gray-200 p-4 dark:border-gray-700 lg:flex-row lg:items-center">
                        <div class="relative flex-1">
                            <Search :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Search reference, factory, auditor..."
                                class="w-full rounded-md border-gray-300 pl-9 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                            />
                        </div>
                        <select v-model="unitFilter" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 lg:w-56">
                            <option value="">All factories</option>
                            <option v-for="unit in units" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
                        </select>
                        <select v-model="statusFilter" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 lg:w-40">
                            <option value="">All statuses</option>
                            <option value="draft">Draft</option>
                            <option value="completed">Completed</option>
                        </select>
                        <select v-model="resultFilter" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 lg:w-52">
                            <option value="">All results</option>
                            <option v-for="(result, key) in template.results" :key="key" :value="key">{{ result.label }}</option>
                        </select>
                        <button
                            v-if="hasFilters"
                            type="button"
                            class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200"
                            @click="clearFilters"
                        >
                            <X :size="14" /> Clear
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900/50">
                                <tr class="text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                    <th class="px-4 py-3">Reference</th>
                                    <th class="px-4 py-3">Factory</th>
                                    <th class="px-4 py-3">Assessment</th>
                                    <th class="px-4 py-3">Findings</th>
                                    <th class="px-4 py-3">CAP Progress</th>
                                    <th class="px-4 py-3">Result</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="audit in filteredAudits" :key="audit.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <Link :href="route('audits.show', audit.id)" class="font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
                                            {{ audit.reference_no }}
                                        </Link>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ template.types[audit.audit_type] }}</p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ audit.facility_name }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ (audit.auditors || []).join(', ') }}</p>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-300">
                                        {{ formatDate(audit.assessment_date) }}
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ audit.assessment_standard }}</p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div v-if="ratingCounts(audit).length" class="flex flex-wrap gap-1">
                                            <span
                                                v-for="r in ratingCounts(audit)"
                                                :key="r.key"
                                                :class="['rounded border px-1.5 py-0.5 text-xs font-semibold', ratingClasses[r.key]]"
                                            >
                                                {{ r.label }} {{ r.count }}
                                            </span>
                                        </div>
                                        <span v-else class="text-xs text-green-700 dark:text-green-400">No non-compliance</span>
                                    </td>
                                    <td class="min-w-[140px] px-4 py-3">
                                        <template v-if="audit.issues_count">
                                            <div class="flex items-center justify-between text-xs text-gray-600 dark:text-gray-300">
                                                <span>{{ capProgress(audit).closed }}/{{ audit.issues_count }} closed</span>
                                                <span v-if="audit.overdue_count" class="font-semibold text-red-600 dark:text-red-400">{{ audit.overdue_count }} overdue</span>
                                            </div>
                                            <div class="mt-1 h-1.5 rounded-full bg-gray-200 dark:bg-gray-700">
                                                <div class="h-1.5 rounded-full bg-green-500" :style="{ width: `${capProgress(audit).percent}%` }"></div>
                                            </div>
                                        </template>
                                        <span v-else class="text-xs text-gray-400">—</span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <span
                                            v-if="audit.overall_result"
                                            :class="['rounded-full border px-2.5 py-0.5 text-xs font-semibold', resultClasses[audit.overall_result]]"
                                        >
                                            {{ template.results[audit.overall_result]?.label }}
                                        </span>
                                        <span v-else class="text-xs text-gray-400">Not rated</span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <span :class="['rounded-full border px-2.5 py-0.5 text-xs font-semibold capitalize', statusClasses[audit.status]]">
                                            {{ audit.status }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <div class="flex items-center justify-end gap-3 text-gray-500 dark:text-gray-400">
                                            <Link :href="route('audits.show', audit.id)" title="View" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                                                <Eye :size="17" />
                                            </Link>
                                            <a :href="route('audits.print', audit.id)" target="_blank" title="Print report" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                                                <Printer :size="17" />
                                            </a>
                                            <template v-if="canManage">
                                                <template v-if="audit.status === 'draft'">
                                                    <Link :href="route('audits.edit', audit.id)" title="Edit" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                                                        <Pencil :size="17" />
                                                    </Link>
                                                    <button type="button" title="Delete draft" class="hover:text-red-600 dark:hover:text-red-400" @click="deleteAudit(audit)">
                                                        <Trash2 :size="17" />
                                                    </button>
                                                </template>
                                                <Link
                                                    v-else
                                                    :href="route('audits.create', { from: audit.id })"
                                                    title="Start follow-up audit"
                                                    class="hover:text-indigo-600 dark:hover:text-indigo-400"
                                                >
                                                    <CopyPlus :size="17" />
                                                </Link>
                                            </template>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="filteredAudits.length === 0">
                                    <td colspan="8" class="px-4 py-12 text-center">
                                        <ClipboardCheck :size="36" class="mx-auto text-gray-300 dark:text-gray-600" />
                                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                            {{ audits.length ? 'No audits match your filters.' : 'No audits have been recorded yet.' }}
                                        </p>
                                        <Link
                                            v-if="!audits.length && canManage"
                                            :href="route('audits.create')"
                                            class="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400"
                                        >
                                            <Plus :size="15" /> Record the first audit
                                        </Link>
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

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    ArrowLeft, Printer, Pencil, RotateCcw, CopyPlus, Trash2, Building2, ClipboardList, Users, Layers,
    FileCheck2, Flame, ListChecks, Award, CheckCircle2, AlertTriangle, Clock,
} from 'lucide-vue-next';
import { useSweetAlert } from '@/composables/useSweetAlert';
import {
    ratingClasses, resultClasses, resultSwatch, statusClasses, inputClass, formatDate, useAuditPermissions,
} from './Composables/useAuditMeta';

const props = defineProps({
    audit: { type: Object, required: true },
    template: { type: Object, required: true },
});

const { canManage, canUpdateCap } = useAuditPermissions();
const isCompleted = computed(() => props.audit.status === 'completed');

// ----- summaries -----
const issues = computed(() => props.audit.findings.filter((f) => f.rating !== 'compliant'));
const closedCount = computed(() => issues.value.filter((f) => f.status === 'closed').length);
const overdueCount = computed(() => issues.value.filter((f) => f.is_overdue).length);

const ratingTotals = computed(() =>
    Object.entries(props.template.ratings)
        .map(([key, label]) => ({ key, label, count: props.audit.findings.filter((f) => f.rating === key).length }))
        .filter((r) => r.count > 0),
);

const manpowerTotal = (group) => (props.audit.manpower?.[group] ?? []).reduce((sum, r) => sum + (Number(r.count) || 0), 0);

const legalStatus = {
    yes: { label: 'Yes', class: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' },
    no: { label: 'No', class: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' },
    na: { label: 'N/A', class: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' },
};

const missingLegalDocs = computed(() => (props.audit.legal_documents ?? []).filter((d) => d.status === 'no').length);

// ----- findings filter -----
const findingFilter = ref('all');
const filterOptions = computed(() => [
    { value: 'all', label: `All (${props.audit.findings.length})` },
    { value: 'open', label: `Open (${issues.value.length - closedCount.value})` },
    { value: 'overdue', label: `Overdue (${overdueCount.value})` },
    { value: 'closed', label: `Closed (${closedCount.value})` },
]);

const visibleFindings = computed(() =>
    props.audit.findings.filter((f) => {
        if (findingFilter.value === 'open') return f.status === 'open';
        if (findingFilter.value === 'overdue') return f.is_overdue;
        if (findingFilter.value === 'closed') return f.status === 'closed';
        return true;
    }),
);

const findingsByCategory = computed(() =>
    props.template.categories
        .map((category) => ({ category, findings: visibleFindings.value.filter((f) => f.category === category) }))
        .filter((group) => group.findings.length),
);

const capBadge = (finding) => {
    if (finding.rating === 'compliant') return null;
    if (finding.status === 'closed') return { label: 'Closed', class: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300', icon: CheckCircle2 };
    if (finding.is_overdue) return { label: 'Overdue', class: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300', icon: AlertTriangle };
    return { label: 'Open', class: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300', icon: Clock };
};

// ----- CAP update -----
const editingFinding = ref(null);
const capForm = useForm({ corrective_action: '', responsible_person: '', target_date: '', status: 'open' });

const openCap = (finding) => {
    editingFinding.value = finding;
    capForm.defaults({
        corrective_action: finding.corrective_action ?? '',
        responsible_person: finding.responsible_person ?? '',
        target_date: finding.target_date ?? '',
        status: finding.status ?? 'open',
    });
    capForm.reset();
    capForm.clearErrors();
};

const closeCap = () => {
    editingFinding.value = null;
};

const saveCap = () => {
    capForm.patch(route('audits.findings.cap', editingFinding.value.id), {
        preserveScroll: true,
        onSuccess: closeCap,
    });
};

// ----- audit actions -----
const reopenAudit = async () => {
    const { showConfirm } = useSweetAlert();
    const result = await showConfirm('The report will return to draft so it can be edited, and must be completed again.', 'Reopen this audit?');
    if (result.isConfirmed) {
        router.post(route('audits.reopen', props.audit.id));
    }
};

const deleteAudit = async () => {
    const { showDeleteConfirm } = useSweetAlert();
    const result = await showDeleteConfirm(`Delete draft audit <strong>${props.audit.reference_no}</strong>?`);
    if (result.isConfirmed) {
        router.delete(route('audits.destroy', props.audit.id));
    }
};

const card = 'rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800 sm:p-6';
const heading = 'mb-4 flex items-center gap-2 text-base font-semibold text-gray-900 dark:text-gray-100';
const dt = 'text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400';
const dd = 'mt-0.5 text-sm text-gray-900 dark:text-gray-100';
const th = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
const td = 'px-3 py-2 text-sm text-gray-700 dark:text-gray-300';
const actionLink = 'inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600';
</script>

<template>
    <Head :title="audit.reference_no" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <Link :href="route('audits.index')" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200">
                        <ArrowLeft :size="14" /> All audits
                    </Link>
                    <h2 class="flex flex-wrap items-center gap-2">
                        {{ audit.reference_no }}
                        <span :class="['rounded-full border px-2.5 py-0.5 text-xs font-semibold capitalize', statusClasses[audit.status]]">{{ audit.status }}</span>
                    </h2>
                    <p>{{ audit.facility_name }} · {{ template.types[audit.audit_type] }} audit · {{ formatDate(audit.assessment_date) }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a :href="route('audits.print', audit.id)" target="_blank" :class="actionLink"><Printer :size="15" /> Print</a>
                    <template v-if="canManage">
                        <template v-if="!isCompleted">
                            <Link :href="route('audits.edit', audit.id)" class="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-500">
                                <Pencil :size="15" /> Continue editing
                            </Link>
                            <button type="button" :class="[actionLink, 'text-red-600 dark:text-red-400']" @click="deleteAudit"><Trash2 :size="15" /> Delete</button>
                        </template>
                        <template v-else>
                            <Link :href="route('audits.create', { from: audit.id })" class="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-500">
                                <CopyPlus :size="15" /> Start follow-up
                            </Link>
                            <button type="button" :class="actionLink" @click="reopenAudit"><RotateCcw :size="15" /> Reopen</button>
                        </template>
                    </template>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-8xl space-y-6 px-4">
                <!-- Summary -->
                <div class="grid gap-4 md:grid-cols-3">
                    <div :class="['rounded-lg border-2 p-5', audit.overall_result ? resultClasses[audit.overall_result] : 'border-dashed border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800']">
                        <p class="text-xs font-semibold uppercase tracking-wide opacity-80">Overall result</p>
                        <p class="mt-1 flex items-center gap-2 text-xl font-bold">
                            <span v-if="audit.overall_result" :class="['h-3.5 w-3.5 rounded-full', resultSwatch[audit.overall_result]]"></span>
                            {{ audit.overall_result ? template.results[audit.overall_result].label : 'Not rated yet' }}
                        </p>
                        <p v-if="audit.overall_result && template.results[audit.overall_result].color" class="text-xs opacity-80">
                            {{ template.results[audit.overall_result].color }} rating
                        </p>
                    </div>

                    <div class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p :class="dt">Findings</p>
                        <div v-if="ratingTotals.length" class="mt-2 flex flex-wrap gap-1.5">
                            <span v-for="r in ratingTotals" :key="r.key" :class="['rounded border px-2 py-0.5 text-xs font-semibold', ratingClasses[r.key]]">
                                {{ r.label }} {{ r.count }}
                            </span>
                        </div>
                        <p v-else class="mt-2 text-sm text-gray-500 dark:text-gray-400">No findings recorded.</p>
                    </div>

                    <div class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p :class="dt">Corrective action plan</p>
                        <template v-if="issues.length">
                            <p class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">
                                {{ closedCount }} / {{ issues.length }} <span class="text-sm font-medium text-gray-500">closed</span>
                            </p>
                            <div class="mt-2 h-2 rounded-full bg-gray-200 dark:bg-gray-700">
                                <div class="h-2 rounded-full bg-green-500" :style="{ width: `${Math.round((closedCount / issues.length) * 100)}%` }"></div>
                            </div>
                            <p v-if="overdueCount" class="mt-2 flex items-center gap-1 text-xs font-semibold text-red-600 dark:text-red-400">
                                <AlertTriangle :size="13" /> {{ overdueCount }} overdue
                            </p>
                        </template>
                        <p v-else class="mt-2 text-sm text-gray-500 dark:text-gray-400">No corrective actions required.</p>
                    </div>
                </div>

                <div
                    v-if="!isCompleted"
                    class="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-300"
                >
                    <AlertTriangle :size="18" class="mt-0.5 shrink-0" />
                    This report is still a draft. Corrective action plans can be tracked here once the auditor completes it.
                </div>

                <!-- Factory & assessment -->
                <section :class="card">
                    <h3 :class="heading"><Building2 :size="18" class="text-indigo-500" /> Factory &amp; Assessment Information</h3>
                    <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="sm:col-span-2"><dt :class="dt">Factory</dt><dd :class="dd">{{ audit.facility_name }}</dd></div>
                        <div class="sm:col-span-2"><dt :class="dt">Address</dt><dd :class="[dd, 'whitespace-pre-line']">{{ audit.facility_address || '—' }}</dd></div>
                        <div><dt :class="dt">Number of employees</dt><dd :class="dd">{{ audit.number_of_employees ?? '—' }}</dd></div>
                        <div><dt :class="dt">Assessment date</dt><dd :class="dd">{{ formatDate(audit.assessment_date) }}</dd></div>
                        <div><dt :class="dt">Assessment standard</dt><dd :class="dd">{{ audit.assessment_standard }}</dd></div>
                        <div><dt :class="dt">Assessment partner</dt><dd :class="dd">{{ audit.assessment_partner || '—' }}</dd></div>
                        <div><dt :class="dt">Audit type</dt><dd :class="dd">{{ template.types[audit.audit_type] }}</dd></div>
                        <div><dt :class="dt">Auditor(s)</dt><dd :class="dd">{{ (audit.auditors || []).join(', ') }}</dd></div>
                        <div class="sm:col-span-2">
                            <dt :class="dt">Factory representative(s)</dt>
                            <dd v-if="(audit.factory_representatives || []).length" :class="dd">
                                <span v-for="(rep, index) in audit.factory_representatives" :key="index" class="block">
                                    {{ rep.name || '—' }}<span v-if="rep.title || rep.department" class="text-gray-500">
                                        {{ ' · ' + [rep.title, rep.department].filter(Boolean).join(', ') }}</span>
                                </span>
                            </dd>
                            <dd v-else :class="dd">—</dd>
                        </div>
                        <div>
                            <dt :class="dt">Recorded by</dt>
                            <dd :class="dd">
                                {{ audit.creator?.name || '—' }}
                                <span v-if="audit.completer" class="block text-xs text-gray-500 dark:text-gray-400">
                                    Completed by {{ audit.completer.name }} on {{ formatDate(audit.completed_at) }}
                                </span>
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- Short description -->
                <section :class="card">
                    <h3 :class="heading"><ClipboardList :size="18" class="text-indigo-500" /> Short Description</h3>
                    <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-5">
                        <div><dt :class="dt">Established in</dt><dd :class="dd">{{ audit.established_year || '—' }}</dd></div>
                        <div><dt :class="dt">Total area (sq ft)</dt><dd :class="dd">{{ audit.total_area || '—' }}</dd></div>
                        <div><dt :class="dt">Warehouse / store</dt><dd :class="dd">{{ audit.warehouse_area || '—' }}</dd></div>
                        <div><dt :class="dt">Production area</dt><dd :class="dd">{{ audit.production_area || '—' }}</dd></div>
                        <div><dt :class="dt">Office &amp; others</dt><dd :class="dd">{{ audit.office_area || '—' }}</dd></div>
                        <div><dt :class="dt">Recent buyer</dt><dd :class="dd">{{ audit.recent_buyer || '—' }}</dd></div>
                        <div><dt :class="dt">Monthly capacity</dt><dd :class="dd">{{ audit.monthly_capacity || '—' }}</dd></div>
                        <div><dt :class="dt">Total lines</dt><dd :class="dd">{{ audit.total_lines ?? '—' }}</dd></div>
                        <div><dt :class="dt">Total machines</dt><dd :class="dd">{{ audit.total_machines ?? '—' }}</dd></div>
                        <div><dt :class="dt">Total employees</dt><dd :class="dd">{{ audit.number_of_employees ?? '—' }}</dd></div>
                    </dl>
                </section>

                <!-- Manpower + floors -->
                <div class="grid gap-6 lg:grid-cols-2">
                    <section :class="card">
                        <h3 :class="heading">
                            <Users :size="18" class="text-indigo-500" /> Manpower Details
                            <span class="ml-auto text-sm font-normal text-gray-500">Total {{ manpowerTotal('workers') + manpowerTotal('staff') }}</span>
                        </h3>
                        <div class="space-y-4">
                            <table v-for="group in ['workers', 'staff']" :key="group" class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-900/40">
                                    <tr><th :class="th">{{ group === 'workers' ? 'Workers' : 'Staff' }}</th><th :class="[th, 'w-24 text-right']">Number</th></tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    <tr v-for="(row, i) in audit.manpower?.[group] ?? []" :key="i">
                                        <td :class="td">{{ row.designation }}</td>
                                        <td :class="[td, 'text-right']">{{ row.count ?? '—' }}</td>
                                    </tr>
                                    <tr class="bg-gray-50 font-semibold dark:bg-gray-900/40">
                                        <td :class="td">Total number of {{ group === 'workers' ? 'workers' : 'staffs' }}</td>
                                        <td :class="[td, 'text-right']">{{ manpowerTotal(group) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section :class="card">
                        <h3 :class="heading">
                            <Layers :size="18" class="text-indigo-500" /> Floor Layout
                            <span v-if="audit.building_storeys" class="ml-auto text-sm font-normal text-gray-500">{{ audit.building_storeys }} storied building</span>
                        </h3>
                        <dl v-if="audit.floor_layout?.length" class="divide-y divide-gray-100 dark:divide-gray-700">
                            <div v-for="(row, i) in audit.floor_layout" :key="i" class="grid grid-cols-[8rem_1fr] gap-3 py-2">
                                <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ row.floor }}</dt>
                                <dd class="whitespace-pre-line text-sm text-gray-600 dark:text-gray-400">{{ row.usage || '—' }}</dd>
                            </div>
                        </dl>
                        <p v-else class="text-sm text-gray-500">No floor layout recorded.</p>
                    </section>
                </div>

                <!-- Legal documents + fire equipment -->
                <div class="grid gap-6 lg:grid-cols-2">
                    <section :class="card">
                        <h3 :class="heading">
                            <FileCheck2 :size="18" class="text-indigo-500" /> Legal Documents
                            <span v-if="missingLegalDocs" class="ml-auto rounded bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-800 dark:bg-red-900/40 dark:text-red-300">
                                {{ missingLegalDocs }} missing
                            </span>
                        </h3>
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900/40">
                                <tr><th :class="th">Certificate / License</th><th :class="th">Status</th><th :class="th">Valid Up To</th><th :class="th">Remarks</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="(doc, i) in audit.legal_documents ?? []" :key="i">
                                    <td :class="td">{{ doc.name }}</td>
                                    <td :class="[td, 'whitespace-nowrap']">
                                        <span v-if="doc.status" :class="['rounded px-2 py-0.5 text-xs font-semibold', legalStatus[doc.status].class]">{{ legalStatus[doc.status].label }}</span>
                                        <span v-else class="text-xs text-amber-600 dark:text-amber-400">Not checked</span>
                                    </td>
                                    <td :class="[td, 'whitespace-nowrap']">{{ formatDate(doc.valid_until) }}</td>
                                    <td :class="[td, 'text-xs']">{{ doc.remarks || '' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </section>

                    <section :class="card">
                        <h3 :class="heading"><Flame :size="18" class="text-indigo-500" /> Fire Fighting Equipment</h3>
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900/40">
                                <tr><th :class="th">Equipment</th><th :class="[th, 'w-24 text-right']">Quantity</th><th :class="th">Remarks</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="(item, i) in audit.fire_equipment ?? []" :key="i">
                                    <td :class="td">{{ item.name }}</td>
                                    <td :class="[td, 'text-right']">{{ item.quantity ?? '—' }}</td>
                                    <td :class="[td, 'text-xs']">{{ item.remarks || '' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </section>
                </div>

                <!-- Findings & CAP -->
                <section :class="card">
                    <div class="mb-4 flex flex-wrap items-center gap-3">
                        <h3 class="flex items-center gap-2 text-base font-semibold text-gray-900 dark:text-gray-100">
                            <ListChecks :size="18" class="text-indigo-500" /> Audit Findings &amp; Corrective Action Plan
                        </h3>
                        <div v-if="issues.length" class="ml-auto inline-flex overflow-hidden rounded-md border border-gray-300 dark:border-gray-600">
                            <button
                                v-for="option in filterOptions"
                                :key="option.value"
                                type="button"
                                :class="[
                                    'px-3 py-1.5 text-xs font-medium',
                                    findingFilter === option.value
                                        ? 'bg-indigo-600 text-white'
                                        : 'bg-white text-gray-600 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600',
                                ]"
                                @click="findingFilter = option.value"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                    </div>

                    <div v-if="findingsByCategory.length" class="space-y-5">
                        <div v-for="group in findingsByCategory" :key="group.category">
                            <p class="mb-2 border-b border-gray-200 pb-1 text-sm font-semibold text-gray-800 dark:border-gray-700 dark:text-gray-200">{{ group.category }}</p>
                            <div class="space-y-3">
                                <div
                                    v-for="finding in group.findings"
                                    :key="finding.id"
                                    :class="[
                                        'rounded-lg border p-4',
                                        finding.is_overdue ? 'border-red-200 bg-red-50/40 dark:border-red-900 dark:bg-red-900/10' : 'border-gray-200 dark:border-gray-700',
                                    ]"
                                >
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span :class="['rounded border px-2 py-0.5 text-xs font-semibold', ratingClasses[finding.rating]]">{{ template.ratings[finding.rating] }}</span>
                                        <span v-if="capBadge(finding)" :class="['inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-semibold', capBadge(finding).class]">
                                            <component :is="capBadge(finding).icon" :size="12" /> {{ capBadge(finding).label }}
                                        </span>
                                        <button
                                            v-if="isCompleted && canUpdateCap && finding.rating !== 'compliant'"
                                            type="button"
                                            class="ml-auto inline-flex items-center gap-1 rounded-md border border-indigo-200 px-2.5 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:text-indigo-300 dark:hover:bg-indigo-900/30"
                                            @click="openCap(finding)"
                                        >
                                            <Pencil :size="12" /> Update CAP
                                        </button>
                                    </div>
                                    <p class="mt-2 whitespace-pre-line text-sm text-gray-900 dark:text-gray-100">{{ finding.finding }}</p>

                                    <div v-if="finding.rating !== 'compliant'" class="mt-3 grid gap-3 rounded-md bg-gray-50 p-3 text-sm dark:bg-gray-900/40 md:grid-cols-[2fr_1fr_1fr]">
                                        <div>
                                            <p :class="dt">Corrective action plan</p>
                                            <p class="mt-0.5 whitespace-pre-line text-gray-800 dark:text-gray-200">{{ finding.corrective_action || 'Not provided yet' }}</p>
                                        </div>
                                        <div>
                                            <p :class="dt">Responsible person</p>
                                            <p class="mt-0.5 text-gray-800 dark:text-gray-200">{{ finding.responsible_person || '—' }}</p>
                                        </div>
                                        <div>
                                            <p :class="dt">Completion date</p>
                                            <p :class="['mt-0.5', finding.is_overdue ? 'font-semibold text-red-600 dark:text-red-400' : 'text-gray-800 dark:text-gray-200']">
                                                {{ formatDate(finding.target_date) }}
                                            </p>
                                            <p v-if="finding.closed_at" class="text-xs text-gray-500 dark:text-gray-400">
                                                Closed {{ formatDate(finding.closed_at) }}<template v-if="finding.closer"> by {{ finding.closer.name }}</template>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <p v-else class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                        {{ audit.findings.length ? 'No findings match this filter.' : 'No findings were recorded in this audit.' }}
                    </p>
                </section>

                <!-- Result -->
                <section :class="card">
                    <h3 :class="heading"><Award :size="18" class="text-indigo-500" /> Result and Comments</h3>
                    <div class="grid gap-2 sm:grid-cols-5">
                        <div
                            v-for="(result, key) in template.results"
                            :key="key"
                            :class="[
                                'rounded-md border-2 px-3 py-2 text-sm',
                                audit.overall_result === key ? resultClasses[key] : 'border-gray-200 text-gray-400 dark:border-gray-700 dark:text-gray-500',
                            ]"
                        >
                            <span class="flex items-center gap-2 font-semibold">
                                <span :class="['h-2.5 w-2.5 rounded-full', audit.overall_result === key ? resultSwatch[key] : 'bg-gray-300 dark:bg-gray-600']"></span>
                                {{ result.label }}
                            </span>
                        </div>
                    </div>
                    <p class="mt-4 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ audit.result_comments || 'No comments.' }}</p>
                </section>
            </div>
        </div>

        <Modal :show="Boolean(editingFinding)" max-width="2xl" @close="closeCap">
            <form v-if="editingFinding" class="space-y-4 p-6 dark:bg-gray-800" @submit.prevent="saveCap">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Update Corrective Action Plan</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        <span :class="['mr-1 rounded border px-1.5 py-0.5 text-xs font-semibold', ratingClasses[editingFinding.rating]]">{{ template.ratings[editingFinding.rating] }}</span>
                        {{ editingFinding.finding }}
                    </p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Factory corrective action plan</label>
                    <textarea v-model="capForm.corrective_action" rows="4" :class="inputClass"></textarea>
                    <p v-if="capForm.errors.corrective_action" class="mt-1 text-xs text-red-600">{{ capForm.errors.corrective_action }}</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Responsible person (name &amp; title)</label>
                        <input v-model="capForm.responsible_person" type="text" :class="inputClass" />
                        <p v-if="capForm.errors.responsible_person" class="mt-1 text-xs text-red-600">{{ capForm.errors.responsible_person }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Completion date</label>
                        <input v-model="capForm.target_date" type="date" :class="inputClass" />
                        <p v-if="capForm.errors.target_date" class="mt-1 text-xs text-red-600">{{ capForm.errors.target_date }}</p>
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                    <div class="flex gap-2">
                        <label
                            v-for="option in [{ value: 'open', label: 'Open' }, { value: 'closed', label: 'Closed — action completed' }]"
                            :key="option.value"
                            :class="[
                                'flex flex-1 cursor-pointer items-center justify-center rounded-md border px-3 py-2 text-sm font-medium',
                                capForm.status === option.value
                                    ? 'border-indigo-600 bg-indigo-50 text-indigo-700 dark:border-indigo-400 dark:bg-indigo-900/40 dark:text-indigo-300'
                                    : 'border-gray-300 text-gray-600 dark:border-gray-600 dark:text-gray-300',
                            ]"
                        >
                            <input v-model="capForm.status" type="radio" :value="option.value" class="sr-only" />
                            {{ option.label }}
                        </label>
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" :class="actionLink" @click="closeCap">Cancel</button>
                    <button
                        type="submit"
                        :disabled="capForm.processing"
                        class="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                    >
                        Save CAP
                    </button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>

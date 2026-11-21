<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2, AlertTriangle, Save, CheckCircle2, Building2, ClipboardList, Users, Layers, FileCheck2, Flame, ListChecks, Award } from 'lucide-vue-next';
import { useSweetAlert } from '@/composables/useSweetAlert';
import { inputClass, ratingClasses, resultClasses, resultSwatch } from '../Composables/useAuditMeta';

const props = defineProps({
    audit: { type: Object, required: true },
    units: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    template: { type: Object, required: true },
});

let keySeed = 0;
const nextKey = () => `k${++keySeed}`;

const buildData = (audit) => {
    const initial = JSON.parse(JSON.stringify(audit));
    const auditors = initial.auditors?.length ? initial.auditors : [''];

    return {
        action: 'draft',
        unit_id: initial.unit_id ?? '',
        facility_address: initial.facility_address ?? '',
        number_of_employees: initial.number_of_employees ?? '',
        assessment_date: initial.assessment_date ?? '',
        assessment_standard: initial.assessment_standard ?? '',
        assessment_partner: initial.assessment_partner ?? '',
        audit_type: initial.audit_type ?? 'initial',
        auditors: auditors.length < 2 ? [...auditors, ''] : auditors,
        factory_representatives: initial.factory_representatives?.length
            ? initial.factory_representatives
            : [{ name: '', title: '', department: '' }],
        established_year: initial.established_year ?? '',
        total_area: initial.total_area ?? '',
        warehouse_area: initial.warehouse_area ?? '',
        production_area: initial.production_area ?? '',
        office_area: initial.office_area ?? '',
        recent_buyer: initial.recent_buyer ?? '',
        monthly_capacity: initial.monthly_capacity ?? '',
        total_lines: initial.total_lines ?? '',
        total_machines: initial.total_machines ?? '',
        manpower: {
            workers: initial.manpower?.workers ?? [],
            staff: initial.manpower?.staff ?? [],
        },
        building_storeys: initial.building_storeys ?? '',
        floor_layout: initial.floor_layout ?? [],
        legal_documents: initial.legal_documents ?? [],
        fire_equipment: initial.fire_equipment ?? [],
        findings: (initial.findings ?? []).map((f) => ({
            _key: nextKey(),
            id: f.id ?? null,
            category: f.category,
            rating: f.rating,
            finding: f.finding ?? '',
            corrective_action: f.corrective_action ?? '',
            responsible_person: f.responsible_person ?? '',
            target_date: f.target_date ?? '',
            status: f.status ?? 'open',
        })),
        overall_result: initial.overall_result ?? '',
        result_comments: initial.result_comments ?? '',
    };
};

const form = useForm(buildData(props.audit));

const isEdit = computed(() => Boolean(props.audit.id));

const sections = [
    { id: 'facility', label: 'Factory & Assessment', icon: Building2 },
    { id: 'description', label: 'Short Description', icon: ClipboardList },
    { id: 'manpower', label: 'Manpower', icon: Users },
    { id: 'floors', label: 'Floor Layout', icon: Layers },
    { id: 'legal', label: 'Legal Documents', icon: FileCheck2 },
    { id: 'fire', label: 'Fire Equipment', icon: Flame },
    { id: 'findings', label: 'Findings & CAP', icon: ListChecks },
    { id: 'result', label: 'Result', icon: Award },
];

const activeSection = ref(sections[0].id);
let sectionObserver = null;

onMounted(() => {
    sectionObserver = new IntersectionObserver(
        (entries) => {
            const top = entries
                .filter((entry) => entry.isIntersecting)
                .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0];
            if (top) activeSection.value = top.target.id;
        },
        { rootMargin: '-88px 0px -65% 0px' },
    );

    sections.forEach(({ id }) => {
        const el = document.getElementById(id);
        if (el) sectionObserver.observe(el);
    });
});

onBeforeUnmount(() => sectionObserver?.disconnect());

const selectUnit = () => {
    const unit = props.units.find((u) => u.id === Number(form.unit_id));
    if (unit && !form.facility_address) {
        form.facility_address = unit.location ?? '';
    }
};

// ----- repeatable rows -----
const addAuditor = () => form.auditors.push('');
const removeAuditor = (index) => form.auditors.splice(index, 1);

// Include the currently saved name as an option even if it no longer matches a user
// (e.g. a name typed before this became a dropdown, or a user that was later removed).
const auditorOptions = (name) => {
    const names = props.users.map((u) => u.name);
    return name && !names.includes(name) ? [name, ...names] : names;
};

const addRepresentative = () => form.factory_representatives.push({ name: '', title: '', department: '' });
const removeRepresentative = (index) => form.factory_representatives.splice(index, 1);

const manpowerTotal = (group) => form.manpower[group].reduce((sum, row) => sum + (Number(row.count) || 0), 0);

const legalSummary = computed(() => {
    const counts = { yes: 0, no: 0, na: 0, unchecked: 0 };
    form.legal_documents.forEach((doc) => {
        counts[doc.status ?? 'unchecked'] = (counts[doc.status ?? 'unchecked'] ?? 0) + 1;
    });
    return counts;
});

const legalOptions = [
    { value: 'yes', label: 'Yes', active: 'bg-green-600 text-white border-green-600' },
    { value: 'no', label: 'No', active: 'bg-red-600 text-white border-red-600' },
    { value: 'na', label: 'N/A', active: 'bg-gray-600 text-white border-gray-600' },
];

const toggleLegalStatus = (doc, value) => {
    doc.status = doc.status === value ? null : value;
};

// ----- findings: kept sorted by category so indices match validation error keys -----
const categoryIndex = (category) => props.template.categories.indexOf(category);

const findingsIn = (category) =>
    form.findings
        .map((finding, index) => ({ finding, index }))
        .filter(({ finding }) => finding.category === category);

const addFinding = (category) => {
    const insertAt = form.findings.findIndex((f) => categoryIndex(f.category) > categoryIndex(category));
    const finding = {
        _key: nextKey(),
        id: null,
        category,
        rating: 'minor',
        finding: '',
        corrective_action: '',
        responsible_person: '',
        target_date: '',
        status: 'open',
    };
    form.findings.splice(insertAt === -1 ? form.findings.length : insertAt, 0, finding);
};

const removeFinding = async (index) => {
    const finding = form.findings[index];
    if (finding.finding || finding.corrective_action) {
        const { showDeleteConfirm } = useSweetAlert();
        const result = await showDeleteConfirm('Remove this finding from the report?');
        if (!result.isConfirmed) return;
    }
    form.findings.splice(index, 1);
};

const ratingTotals = computed(() =>
    Object.entries(props.template.ratings)
        .map(([key, label]) => ({ key, label, count: form.findings.filter((f) => f.rating === key).length }))
        .filter((r) => r.count > 0),
);

const hasZeroTolerance = computed(() => form.findings.some((f) => f.rating === 'zero_tolerance'));

// ----- submit -----
const errorCount = computed(() => Object.keys(form.errors).length);

const submit = async (action) => {
    if (action === 'complete') {
        const { showConfirm } = useSweetAlert();
        const result = await showConfirm(
            'The report will be locked for editing. Corrective action plans can still be updated afterwards.',
            'Complete this audit?',
        );
        if (!result.isConfirmed) return;
    }

    const options = {
        preserveScroll: true,
        // Saving a draft returns to this same page with the component kept alive; reload the
        // form from the saved audit so new findings pick up their database ids.
        onSuccess: () => {
            form.defaults(buildData(props.audit));
            form.reset();
        },
        onError: () => {
            const first = document.querySelector('[data-error="true"]');
            first?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        },
    };

    form.transform((data) => ({
        ...data,
        action,
        findings: data.findings.map(({ _key, ...finding }) => finding),
    }));

    if (isEdit.value) {
        form.put(route('audits.update', props.audit.id), options);
    } else {
        form.post(route('audits.store'), options);
    }
};

const err = (key) => form.errors[key];
const hasErrorPrefix = (prefix) => Object.keys(form.errors).some((key) => key.startsWith(prefix));

const cardClass = 'scroll-mt-24 rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800 sm:p-6';
const labelClass = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300';
const thClass = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
const smallButton = 'inline-flex items-center gap-1 rounded-md border border-dashed border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:border-indigo-400 hover:text-indigo-600 dark:border-gray-600 dark:text-gray-300 dark:hover:border-indigo-500 dark:hover:text-indigo-400';
const errorText = 'mt-1 text-xs text-red-600 dark:text-red-400';
</script>

<template>
    <div class="py-6">
        <div class="mx-auto max-w-7xl space-y-6 px-4">
            <nav class="sticky top-0 z-10 -mx-4 overflow-x-auto border-b border-gray-200/80 bg-gray-100/90 px-4 py-3 backdrop-blur-md dark:border-gray-700/60 dark:bg-gray-900/90">
                <div class="flex gap-2">
                    <a
                        v-for="section in sections"
                        :key="section.id"
                        :href="`#${section.id}`"
                        :aria-current="activeSection === section.id ? 'location' : undefined"
                        :class="[
                            'inline-flex shrink-0 items-center gap-2 rounded-full px-4 py-2 text-xs font-semibold tracking-wide transition duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900',
                            activeSection === section.id
                                ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30 ring-1 ring-indigo-600'
                                : 'bg-white text-gray-600 shadow-sm ring-1 ring-gray-200 hover:-translate-y-0.5 hover:bg-indigo-50 hover:text-indigo-700 hover:shadow-md hover:ring-indigo-300 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-indigo-900/40 dark:hover:text-indigo-300 dark:hover:ring-indigo-700',
                        ]"
                    >
                        <component
                            :is="section.icon"
                            :size="14"
                            :class="activeSection === section.id ? 'text-white' : 'text-indigo-500 dark:text-indigo-400'"
                        />
                        {{ section.label }}
                    </a>
                </div>
            </nav>

            <!-- Factory & assessment information -->
            <section id="facility" :class="cardClass">
                <h3 class="mb-4 flex items-center gap-2 text-base font-semibold text-gray-900 dark:text-gray-100">
                    <Building2 :size="18" class="text-indigo-500" /> Factory &amp; Assessment Information
                </h3>
                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="space-y-4">
                        <p class="text-xs font-bold uppercase tracking-widest text-gray-400">Factory Information</p>
                        <div :data-error="!!err('unit_id')">
                            <label :class="labelClass">Factory <span class="text-red-500">*</span></label>
                            <select v-model="form.unit_id" :class="inputClass" @change="selectUnit">
                                <option value="" disabled>Select a factory…</option>
                                <option v-for="unit in units" :key="unit.id" :value="unit.id">
                                    {{ unit.name }}{{ unit.short_form ? ` (${unit.short_form})` : '' }}
                                </option>
                            </select>
                            <p v-if="err('unit_id')" :class="errorText">{{ err('unit_id') }}</p>
                        </div>
                        <div>
                            <label :class="labelClass">Address</label>
                            <textarea v-model="form.facility_address" rows="2" :class="inputClass"></textarea>
                            <p v-if="err('facility_address')" :class="errorText">{{ err('facility_address') }}</p>
                        </div>
                        <div :data-error="!!err('number_of_employees')">
                            <label :class="labelClass">Number of Employees</label>
                            <input v-model="form.number_of_employees" type="number" min="0" :class="inputClass" />
                            <p v-if="err('number_of_employees')" :class="errorText">{{ err('number_of_employees') }}</p>
                        </div>

                        <p class="pt-2 text-xs font-bold uppercase tracking-widest text-gray-400">Factory Representative(s)</p>
                        <div class="space-y-2" :data-error="hasErrorPrefix('factory_representatives')">
                            <div
                                v-for="(rep, index) in form.factory_representatives"
                                :key="index"
                                class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-start"
                            >
                                <input v-model="rep.name" type="text" placeholder="Name" :class="inputClass" />
                                <input v-model="rep.title" type="text" placeholder="Title / Designation" :class="inputClass" />
                                <input v-model="rep.department" type="text" placeholder="Department" :class="inputClass" />
                                <button
                                    v-if="form.factory_representatives.length > 1"
                                    type="button"
                                    class="text-gray-400 hover:text-red-600 sm:mt-2"
                                    title="Remove representative"
                                    @click="removeRepresentative(index)"
                                >
                                    <Trash2 :size="16" />
                                </button>
                            </div>
                            <button type="button" :class="smallButton" @click="addRepresentative"><Plus :size="13" /> Add representative</button>
                            <p v-if="hasErrorPrefix('factory_representatives')" :class="errorText">Check the factory representative rows.</p>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <p class="text-xs font-bold uppercase tracking-widest text-gray-400">Assessment Information</p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div :data-error="!!err('assessment_date')">
                                <label :class="labelClass">Assessment Date <span class="text-red-500">*</span></label>
                                <input v-model="form.assessment_date" type="date" :class="inputClass" />
                                <p v-if="err('assessment_date')" :class="errorText">{{ err('assessment_date') }}</p>
                            </div>
                            <div :data-error="!!err('assessment_standard')">
                                <label :class="labelClass">Assessment Standard <span class="text-red-500">*</span></label>
                                <select v-model="form.assessment_standard" :class="inputClass">
                                    <option v-for="standard in template.standards" :key="standard" :value="standard">{{ standard }}</option>
                                </select>
                                <p v-if="err('assessment_standard')" :class="errorText">{{ err('assessment_standard') }}</p>
                            </div>
                            <div>
                                <label :class="labelClass">Assessment Partner</label>
                                <input v-model="form.assessment_partner" type="text" :class="inputClass" />
                            </div>
                            <div :data-error="!!err('audit_type')">
                                <label :class="labelClass">Audit Type <span class="text-red-500">*</span></label>
                                <div class="flex gap-2">
                                    <label
                                        v-for="(label, value) in template.types"
                                        :key="value"
                                        :class="[
                                            'flex flex-1 cursor-pointer items-center justify-center rounded-md border px-3 py-2 text-sm font-medium',
                                            form.audit_type === value
                                                ? 'border-indigo-600 bg-indigo-50 text-indigo-700 dark:border-indigo-400 dark:bg-indigo-900/40 dark:text-indigo-300'
                                                : 'border-gray-300 text-gray-600 dark:border-gray-600 dark:text-gray-300',
                                        ]"
                                    >
                                        <input v-model="form.audit_type" type="radio" :value="value" class="sr-only" />
                                        {{ label }}
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div :data-error="hasErrorPrefix('auditors')">
                            <label :class="labelClass">Auditor(s) Name <span class="text-red-500">*</span></label>
                            <div class="space-y-2">
                                <div v-for="(name, index) in form.auditors" :key="index" class="flex items-center gap-2">
                                    <span class="w-5 text-right text-sm text-gray-400">{{ index + 1 }}.</span>
                                    <select v-model="form.auditors[index]" :class="inputClass">
                                        <option value="" disabled>Select an auditor…</option>
                                        <option v-for="userName in auditorOptions(name)" :key="userName" :value="userName">{{ userName }}</option>
                                    </select>
                                    <button
                                        v-if="form.auditors.length > 1"
                                        type="button"
                                        class="text-gray-400 hover:text-red-600"
                                        title="Remove auditor"
                                        @click="removeAuditor(index)"
                                    >
                                        <Trash2 :size="16" />
                                    </button>
                                </div>
                            </div>
                            <button type="button" :class="[smallButton, 'mt-2']" @click="addAuditor"><Plus :size="13" /> Add auditor</button>
                            <p v-if="err('auditors')" :class="errorText">{{ err('auditors') }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Short description -->
            <section id="description" :class="cardClass">
                <h3 class="mb-4 flex items-center gap-2 text-base font-semibold text-gray-900 dark:text-gray-100">
                    <ClipboardList :size="18" class="text-indigo-500" /> Short Description
                </h3>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label :class="labelClass">Established in</label>
                        <input v-model="form.established_year" type="text" placeholder="e.g. 2004" :class="inputClass" />
                    </div>
                    <div>
                        <label :class="labelClass">Total area (sq ft)</label>
                        <input v-model="form.total_area" type="text" :class="inputClass" />
                    </div>
                    <div>
                        <label :class="labelClass">Warehouse / store area</label>
                        <input v-model="form.warehouse_area" type="text" :class="inputClass" />
                    </div>
                    <div>
                        <label :class="labelClass">Production area</label>
                        <input v-model="form.production_area" type="text" :class="inputClass" />
                    </div>
                    <div>
                        <label :class="labelClass">Office &amp; others</label>
                        <input v-model="form.office_area" type="text" :class="inputClass" />
                    </div>
                    <div>
                        <label :class="labelClass">Recent buyer</label>
                        <input v-model="form.recent_buyer" type="text" :class="inputClass" />
                    </div>
                    <div>
                        <label :class="labelClass">Monthly capacity</label>
                        <input v-model="form.monthly_capacity" type="text" placeholder="e.g. 500,000 pcs" :class="inputClass" />
                    </div>
                    <div :data-error="!!err('total_lines')">
                        <label :class="labelClass">Total lines</label>
                        <input v-model="form.total_lines" type="number" min="0" :class="inputClass" />
                        <p v-if="err('total_lines')" :class="errorText">{{ err('total_lines') }}</p>
                    </div>
                    <div :data-error="!!err('total_machines')">
                        <label :class="labelClass">Total machines</label>
                        <input v-model="form.total_machines" type="number" min="0" :class="inputClass" />
                        <p v-if="err('total_machines')" :class="errorText">{{ err('total_machines') }}</p>
                    </div>
                </div>
            </section>

            <!-- Manpower -->
            <section id="manpower" :class="cardClass" :data-error="hasErrorPrefix('manpower')">
                <h3 class="mb-4 flex items-center gap-2 text-base font-semibold text-gray-900 dark:text-gray-100">
                    <Users :size="18" class="text-indigo-500" /> Manpower Details
                    <span class="ml-auto text-sm font-normal text-gray-500 dark:text-gray-400">
                        Total: <strong class="text-gray-900 dark:text-gray-100">{{ manpowerTotal('workers') + manpowerTotal('staff') }}</strong>
                    </span>
                </h3>
                <div class="grid gap-6 lg:grid-cols-2">
                    <div v-for="group in ['workers', 'staff']" :key="group">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th :class="[thClass, 'w-8']">#</th>
                                    <th :class="thClass">{{ group === 'workers' ? 'Workers' : 'Staff' }} designation</th>
                                    <th :class="[thClass, 'w-28']">Number</th>
                                    <th class="w-8"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="(row, index) in form.manpower[group]" :key="index">
                                    <td class="px-3 py-1.5 text-sm text-gray-400">{{ index + 1 }}</td>
                                    <td class="px-1 py-1.5"><input v-model="row.designation" type="text" :class="inputClass" /></td>
                                    <td class="px-1 py-1.5"><input v-model="row.count" type="number" min="0" :class="inputClass" /></td>
                                    <td class="py-1.5 text-center">
                                        <button type="button" class="text-gray-400 hover:text-red-600" title="Remove row" @click="form.manpower[group].splice(index, 1)">
                                            <Trash2 :size="15" />
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="bg-gray-50 dark:bg-gray-900/40">
                                    <td></td>
                                    <td class="px-3 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200">
                                        Total number of {{ group === 'workers' ? 'workers' : 'staffs' }}
                                    </td>
                                    <td class="px-3 py-2 text-sm font-bold text-gray-900 dark:text-gray-100">{{ manpowerTotal(group) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                        <button type="button" :class="[smallButton, 'mt-2']" @click="form.manpower[group].push({ designation: '', count: null })">
                            <Plus :size="13" /> Add designation
                        </button>
                    </div>
                </div>
                <p v-if="hasErrorPrefix('manpower')" :class="errorText">Every manpower row needs a designation and a whole number.</p>
            </section>

            <!-- Floor layout -->
            <section id="floors" :class="cardClass" :data-error="hasErrorPrefix('floor_layout')">
                <h3 class="mb-4 flex flex-wrap items-center gap-2 text-base font-semibold text-gray-900 dark:text-gray-100">
                    <Layers :size="18" class="text-indigo-500" /> Floor Layout
                    <span class="ml-auto flex items-center gap-2 text-sm font-normal text-gray-600 dark:text-gray-300">
                        <input v-model="form.building_storeys" type="text" class="w-20 rounded-md border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" placeholder="—" />
                        storied building
                    </span>
                </h3>
                <div class="space-y-2">
                    <div v-for="(row, index) in form.floor_layout" :key="index" class="grid grid-cols-[10rem_1fr_auto] items-start gap-2">
                        <input v-model="row.floor" type="text" :class="[inputClass, 'font-medium']" />
                        <textarea v-model="row.usage" rows="1" :class="inputClass" placeholder="Sections / usage on this floor"></textarea>
                        <button type="button" class="mt-2 text-gray-400 hover:text-red-600" title="Remove floor" @click="form.floor_layout.splice(index, 1)">
                            <Trash2 :size="15" />
                        </button>
                    </div>
                </div>
                <button type="button" :class="[smallButton, 'mt-3']" @click="form.floor_layout.push({ floor: '', usage: '' })">
                    <Plus :size="13" /> Add floor / shed
                </button>
                <p v-if="hasErrorPrefix('floor_layout')" :class="errorText">Every floor row needs a name.</p>
            </section>

            <!-- Legal documents -->
            <section id="legal" :class="cardClass" :data-error="hasErrorPrefix('legal_documents')">
                <h3 class="mb-4 flex flex-wrap items-center gap-2 text-base font-semibold text-gray-900 dark:text-gray-100">
                    <FileCheck2 :size="18" class="text-indigo-500" /> Legal Documents
                    <span class="ml-auto flex flex-wrap gap-2 text-xs font-medium">
                        <span class="rounded bg-green-100 px-2 py-0.5 text-green-800 dark:bg-green-900/40 dark:text-green-300">Yes {{ legalSummary.yes }}</span>
                        <span class="rounded bg-red-100 px-2 py-0.5 text-red-800 dark:bg-red-900/40 dark:text-red-300">No {{ legalSummary.no }}</span>
                        <span class="rounded bg-gray-100 px-2 py-0.5 text-gray-700 dark:bg-gray-700 dark:text-gray-200">N/A {{ legalSummary.na }}</span>
                        <span v-if="legalSummary.unchecked" class="rounded bg-amber-100 px-2 py-0.5 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                            Not checked {{ legalSummary.unchecked }}
                        </span>
                    </span>
                </h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th :class="[thClass, 'w-8']">#</th>
                                <th :class="thClass">Certificate / License</th>
                                <th :class="[thClass, 'w-44']">Status</th>
                                <th :class="[thClass, 'w-40']">Valid Up To</th>
                                <th :class="thClass">Remarks</th>
                                <th class="w-8"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="(doc, index) in form.legal_documents" :key="index">
                                <td class="px-3 py-1.5 text-sm text-gray-400">{{ index + 1 }}</td>
                                <td class="min-w-[14rem] px-1 py-1.5"><input v-model="doc.name" type="text" :class="inputClass" /></td>
                                <td class="px-1 py-1.5">
                                    <div class="inline-flex overflow-hidden rounded-md border border-gray-300 dark:border-gray-600">
                                        <button
                                            v-for="option in legalOptions"
                                            :key="option.value"
                                            type="button"
                                            :class="[
                                                'px-3 py-1.5 text-xs font-semibold',
                                                doc.status === option.value ? option.active : 'bg-white text-gray-600 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600',
                                            ]"
                                            @click="toggleLegalStatus(doc, option.value)"
                                        >
                                            {{ option.label }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-1 py-1.5"><input v-model="doc.valid_until" type="date" :class="inputClass" /></td>
                                <td class="min-w-[12rem] px-1 py-1.5"><input v-model="doc.remarks" type="text" :class="inputClass" /></td>
                                <td class="py-1.5 text-center">
                                    <button type="button" class="text-gray-400 hover:text-red-600" title="Remove document" @click="form.legal_documents.splice(index, 1)">
                                        <Trash2 :size="15" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <button type="button" :class="[smallButton, 'mt-3']" @click="form.legal_documents.push({ name: '', status: null, valid_until: null, remarks: '' })">
                    <Plus :size="13" /> Add document
                </button>
                <p v-if="hasErrorPrefix('legal_documents')" :class="errorText">Every document row needs a name.</p>
            </section>

            <!-- Fire equipment -->
            <section id="fire" :class="cardClass" :data-error="hasErrorPrefix('fire_equipment')">
                <h3 class="mb-4 flex items-center gap-2 text-base font-semibold text-gray-900 dark:text-gray-100">
                    <Flame :size="18" class="text-indigo-500" /> List of Fire Fighting Equipment
                </h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th :class="[thClass, 'w-8']">#</th>
                                <th :class="thClass">Equipment Name</th>
                                <th :class="[thClass, 'w-32']">Quantity</th>
                                <th :class="thClass">Remarks</th>
                                <th class="w-8"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="(item, index) in form.fire_equipment" :key="index">
                                <td class="px-3 py-1.5 text-sm text-gray-400">{{ index + 1 }}</td>
                                <td class="min-w-[12rem] px-1 py-1.5"><input v-model="item.name" type="text" :class="inputClass" /></td>
                                <td class="px-1 py-1.5"><input v-model="item.quantity" type="number" min="0" :class="inputClass" /></td>
                                <td class="min-w-[12rem] px-1 py-1.5"><input v-model="item.remarks" type="text" :class="inputClass" /></td>
                                <td class="py-1.5 text-center">
                                    <button type="button" class="text-gray-400 hover:text-red-600" title="Remove equipment" @click="form.fire_equipment.splice(index, 1)">
                                        <Trash2 :size="15" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <button type="button" :class="[smallButton, 'mt-3']" @click="form.fire_equipment.push({ name: '', quantity: null, remarks: '' })">
                    <Plus :size="13" /> Add equipment
                </button>
                <p v-if="hasErrorPrefix('fire_equipment')" :class="errorText">Every equipment row needs a name and a whole-number quantity.</p>
            </section>

            <!-- Findings -->
            <section id="findings" :class="cardClass">
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <h3 class="flex items-center gap-2 text-base font-semibold text-gray-900 dark:text-gray-100">
                        <ListChecks :size="18" class="text-indigo-500" /> Audit Findings &amp; Corrective Action Plan
                    </h3>
                    <div class="ml-auto flex flex-wrap gap-1">
                        <span
                            v-for="r in ratingTotals"
                            :key="r.key"
                            :class="['rounded border px-2 py-0.5 text-xs font-semibold', ratingClasses[r.key]]"
                        >
                            {{ r.label }} {{ r.count }}
                        </span>
                        <span v-if="!ratingTotals.length" class="text-xs text-gray-500 dark:text-gray-400">No findings recorded yet</span>
                    </div>
                </div>

                <div class="space-y-4">
                    <div
                        v-for="category in template.categories"
                        :key="category"
                        class="rounded-lg border border-gray-200 dark:border-gray-700"
                    >
                        <div class="flex items-center justify-between gap-2 rounded-t-lg bg-gray-50 px-4 py-2.5 dark:bg-gray-900/40">
                            <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">
                                {{ category }}
                                <span class="ml-1 text-xs font-normal text-gray-500">({{ findingsIn(category).length }})</span>
                            </p>
                            <button type="button" :class="smallButton" @click="addFinding(category)">
                                <Plus :size="13" /> Add finding
                            </button>
                        </div>

                        <div v-if="findingsIn(category).length" class="divide-y divide-gray-100 dark:divide-gray-700">
                            <div
                                v-for="{ finding, index } in findingsIn(category)"
                                :key="finding._key"
                                class="space-y-3 p-4"
                                :data-error="hasErrorPrefix(`findings.${index}.`)"
                            >
                                <div class="flex flex-wrap items-center gap-2">
                                    <select
                                        v-model="finding.rating"
                                        :class="['rounded-md border py-1 pl-2 pr-8 text-xs font-semibold focus:ring-indigo-500', ratingClasses[finding.rating]]"
                                    >
                                        <option v-for="(label, value) in template.ratings" :key="value" :value="value">{{ label }}</option>
                                    </select>
                                    <select
                                        v-if="finding.rating !== 'compliant'"
                                        v-model="finding.status"
                                        class="rounded-md border-gray-300 py-1 pl-2 pr-8 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                    >
                                        <option value="open">CAP open</option>
                                        <option value="closed">CAP closed</option>
                                    </select>
                                    <button type="button" class="ml-auto text-gray-400 hover:text-red-600" title="Remove finding" @click="removeFinding(index)">
                                        <Trash2 :size="16" />
                                    </button>
                                </div>

                                <div>
                                    <label :class="labelClass">{{ finding.rating === 'compliant' ? 'Statement' : 'Audit finding / non-compliance' }} <span class="text-red-500">*</span></label>
                                    <textarea
                                        v-model="finding.finding"
                                        rows="2"
                                        :class="inputClass"
                                        :placeholder="finding.rating === 'compliant' ? 'e.g. No child labor found' : 'Describe what was observed'"
                                    ></textarea>
                                    <p v-if="err(`findings.${index}.finding`)" :class="errorText">{{ err(`findings.${index}.finding`) }}</p>
                                </div>

                                <div v-if="finding.rating !== 'compliant'" class="grid gap-3 md:grid-cols-[2fr_1fr_10rem]">
                                    <div>
                                        <label :class="labelClass">Factory corrective action plan</label>
                                        <textarea v-model="finding.corrective_action" rows="2" :class="inputClass"></textarea>
                                    </div>
                                    <div>
                                        <label :class="labelClass">Responsible person (name &amp; title)</label>
                                        <input v-model="finding.responsible_person" type="text" :class="inputClass" />
                                    </div>
                                    <div>
                                        <label :class="labelClass">Completion date</label>
                                        <input v-model="finding.target_date" type="date" :class="inputClass" />
                                        <p v-if="err(`findings.${index}.target_date`)" :class="errorText">{{ err(`findings.${index}.target_date`) }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Result -->
            <section id="result" :class="cardClass" :data-error="!!err('overall_result')">
                <h3 class="mb-4 flex items-center gap-2 text-base font-semibold text-gray-900 dark:text-gray-100">
                    <Award :size="18" class="text-indigo-500" /> Result and Comments
                </h3>
                <div
                    v-if="hasZeroTolerance && form.overall_result !== 'zero_tolerance'"
                    class="mb-4 flex items-start gap-2 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300"
                >
                    <AlertTriangle :size="18" class="mt-0.5 shrink-0" />
                    This audit contains Zero Tolerance findings. Check that the overall result reflects them.
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <label
                        v-for="(result, key) in template.results"
                        :key="key"
                        :class="[
                            'flex cursor-pointer flex-col gap-1 rounded-lg border-2 p-3 transition',
                            form.overall_result === key ? resultClasses[key] : 'border-gray-200 hover:border-gray-300 dark:border-gray-700 dark:hover:border-gray-600',
                        ]"
                    >
                        <input v-model="form.overall_result" type="radio" :value="key" class="sr-only" />
                        <span class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
                            <span :class="['h-3 w-3 rounded-full', resultSwatch[key]]"></span>
                            {{ result.label }}
                        </span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ result.color ? `${result.color} rating` : 'No color rating' }}</span>
                    </label>
                </div>
                <p v-if="err('overall_result')" :class="errorText">{{ err('overall_result') }}</p>
                <div class="mt-4">
                    <label :class="labelClass">Comments</label>
                    <textarea v-model="form.result_comments" rows="4" :class="inputClass"></textarea>
                </div>
            </section>

            <!-- Action bar -->
            <div class="sticky bottom-0 z-20 -mx-4 flex flex-wrap items-center gap-3 border-t border-gray-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-gray-700 dark:bg-gray-800/95 sm:mx-0 sm:rounded-t-lg sm:border sm:shadow-lg">
                <p v-if="errorCount" class="flex items-center gap-1.5 text-sm font-medium text-red-600 dark:text-red-400">
                    <AlertTriangle :size="16" /> {{ errorCount }} field{{ errorCount > 1 ? 's need' : ' needs' }} attention
                </p>
                <p v-else-if="form.isDirty" class="text-sm text-gray-500 dark:text-gray-400">Unsaved changes</p>
                <div class="ml-auto flex gap-2">
                    <button
                        type="button"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                        @click="submit('draft')"
                    >
                        <Save :size="15" /> Save Draft
                    </button>
                    <button
                        type="button"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-indigo-500 disabled:opacity-50"
                        @click="submit('complete')"
                    >
                        <CheckCircle2 :size="15" /> Complete Audit
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

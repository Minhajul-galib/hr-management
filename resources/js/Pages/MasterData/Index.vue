<script setup>
/**
 * Generic HR master-data screen. The server (MasterDataRegistry) describes the
 * columns and form fields; this page just renders them, so departments,
 * designations, grades, leave types, offences, ACR templates and document
 * types all share it.
 *
 * Small masters arrive whole and are filtered here. Large ones (definition.paginate)
 * arrive one page at a time: search, status and paging go back to the server.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import Pagination from '@/Components/MasterData/Pagination.vue';
import ImportDialog from '@/Components/MasterData/ImportDialog.vue';
import ImportResult from '@/Components/MasterData/ImportResult.vue';
import ImportReview from '@/Components/MasterData/ImportReview.vue';
import { Plus, Pencil, Trash2, Search, X, Download, FileSpreadsheet } from 'lucide-vue-next';
import { useSweetAlert } from '@/composables/useSweetAlert';

const props = defineProps({
    definition: { type: Object, required: true },
    records: { type: Array, default: () => [] },
    lookups: { type: Object, default: () => ({}) },
    // What this person may do here: { create, edit, delete, import, export }.
    can: { type: Object, default: () => ({}) },
    // Server-paged masters only.
    pagination: { type: Object, default: null },
    query: { type: Object, default: () => ({}) },
    stats: { type: Object, default: null },
    // Masters with Excel import only.
    importResult: { type: Object, default: null },
    importLimits: { type: Object, default: null },
});

// Full class strings so Tailwind can see them.
const BADGE_CLASSES = {
    green: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
    red: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
    yellow: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300',
    orange: 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-300',
    blue: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
    purple: 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300',
    gray: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
};
const badgeClass = (color) => BADGE_CLASSES[color] || BADGE_CLASSES.gray;

const inputClass =
    'block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';

const escapeHtml = (value) =>
    String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

const formatNumber = (value) => {
    if (value === null || value === undefined || value === '') return '—';
    const n = Number(value);
    return Number.isFinite(n) ? n.toLocaleString(undefined, { maximumFractionDigits: 2 }) : value;
};

// ----- table -----------------------------------------------------------------

const serverMode = computed(() => !!props.pagination);

const search = ref(props.query?.search ?? '');
const statusFilter = ref(props.query?.status ?? '');
const filters = ref({}); // extra dropdown filters, keyed by field (e.g. grade_type)
const loading = ref(false);

const fieldByKey = (key) => props.definition.fields.find((f) => f.key === key);

// A select's options are either fixed on the field or looked up from another master.
const optionsFor = (field) => field?.options ?? props.lookups[field?.key] ?? [];

const optionFor = (fieldKey, value) => optionsFor(fieldByKey(fieldKey)).find((o) => o.value === value);

const lookupLabels = computed(() => {
    const maps = {};
    for (const [key, options] of Object.entries(props.lookups)) {
        maps[key] = Object.fromEntries(options.map((o) => [o.value, o.label]));
    }
    return maps;
});

const tagLabels = (record, key) =>
    (record[key] || []).map((id) => lookupLabels.value[key]?.[id]).filter(Boolean);

const countOf = (value) => (Array.isArray(value) ? value.length : 0);

const filteredRecords = computed(() => {
    if (serverMode.value) return props.records; // already searched and paged by the server

    let rows = props.records;

    if (statusFilter.value) {
        rows = rows.filter((r) => (statusFilter.value === 'active' ? r.is_active : !r.is_active));
    }

    for (const [key, wanted] of Object.entries(filters.value)) {
        if (wanted) rows = rows.filter((r) => r[key] === wanted);
    }

    const words = search.value.toLowerCase().split(/\s+/).filter(Boolean);
    if (words.length) {
        rows = rows.filter((r) => {
            const haystack = props.definition.fields
                .map((f) => {
                    if (f.type === 'multiselect') return tagLabels(r, f.key).join(' ');
                    if (f.type === 'select') return optionFor(f.key, r[f.key])?.label ?? '';
                    if (f.type === 'text' || f.type === 'textarea') return r[f.key] ?? '';
                    return '';
                })
                .join(' ')
                .toLowerCase();
            return words.every((w) => haystack.includes(w));
        });
    }

    return rows;
});

// ----- server paging ---------------------------------------------------------

// Where the list is now. Sent along with create/update/delete/import so the
// redirect comes back to the same search and page.
const listParams = computed(() => {
    if (!serverMode.value) return {};
    const params = {
        search: props.query.search || undefined,
        status: props.query.status || undefined,
        per_page: props.query.per_page,
        page: props.pagination.current_page > 1 ? props.pagination.current_page : undefined,
    };
    return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== ''));
});

const reload = (changes = {}) => {
    const params = {
        search: search.value.trim() || undefined,
        status: statusFilter.value || undefined,
        per_page: props.query.per_page,
        ...changes,
    };
    if (params.page === 1) delete params.page;

    router.get(route(`${props.definition.key}.index`), Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== '')), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['records', 'pagination', 'query', 'stats'],
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
};

let searchTimer = null;
watch(search, (value) => {
    if (!serverMode.value || value.trim() === (props.query.search ?? '')) return;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => reload({ page: 1 }), 350);
});
onBeforeUnmount(() => clearTimeout(searchTimer));

watch(statusFilter, (value) => {
    if (serverMode.value && value !== (props.query.status ?? '')) reload({ page: 1 });
});

const goToPage = (page) => reload({ page });
const changePerPage = (perPage) => reload({ per_page: perPage, page: 1 });

// The Actions column (edit / delete) is only there when the role may do either.
const hasActions = computed(() => !!(props.can.edit || props.can.delete));

// Export what the list shows: same search and status, every page.
const exportUrl = computed(() => {
    const params = {};
    if (serverMode.value && props.query.search) params.search = props.query.search;
    if (serverMode.value && props.query.status) params.status = props.query.status;
    return route(`${props.definition.key}.export`, params);
});

const number = (n) => Number(n ?? 0).toLocaleString();

// ----- Excel import ----------------------------------------------------------

const showImport = ref(false);

// An uploaded file waiting for review: { file, rows, summary, token }. Nothing is saved until it is imported.
const review = ref(null);
const reviewPanel = ref(null);

const openReview = (preview) => {
    showImport.value = false;
    lastImport.value = null;
    review.value = { ...preview, token: Date.now() };
    nextTick(() => reviewPanel.value?.$el?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
};

// What the last upload did. It arrives once, right after the upload, and stays
// on the page until dismissed.
const lastImport = ref(props.importResult);
const importPanel = ref(null);

watch(
    () => props.importResult,
    (result) => {
        if (!result) return;
        lastImport.value = result;
        nextTick(() => importPanel.value?.$el?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    },
);

// ----- form ------------------------------------------------------------------

const showModal = ref(false);
const editing = ref(null);
const form = ref(null);

const blankValue = (field) => {
    if ('default' in field) return field.default;
    if (field.type === 'boolean') return false;
    if (field.type === 'multiselect') {
        // e.g. a new grade starts with every active salary type ticked
        return field.preselect_active ? (props.lookups[field.key] || []).filter((o) => o.active).map((o) => o.value) : [];
    }
    if (field.type === 'repeater') return field.required ? [{ ...field.item_default }] : [];
    return '';
};

const buildForm = (record) =>
    useForm(
        Object.fromEntries(
            props.definition.fields.map((field) => {
                let value = record ? record[field.key] : blankValue(field);

                if (field.type === 'repeater') value = (value || []).map((row) => ({ ...row }));
                else if (field.type === 'multiselect') value = [...(value || [])];
                else if (value === null || value === undefined) value = field.type === 'boolean' ? false : '';

                return [field.key, value];
            }),
        ),
    );

const openCreate = () => {
    editing.value = null;
    form.value = buildForm(null);
    showModal.value = true;
};

const openEdit = (record) => {
    editing.value = record;
    form.value = buildForm(record);
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: closeModal };
    const key = props.definition.key;

    if (editing.value) {
        form.value.put(route(`${key}.update`, { id: editing.value.id, ...listParams.value }), options);
    } else {
        form.value.post(route(`${key}.store`, listParams.value), options);
    }
};

const isVisible = (field) =>
    !field.show_if || Object.entries(field.show_if).every(([key, expected]) => form.value[key] === expected);

const addRow = (field) => form.value[field.key].push({ ...field.item_default });
const removeRow = (field, index) => form.value[field.key].splice(index, 1);

const repeaterTotal = (field) =>
    (form.value[field.key] || []).reduce((sum, row) => sum + (Number(row[field.total.key]) || 0), 0);

const repeaterTotalOk = (field) => Math.abs(repeaterTotal(field) - field.total.target) < 0.001;

/**
 * What the ticked options of a multiselect add up to at the minimum and maximum
 * basic (a grade's pay scale): one line per option, then the gross. Options that
 * are inactive are shown but not counted, as on the server.
 */
const breakdown = (field) => {
    const basics = ['min', 'max'].map((end) => Number(form.value[field.breakdown[end]]) || 0);
    const chosen = (props.lookups[field.key] || []).filter((o) => form.value[field.key].includes(o.value));

    const amount = (option, basic) =>
        option.calculation === 'percent_of_basic' ? Math.round(basic * option.rate) / 100 : option.rate;

    const lines = chosen.map((option) => ({
        key: option.value,
        label: option.label.replace(/ \(inactive\)$/, ''),
        rate: option.calculation === 'percent_of_basic' ? `${formatNumber(option.rate)}% of basic` : `${formatNumber(option.rate)} fixed`,
        active: option.active,
        amounts: basics.map((basic) => amount(option, basic)),
    }));

    return {
        basics,
        lines,
        gross: basics.map((basic, i) => basic + lines.filter((l) => l.active).reduce((sum, l) => sum + l.amounts[i], 0)),
    };
};

const repeaterGrid = (field) => ({
    gridTemplateColumns: `repeat(${field.item_fields.length}, minmax(0, 1fr)) 2rem`,
});

const rowError = (field, index, sub) => form.value.errors[`${field.key}.${index}.${sub.key}`];

// ----- delete ----------------------------------------------------------------

const remove = async (record) => {
    const { showDeleteConfirm } = useSweetAlert();
    const title = escapeHtml(record[props.definition.title_key]);

    const result = await showDeleteConfirm(
        `Delete ${escapeHtml(props.definition.singular.toLowerCase())} "<b>${title}</b>"?<br>` +
            'To keep it on record but stop using it, edit it and untick Active instead.',
    );

    if (result.isConfirmed) {
        router.delete(route(`${props.definition.key}.destroy`, { id: record.id, ...listParams.value }), { preserveScroll: true });
    }
};

// Navigating between masters reuses this page, so drop per-master UI state.
watch(
    () => props.definition.key,
    () => {
        clearTimeout(searchTimer);
        search.value = props.query?.search ?? '';
        statusFilter.value = props.query?.status ?? '';
        filters.value = {};
        showModal.value = false;
        showImport.value = false;
        review.value = null;
        lastImport.value = props.importResult;
        form.value = null;
    },
);
</script>

<template>
    <Head :title="definition.label" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                <div class="min-w-0">
                    <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ definition.label }}</h2>
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ definition.description }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2 sm:shrink-0 sm:justify-end">
                    <a
                        v-if="definition.import && can.export"
                        :href="exportUrl"
                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm transition duration-150 ease-in-out hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                        :title="`Download ${serverMode && (query.search || query.status) ? 'the filtered' : 'the full'} list as Excel`"
                    >
                        <Download :size="16" class="mr-1.5" />
                        Export
                    </a>
                    <SecondaryButton v-if="definition.import && can.import" type="button" @click="showImport = true">
                        <FileSpreadsheet :size="16" class="mr-1.5" />
                        Import Excel
                    </SecondaryButton>
                    <PrimaryButton v-if="can.create" type="button" @click="openCreate">
                        <Plus :size="16" class="mr-1.5" />
                        Add {{ definition.singular }}
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-4 lg:px-4 xl:max-w-[1440px] 2xl:max-w-[1600px]">
                <ImportReview
                    v-if="review && definition.import"
                    ref="reviewPanel"
                    :key="review.token"
                    :definition="definition"
                    :preview="review"
                    :check-url="route(`${definition.key}.import.check`)"
                    :import-url="route(`${definition.key}.import`, listParams)"
                    @close="review = null"
                    @imported="review = null"
                />

                <ImportResult
                    v-if="lastImport"
                    ref="importPanel"
                    :result="lastImport"
                    :match-label="definition.import?.match_label"
                    :plural="definition.label.toLowerCase()"
                    @dismiss="lastImport = null"
                />

                <!-- Totals (server-paged masters) -->
                <div v-if="stats" class="grid grid-cols-3 gap-4 px-4 sm:px-0">
                    <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Total {{ definition.label }}</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ number(stats.total) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Active</p>
                        <p class="mt-1 text-2xl font-semibold text-green-600 dark:text-green-400">{{ number(stats.active) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Inactive</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-500 dark:text-gray-400">{{ number(stats.total - stats.active) }}</p>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <!-- Filters -->
                        <div class="mb-6 flex flex-col md:flex-row md:items-center gap-2 md:gap-4 justify-between">
                            <div class="relative w-full md:w-1/3">
                                <Search :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                                <input
                                    v-model="search"
                                    type="text"
                                    :placeholder="serverMode ? 'Search by code, name or short name...' : `Search ${definition.label.toLowerCase()}...`"
                                    class="w-full pl-9 pr-8 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    autocomplete="off"
                                />
                                <button
                                    v-if="search"
                                    type="button"
                                    @click="search = ''"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                    aria-label="Clear search"
                                >
                                    <X :size="16" />
                                </button>
                            </div>
                            <div class="flex flex-col sm:flex-row gap-2 w-full md:w-auto">
                                <select
                                    v-for="key in definition.filters"
                                    :key="key"
                                    v-model="filters[key]"
                                    :aria-label="`Filter by ${fieldByKey(key)?.label}`"
                                    class="w-full md:w-48 px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                >
                                    <option value="">All {{ fieldByKey(key)?.label }}s</option>
                                    <option v-for="option in optionsFor(fieldByKey(key))" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                                <select
                                    v-model="statusFilter"
                                    class="w-full md:w-48 px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                >
                                    <option value="">All Statuses</option>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>

                        <!-- Table -->
                        <div :class="['overflow-x-auto transition-opacity', loading ? 'opacity-50' : '']" :aria-busy="loading">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-900/50">
                                    <tr>
                                        <th
                                            v-for="col in definition.columns"
                                            :key="col.key"
                                            class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"
                                        >
                                            {{ col.label }}
                                        </th>
                                        <th v-if="hasActions" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr v-for="record in filteredRecords" :key="record.id">
                                        <td
                                            v-for="col in definition.columns"
                                            :key="col.key"
                                            :class="[
                                                'px-6 py-4 text-sm',
                                                col.muted ? 'max-w-xs truncate text-gray-500 dark:text-gray-400' : 'whitespace-nowrap',
                                                col.strong ? 'font-medium text-gray-900 dark:text-gray-100' : 'dark:text-gray-300',
                                            ]"
                                            :title="col.muted ? record[col.key] : undefined"
                                        >
                                            <span
                                                v-if="col.type === 'badge'"
                                                :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium', badgeClass(optionFor(col.key, record[col.key])?.color)]"
                                            >
                                                {{ optionFor(col.key, record[col.key])?.label ?? record[col.key] }}
                                            </span>

                                            <span
                                                v-else-if="col.type === 'boolean'"
                                                :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium', badgeClass(col.colors[record[col.key] ? 0 : 1])]"
                                            >
                                                {{ col.labels[record[col.key] ? 0 : 1] }}
                                            </span>

                                            <template v-else-if="col.type === 'count'">
                                                {{ countOf(record[col.key]) }} {{ col.suffix }}
                                            </template>

                                            <div v-else-if="col.type === 'tags'" class="flex flex-wrap gap-1" :title="tagLabels(record, col.key).join(', ')">
                                                <template v-if="tagLabels(record, col.key).length">
                                                    <span
                                                        v-for="label in tagLabels(record, col.key).slice(0, 3)"
                                                        :key="label"
                                                        class="inline-flex items-center px-2 py-0.5 rounded text-xs bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300"
                                                    >
                                                        {{ label }}
                                                    </span>
                                                    <span v-if="tagLabels(record, col.key).length > 3" class="text-xs text-gray-500 dark:text-gray-400 self-center">
                                                        +{{ tagLabels(record, col.key).length - 3 }} more
                                                    </span>
                                                </template>
                                                <span v-else class="text-gray-400 dark:text-gray-500">—</span>
                                            </div>

                                            <template v-else-if="col.type === 'range'">
                                                {{ formatNumber(record[col.from]) }} – {{ formatNumber(record[col.to]) }}
                                            </template>

                                            <template v-else-if="col.type === 'rate'">
                                                {{ formatNumber(record[col.key]) }}{{ record[col.by] === col.percent ? '%' : '' }}
                                            </template>

                                            <template v-else-if="col.type === 'number'">{{ formatNumber(record[col.key]) }}</template>

                                            <template v-else>{{ record[col.key] || '—' }}</template>
                                        </td>

                                        <td v-if="hasActions" class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <div class="flex justify-end gap-3">
                                                <button
                                                    v-if="can.edit"
                                                    type="button"
                                                    @click="openEdit(record)"
                                                    class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300"
                                                    :title="`Edit ${definition.singular}`"
                                                >
                                                    <Pencil :size="16" />
                                                </button>
                                                <button
                                                    v-if="can.delete"
                                                    type="button"
                                                    @click="remove(record)"
                                                    class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300"
                                                    :title="`Delete ${definition.singular}`"
                                                >
                                                    <Trash2 :size="16" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <tr v-if="filteredRecords.length === 0">
                                        <td :colspan="definition.columns.length + (hasActions ? 1 : 0)" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                                            {{
                                                (serverMode ? stats?.total === 0 : records.length === 0)
                                                    ? `No ${definition.label.toLowerCase()} yet.`
                                                    : 'Nothing matches your search.'
                                            }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <Pagination
                            v-if="pagination"
                            class="mt-4"
                            :pagination="pagination"
                            :disabled="loading"
                            @page="goToPage"
                            @per-page="changePerPage"
                        />
                    </div>
                </div>
            </div>
        </div>

        <ImportDialog
            v-if="definition.import && can.import && importLimits"
            :show="showImport"
            :title="`Import ${definition.label.toLowerCase()} from Excel`"
            :preview-url="route(`${definition.key}.import.preview`)"
            :template-url="route(`${definition.key}.template`)"
            :limits="importLimits"
            :columns="definition.import.columns"
            :match-label="definition.import.match_label"
            :singular="definition.singular.toLowerCase()"
            :samples="definition.import.samples"
            @close="showImport = false"
            @previewed="openReview"
        />

        <!-- Create / edit -->
        <Modal :show="showModal" max-width="3xl" @close="closeModal">
            <form v-if="form" @submit.prevent="submit" class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ editing ? `Edit ${definition.singular}` : `Add ${definition.singular}` }}
                </h3>

                <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                    <template v-for="field in definition.fields" :key="field.key">
                        <div v-if="isVisible(field)" :class="field.full ? 'md:col-span-2' : ''">
                            <!-- Checkbox -->
                            <template v-if="field.type === 'boolean'">
                                <label class="flex items-center gap-2 mt-6">
                                    <input
                                        type="checkbox"
                                        v-model="form[field.key]"
                                        class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                    />
                                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ field.label }}</span>
                                </label>
                            </template>

                            <template v-else>
                                <InputLabel :for="`f_${field.key}`" :value="field.required ? `${field.label} *` : field.label" />

                                <!-- Text / number -->
                                <input
                                    v-if="field.type === 'text' || field.type === 'number'"
                                    :id="`f_${field.key}`"
                                    v-model="form[field.key]"
                                    :type="field.type === 'number' ? 'number' : 'text'"
                                    :min="field.min"
                                    :max="field.max"
                                    :step="field.step"
                                    :maxlength="field.maxlength"
                                    :placeholder="field.placeholder"
                                    :class="[inputClass, 'mt-1', field.uppercase ? 'uppercase placeholder:normal-case' : '', field.lowercase ? 'lowercase placeholder:normal-case' : '']"
                                    :autocapitalize="field.lowercase ? 'off' : undefined"
                                />

                                <textarea
                                    v-else-if="field.type === 'textarea'"
                                    :id="`f_${field.key}`"
                                    v-model="form[field.key]"
                                    rows="3"
                                    :class="[inputClass, 'mt-1']"
                                ></textarea>

                                <template v-else-if="field.type === 'select'">
                                    <select
                                        :id="`f_${field.key}`"
                                        v-model="form[field.key]"
                                        :class="[inputClass, 'mt-1']"
                                    >
                                        <option v-if="form[field.key] === '' || form[field.key] === null" value="" disabled>Select...</option>
                                        <option v-for="option in optionsFor(field)" :key="option.value" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                    <p v-if="!optionsFor(field).length" class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                                        Nothing to choose from yet. Add some first.
                                    </p>
                                </template>

                                <!-- Pick several from another master -->
                                <div
                                    v-else-if="field.type === 'multiselect'"
                                    class="mt-1 max-h-44 overflow-y-auto rounded-md border border-gray-300 dark:border-gray-600 p-2 grid grid-cols-1 sm:grid-cols-2 gap-1"
                                >
                                    <label
                                        v-for="option in lookups[field.key] || []"
                                        :key="option.value"
                                        class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"
                                    >
                                        <input
                                            type="checkbox"
                                            :value="option.value"
                                            v-model="form[field.key]"
                                            class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500"
                                        />
                                        {{ option.label }}
                                    </label>
                                    <p v-if="!(lookups[field.key] || []).length" class="text-sm text-gray-500 dark:text-gray-400 sm:col-span-2">
                                        Nothing to choose from yet.
                                    </p>
                                </div>

                                <!-- What the ticked options add up to (a grade's pay scale) -->
                                <div
                                    v-if="field.type === 'multiselect' && field.breakdown && breakdown(field).lines.length"
                                    class="mt-3 overflow-hidden rounded-md border border-gray-200 dark:border-gray-700"
                                >
                                    <table class="min-w-full text-sm">
                                        <thead class="bg-gray-50 text-xs uppercase tracking-wider text-gray-500 dark:bg-gray-900/50 dark:text-gray-400">
                                            <tr>
                                                <th class="px-3 py-2 text-left font-medium">Pay scale</th>
                                                <th class="px-3 py-2 text-left font-medium">Rate</th>
                                                <th class="px-3 py-2 text-right font-medium">At minimum basic</th>
                                                <th class="px-3 py-2 text-right font-medium">At maximum basic</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                            <tr>
                                                <td class="px-3 py-1.5 text-gray-700 dark:text-gray-300">Basic salary</td>
                                                <td class="px-3 py-1.5 text-gray-400">—</td>
                                                <td class="px-3 py-1.5 text-right tabular-nums">{{ formatNumber(breakdown(field).basics[0]) }}</td>
                                                <td class="px-3 py-1.5 text-right tabular-nums">{{ formatNumber(breakdown(field).basics[1]) }}</td>
                                            </tr>
                                            <tr v-for="line in breakdown(field).lines" :key="line.key" :class="line.active ? '' : 'text-gray-400 line-through dark:text-gray-500'">
                                                <td class="px-3 py-1.5">
                                                    {{ line.label }}
                                                    <span v-if="!line.active" class="no-underline text-xs">(inactive — not counted)</span>
                                                </td>
                                                <td class="px-3 py-1.5">{{ line.rate }}</td>
                                                <td class="px-3 py-1.5 text-right tabular-nums">{{ formatNumber(line.amounts[0]) }}</td>
                                                <td class="px-3 py-1.5 text-right tabular-nums">{{ formatNumber(line.amounts[1]) }}</td>
                                            </tr>
                                            <tr class="bg-gray-50 font-semibold dark:bg-gray-900/50">
                                                <td class="px-3 py-2" colspan="2">Gross salary</td>
                                                <td class="px-3 py-2 text-right tabular-nums">{{ formatNumber(breakdown(field).gross[0]) }}</td>
                                                <td class="px-3 py-2 text-right tabular-nums">{{ formatNumber(breakdown(field).gross[1]) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Editable list of rows -->
                                <div v-else-if="field.type === 'repeater'" class="mt-1 space-y-2">
                                    <div
                                        v-if="form[field.key].length"
                                        class="grid gap-2 text-xs font-medium text-gray-500 dark:text-gray-400"
                                        :style="repeaterGrid(field)"
                                    >
                                        <span v-for="sub in field.item_fields" :key="sub.key">{{ sub.label }}</span>
                                        <span></span>
                                    </div>

                                    <div v-for="(row, index) in form[field.key]" :key="index">
                                        <div class="grid gap-2 items-center" :style="repeaterGrid(field)">
                                            <template v-for="sub in field.item_fields" :key="sub.key">
                                                <select v-if="sub.type === 'select'" v-model="row[sub.key]" :class="inputClass" :aria-label="sub.label">
                                                    <option v-for="option in sub.options" :key="option.value" :value="option.value">
                                                        {{ option.label }}
                                                    </option>
                                                </select>
                                                <input
                                                    v-else
                                                    v-model="row[sub.key]"
                                                    :type="sub.type === 'number' ? 'number' : 'text'"
                                                    :min="sub.min"
                                                    :max="sub.max"
                                                    :step="sub.step"
                                                    :placeholder="sub.placeholder"
                                                    :class="inputClass"
                                                    :aria-label="sub.label"
                                                />
                                            </template>
                                            <button
                                                type="button"
                                                @click="removeRow(field, index)"
                                                class="text-gray-400 hover:text-red-600 dark:hover:text-red-400"
                                                :title="`Remove row ${index + 1}`"
                                            >
                                                <X :size="18" />
                                            </button>
                                        </div>
                                        <div class="grid gap-2" :style="repeaterGrid(field)">
                                            <p v-for="sub in field.item_fields" :key="sub.key" class="text-xs text-red-600 dark:text-red-400">
                                                {{ rowError(field, index, sub) }}
                                            </p>
                                            <span></span>
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-between">
                                        <button
                                            type="button"
                                            @click="addRow(field)"
                                            class="inline-flex items-center gap-1 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300"
                                        >
                                            <Plus :size="14" /> {{ field.add_label || 'Add row' }}
                                        </button>
                                        <span
                                            v-if="field.total"
                                            :class="['text-sm font-medium', repeaterTotalOk(field) ? 'text-green-600 dark:text-green-400' : 'text-amber-600 dark:text-amber-400']"
                                        >
                                            {{ field.total.label }}: {{ formatNumber(repeaterTotal(field)) }} / {{ field.total.target }}
                                        </span>
                                    </div>
                                </div>

                                <p v-if="field.help" class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ field.help }}</p>
                            </template>

                            <InputError :message="form.errors[field.key]" class="mt-1" />
                        </div>
                    </template>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton type="button" @click="closeModal">Cancel</SecondaryButton>
                    <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                        {{ editing ? 'Save changes' : `Create ${definition.singular}` }}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>

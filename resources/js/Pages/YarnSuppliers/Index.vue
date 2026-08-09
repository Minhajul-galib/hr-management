<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import { useSweetAlert } from '@/composables/useSweetAlert';
import { Factory, Pencil, Plus, Trash2, RotateCcw } from 'lucide-vue-next';

defineProps({
    suppliers: {
        type: Array,
        default: () => [],
    },
    showTrashed: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();
const { showConfirm, showDeleteConfirm } = useSweetAlert();

const permissions = computed(() => page.props.auth?.permissions || []);
const roles = computed(() => page.props.auth?.roles || []);
const isSuperAdmin = computed(() => roles.value.includes('Super System Admin'));

// Legacy umbrella permission: still grants full create/edit/delete access.
const canManage = computed(
    () =>
        isSuperAdmin.value ||
        permissions.value.includes('manage yarn suppliers') ||
        permissions.value.includes('manage bookings'),
);

const canCreate = computed(() => canManage.value || permissions.value.includes('create yarn suppliers'));
const canEdit = computed(() => canManage.value || permissions.value.includes('edit yarn suppliers'));
const canDelete = computed(() => canManage.value || permissions.value.includes('delete yarn suppliers'));
const canAnyAction = computed(() => canCreate.value || canEdit.value || canDelete.value);

const showTrash = computed(() => Boolean(page.props.showTrashed));
const toggleTrashHref = computed(() => (showTrash.value ? route('yarn-suppliers.index') : route('yarn-suppliers.index', { trash: 1 })));

const modalOpen = ref(false);
const editingId = ref(null);
const tableFilters = reactive({
    keyword: '',
    name: '',
    country: '',
    excel_input: '',
    code: '',
});

// Suggestion state
const activeSuggestField = ref(null);
const suggestions = computed(() => {
    const field = activeSuggestField.value;
    if (!field) return [];
    const q = normalizeText(tableFilters[field]);
    if (!q) return [];
    const all = page.props.suppliers ?? [];
    const seen = new Set();
    const results = [];
    for (const row of all) {
        const val = String(row[field] ?? '').trim();
        if (!val) continue;
        const normalized = val.toLowerCase();
        if (normalized.includes(q) && !seen.has(normalized)) {
            seen.add(normalized);
            results.push(val);
            if (results.length >= 8) break;
        }
    }
    return results;
});

function applySuggestion(field, val) {
    tableFilters[field] = val;
    activeSuggestField.value = null;
}

function onFieldFocus(field) {
    activeSuggestField.value = field;
}

function onFieldBlur() {
    // Delay so click on suggestion fires first
    setTimeout(() => { activeSuggestField.value = null; }, 150);
}

const form = useForm({
    name: '',
    alphabet: '',
    code: '',
    country: '',
    excel_input: '',
    contact: '',
    is_active: true,
});


const inputCls =
    'mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';

const normalizeText = (value) => String(value ?? '').toLowerCase().trim();

const hasActiveFilters = computed(() =>
    Object.values(tableFilters).some((value) => normalizeText(value) !== ''),
);

const filteredSuppliers = computed(() => {
    const keyword = normalizeText(tableFilters.keyword);

    return (page.props.suppliers ?? []).filter((row) => {
        const fields = {
            alphabet: normalizeText(row.alphabet),
            name: normalizeText(row.name),
            country: normalizeText(row.country),
            excel_input: normalizeText(row.excel_input),
            contact: normalizeText(row.contact),
            code: normalizeText(row.code),
        };

        const keywordMatched = !keyword || Object.values(fields).some((value) => value.includes(keyword));

        return keywordMatched
            && (!normalizeText(tableFilters.name) || fields.name.includes(normalizeText(tableFilters.name)))
            && (!normalizeText(tableFilters.country) || fields.country.includes(normalizeText(tableFilters.country)))
            && (!normalizeText(tableFilters.excel_input) || fields.excel_input.includes(normalizeText(tableFilters.excel_input)))
            && (!normalizeText(tableFilters.code) || fields.code.includes(normalizeText(tableFilters.code)));
    });
});

const selectedIds = ref([]);

watch(
    () => (page.props.suppliers ?? []).map((r) => r.id).join(',') + '|' + String(showTrash.value),
    () => {
        selectedIds.value = [];
    },
);

const allVisibleSelected = computed(() => {
    if (!filteredSuppliers.value.length) return false;
    return filteredSuppliers.value.every((r) => selectedIds.value.includes(r.id));
});

function toggleSelectAllVisible() {
    if (!filteredSuppliers.value.length) return;
    if (allVisibleSelected.value) {
        const visible = new Set(filteredSuppliers.value.map((r) => r.id));
        selectedIds.value = selectedIds.value.filter((id) => !visible.has(id));
        return;
    }
    const merged = new Set(selectedIds.value);
    filteredSuppliers.value.forEach((r) => merged.add(r.id));
    selectedIds.value = Array.from(merged);
}

function toggleRowSelection(id) {
    if (selectedIds.value.includes(id)) {
        selectedIds.value = selectedIds.value.filter((x) => x !== id);
        return;
    }
    selectedIds.value = [...selectedIds.value, id];
}

function clearSelection() {
    selectedIds.value = [];
}

function resetFilters() {
    Object.keys(tableFilters).forEach((key) => {
        tableFilters[key] = '';
    });
}

function openCreate() {
    editingId.value = null;
    form.reset();
    form.is_active = true;
    form.clearErrors();
    modalOpen.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    form.name = row.name ?? '';
    form.alphabet = row.alphabet ?? '';
    form.code = row.code ?? '';
    form.country = row.country ?? '';
    form.excel_input = row.excel_input ?? '';
    form.contact = row.contact ?? '';
    form.is_active = row.is_active !== false;
    form.clearErrors();
    modalOpen.value = true;
}

function closeModal() {
    modalOpen.value = false;
    editingId.value = null;
}

function submitModal() {
    if (editingId.value ? !canEdit.value : !canCreate.value) return;
    if (editingId.value) {
        form.put(route('yarn-suppliers.update', editingId.value), {
            preserveScroll: true,
            onSuccess: closeModal,
        });
    } else {
        form.post(route('yarn-suppliers.store'), {
            preserveScroll: true,
            onSuccess: closeModal,
        });
    }
}

async function trashSupplier(row) {
    if (!canDelete.value || showTrash.value) return;
    const r = await showDeleteConfirm(`Delete yarn supplier "${row.name}"?<br><span class="text-sm text-gray-500">It will be moved to Trash.</span>`);
    if (!r?.isConfirmed) return;
    router.delete(route('yarn-suppliers.destroy', row.id), { preserveScroll: true, preserveState: true });
}

async function restoreSupplier(row) {
    if (!canDelete.value || !showTrash.value) return;
    const r = await showConfirm(`Restore yarn supplier "${row.name}"?`, 'Confirm');
    if (!r?.isConfirmed) return;
    router.post(route('yarn-suppliers.restore', row.id), {}, { preserveScroll: true, preserveState: true });
}

async function forceDeleteSupplier(row) {
    if (!canDelete.value || !showTrash.value) return;
    const r = await showDeleteConfirm(
        `Permanently delete yarn supplier "${row.name}"?<br><span class="text-sm text-red-500 font-semibold">This action cannot be undone.</span>`,
    );
    if (!r?.isConfirmed) return;
    router.delete(route('yarn-suppliers.force-destroy', row.id), { preserveScroll: true, preserveState: true });
}

async function bulkTrashSelected() {
    if (!canDelete.value || showTrash.value || !selectedIds.value.length) return;
    const r = await showDeleteConfirm(
        `Delete <strong>${selectedIds.value.length}</strong> suppliers?<br><span class="text-sm text-gray-500">They will be moved to Trash.</span>`,
    );
    if (!r?.isConfirmed) return;
    router.delete(route('yarn-suppliers.bulk-destroy'), {
        data: { ids: [...selectedIds.value] },
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { selectedIds.value = []; },
    });
}

async function bulkRestoreSelected() {
    if (!canDelete.value || !showTrash.value || !selectedIds.value.length) return;
    const r = await showConfirm(`Restore ${selectedIds.value.length} suppliers?`, 'Confirm');
    if (!r?.isConfirmed) return;
    router.post(
        route('yarn-suppliers.bulk-restore'),
        { ids: [...selectedIds.value] },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => { selectedIds.value = []; },
        },
    );
}

async function bulkForceDeleteSelected() {
    if (!canDelete.value || !showTrash.value || !selectedIds.value.length) return;
    const r = await showDeleteConfirm(
        `Permanently delete <strong>${selectedIds.value.length}</strong> suppliers?<br><span class="text-sm text-red-500 font-semibold">This action cannot be undone.</span>`,
    );
    if (!r?.isConfirmed) return;
    router.delete(route('yarn-suppliers.bulk-force-destroy'), {
        data: { ids: [...selectedIds.value] },
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { selectedIds.value = []; },
    });
}
</script>

<template>
    <Head title="Yarn Suppliers" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                    <Factory class="text-indigo-600 dark:text-indigo-400 shrink-0" :size="22" />
                    Yarn Suppliers
                </h2>
                <div class="flex flex-wrap gap-2">
                    <Link
                        v-if="canManage"
                        :href="route('yarn-suppliers.import.page')"
                        class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                    >
                        Import Excel
                    </Link>
                    <Link
                        v-if="canDelete"
                        :href="toggleTrashHref"
                        class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                    >
                        <Trash2 :size="18" />
                        {{ showTrash ? 'Back to List' : 'Trash' }}
                    </Link>
                    <PrimaryButton
                        v-if="canCreate && !showTrash"
                        type="button"
                        class="inline-flex items-center gap-2 !normal-case tracking-normal font-semibold"
                        @click="openCreate"
                    >
                        <Plus :size="18" /> Add supplier
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="py-6 sm:py-8">
            <div class="mx-auto max-w-7xl sm:px-4 lg:px-4 xl:max-w-[1440px] 2xl:max-w-[1600px] space-y-6">
                <div class="filter-panel">
                    <!-- Header -->
                    <div class="filter-panel-header">
                        <div class="flex items-center gap-2.5">
                            <div class="filter-icon-wrap">
                                <svg viewBox="0 0 16 16" fill="none" class="w-3.5 h-3.5"><path d="M2 4h12M4 8h8M6 12h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            </div>
                            <div>
                                <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100 leading-tight">Filters</h3>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400 leading-tight mt-0.5">Live column-wise filtering</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="filter-count-badge" :class="filteredSuppliers.length < suppliers.length ? 'filter-count-badge--active' : ''">
                                <span class="filter-count-dot" :class="filteredSuppliers.length < suppliers.length ? 'bg-indigo-500' : 'bg-gray-300 dark:bg-gray-600'" />
                                <span class="tabular-nums">{{ filteredSuppliers.length }}<span class="text-gray-400 dark:text-gray-500 font-normal"> / {{ suppliers.length }}</span></span>
                            </div>
                            <button
                                v-if="canDelete && selectedIds.length"
                                type="button"
                                class="filter-reset-btn filter-reset-btn--active"
                                @click="clearSelection"
                            >
                                Clear Selection ({{ selectedIds.length }})
                            </button>
                            <button
                                v-if="canDelete && selectedIds.length && !showTrash"
                                type="button"
                                class="filter-reset-btn filter-reset-btn--active"
                                @click="bulkTrashSelected"
                            >
                                <Trash2 :size="14" /> Delete Selected
                            </button>
                            <button
                                v-if="canDelete && selectedIds.length && showTrash"
                                type="button"
                                class="filter-reset-btn filter-reset-btn--active"
                                @click="bulkRestoreSelected"
                            >
                                <RotateCcw :size="14" /> Restore Selected
                            </button>
                            <button
                                v-if="canDelete && selectedIds.length && showTrash"
                                type="button"
                                class="filter-reset-btn filter-reset-btn--active"
                                @click="bulkForceDeleteSelected"
                            >
                                <Trash2 :size="14" /> Delete Permanently
                            </button>
                            <button
                                type="button"
                                class="filter-reset-btn"
                                :disabled="!hasActiveFilters"
                                :class="hasActiveFilters ? 'filter-reset-btn--active' : 'filter-reset-btn--idle'"
                                @click="resetFilters"
                            >
                                <svg viewBox="0 0 14 14" fill="none" class="w-3 h-3"><path d="M2 7a5 5 0 1 0 1-3M2 2v3h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                Reset
                            </button>
                        </div>
                    </div>

                    <!-- Inputs grid -->
                    <div class="filter-inputs-grid">

                        <!-- Global search -->
                        <div class="filter-field lg:col-span-2">
                            <label class="filter-label" for="supplier_keyword">
                                <svg viewBox="0 0 14 14" fill="none" class="w-3 h-3"><circle cx="6" cy="6" r="4" stroke="currentColor" stroke-width="1.6"/><path d="M10 10l2.5 2.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                Global Search
                            </label>
                            <div class="filter-input-wrap filter-input-wrap--search">
                                <input
                                    id="supplier_keyword"
                                    v-model="tableFilters.keyword"
                                    type="text"
                                    class="filter-input"
                                    placeholder="Search anything across all columns…"
                                    autocomplete="off"
                                />
                                <button v-if="tableFilters.keyword" type="button" class="filter-clear-btn" @click="tableFilters.keyword = ''">
                                    <svg viewBox="0 0 12 12" fill="none" class="w-2.5 h-2.5"><path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                </button>
                            </div>
                        </div>

                        <!-- Code -->
                        <div class="filter-field">
                            <label class="filter-label" for="supplier_code_filter">Supplier Code</label>
                            <div class="filter-input-wrap" :class="activeSuggestField === 'code' && suggestions.length ? 'filter-input-wrap--open' : ''">
                                <input
                                    id="supplier_code_filter"
                                    v-model="tableFilters.code"
                                    type="text"
                                    class="filter-input font-mono"
                                    placeholder="e.g. SUP-001"
                                    autocomplete="off"
                                    @focus="onFieldFocus('code')"
                                    @blur="onFieldBlur"
                                />
                                <button v-if="tableFilters.code" type="button" class="filter-clear-btn" @click="tableFilters.code = ''">
                                    <svg viewBox="0 0 12 12" fill="none" class="w-2.5 h-2.5"><path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                </button>
                                <!-- Suggestions -->
                                <div v-if="activeSuggestField === 'code' && suggestions.length" class="suggest-dropdown">
                                    <button
                                        v-for="s in suggestions" :key="s"
                                        type="button"
                                        class="suggest-item font-mono"
                                        @mousedown.prevent="applySuggestion('code', s)"
                                    >{{ s }}</button>
                                </div>
                            </div>
                        </div>

                        <!-- Supplier Name -->
                        <div class="filter-field">
                            <label class="filter-label" for="supplier_name_filter">Supplier Name</label>
                            <div class="filter-input-wrap" :class="activeSuggestField === 'name' && suggestions.length ? 'filter-input-wrap--open' : ''">
                                <input
                                    id="supplier_name_filter"
                                    v-model="tableFilters.name"
                                    type="text"
                                    class="filter-input"
                                    placeholder="Supplier name…"
                                    autocomplete="off"
                                    @focus="onFieldFocus('name')"
                                    @blur="onFieldBlur"
                                />
                                <button v-if="tableFilters.name" type="button" class="filter-clear-btn" @click="tableFilters.name = ''">
                                    <svg viewBox="0 0 12 12" fill="none" class="w-2.5 h-2.5"><path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                </button>
                                <div v-if="activeSuggestField === 'name' && suggestions.length" class="suggest-dropdown">
                                    <button
                                        v-for="s in suggestions" :key="s"
                                        type="button"
                                        class="suggest-item"
                                        @mousedown.prevent="applySuggestion('name', s)"
                                    >{{ s }}</button>
                                </div>
                            </div>
                        </div>

                        <!-- Country -->
                        <div class="filter-field">
                            <label class="filter-label" for="supplier_country_filter">Origin / Country</label>
                            <div class="filter-input-wrap" :class="activeSuggestField === 'country' && suggestions.length ? 'filter-input-wrap--open' : ''">
                                <input
                                    id="supplier_country_filter"
                                    v-model="tableFilters.country"
                                    type="text"
                                    class="filter-input"
                                    placeholder="Country…"
                                    autocomplete="off"
                                    @focus="onFieldFocus('country')"
                                    @blur="onFieldBlur"
                                />
                                <button v-if="tableFilters.country" type="button" class="filter-clear-btn" @click="tableFilters.country = ''">
                                    <svg viewBox="0 0 12 12" fill="none" class="w-2.5 h-2.5"><path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                </button>
                                <div v-if="activeSuggestField === 'country' && suggestions.length" class="suggest-dropdown">
                                    <button
                                        v-for="s in suggestions" :key="s"
                                        type="button"
                                        class="suggest-item"
                                        @mousedown.prevent="applySuggestion('country', s)"
                                    >{{ s }}</button>
                                </div>
                            </div>
                        </div>

                        <!-- Excel Input -->
                        <div class="filter-field">
                            <label class="filter-label" for="supplier_excel_filter">Excel Input</label>
                            <div class="filter-input-wrap" :class="activeSuggestField === 'excel_input' && suggestions.length ? 'filter-input-wrap--open' : ''">
                                <input
                                    id="supplier_excel_filter"
                                    v-model="tableFilters.excel_input"
                                    type="text"
                                    class="filter-input"
                                    placeholder="Paste from Excel…"
                                    autocomplete="off"
                                    @focus="onFieldFocus('excel_input')"
                                    @blur="onFieldBlur"
                                />
                                <button v-if="tableFilters.excel_input" type="button" class="filter-clear-btn" @click="tableFilters.excel_input = ''">
                                    <svg viewBox="0 0 12 12" fill="none" class="w-2.5 h-2.5"><path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                </button>
                                <div v-if="activeSuggestField === 'excel_input' && suggestions.length" class="suggest-dropdown">
                                    <button
                                        v-for="s in suggestions" :key="s"
                                        type="button"
                                        class="suggest-item"
                                        @mousedown.prevent="applySuggestion('excel_input', s)"
                                    >{{ s }}</button>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <div
                    class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800"
                >
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900/50">
                                <tr>
                                    <th
                                        v-if="canDelete"
                                        class="w-10 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400"
                                    >
                                        <input
                                            type="checkbox"
                                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700"
                                            :checked="allVisibleSelected"
                                            @change="toggleSelectAllVisible"
                                        />
                                    </th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400"
                                    >
                                        Short Name
                                    </th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400"
                                    >
                                        Supplier Name
                                    </th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400"
                                    >
                                        Origin
                                    </th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400"
                                    >
                                        Excel Input
                                    </th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400"
                                    >
                                        Contact
                                    </th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400"
                                    >
                                        Supplier Code
                                    </th>
                                    <th
                                        v-if="canAnyAction"
                                        class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400"
                                    >
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800"
                            >
                                <tr v-if="!filteredSuppliers.length">
                                    <td
                                        :colspan="(canDelete ? 1 : 0) + (canAnyAction ? 1 : 0) + 6"
                                        class="px-6 py-16 text-center text-sm text-gray-500 dark:text-gray-400"
                                    >
                                        <div class="flex flex-col items-center gap-2">
                                            <Factory class="opacity-30" :size="40" stroke-width="1.25" />
                                            <p class="font-medium text-gray-700 dark:text-gray-300">
                                                {{ hasActiveFilters ? 'No supplier matched the current filters' : 'No yarn suppliers yet' }}
                                            </p>
                                            <button
                                                v-if="canCreate && !hasActiveFilters && !showTrash"
                                                type="button"
                                                class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 text-sm font-medium"
                                                @click="openCreate"
                                            >
                                                Add your first supplier
                                            </button>
                                            <button
                                                v-else-if="hasActiveFilters"
                                                type="button"
                                                class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 text-sm font-medium"
                                                @click="resetFilters"
                                            >
                                                Clear filters
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr
                                    v-for="s in filteredSuppliers"
                                    :key="s.id"
                                    class="transition-colors hover:bg-gray-50/80 dark:hover:bg-gray-700/40"
                                >
                                    <td v-if="canDelete" class="w-10 px-4 py-3">
                                        <input
                                            type="checkbox"
                                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700"
                                            :checked="selectedIds.includes(s.id)"
                                            @change="toggleRowSelection(s.id)"
                                        />
                                    </td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ s.alphabet || '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ s.name }}
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                                        {{ s.country || '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                                        {{ s.excel_input || '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                                        {{ s.contact || '—' }}
                                    </td>
                                    <td
                                        class="px-4 py-3 font-mono text-sm text-gray-700 dark:text-gray-200 whitespace-nowrap"
                                    >
                                        {{ s.code || '—' }}
                                    </td>
                                    <td v-if="canAnyAction" class="px-4 py-3 text-right whitespace-nowrap">
                                        <template v-if="!showTrash">
                                            <button
                                                v-if="canEdit"
                                                type="button"
                                                class="mr-2 inline-flex rounded-lg p-2 text-indigo-600 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-indigo-950/40"
                                                title="Edit"
                                                @click="openEdit(s)"
                                            >
                                                <Pencil :size="16" />
                                            </button>
                                            <button
                                                v-if="canDelete"
                                                type="button"
                                                class="inline-flex rounded-lg p-2 text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                                                title="Move to Trash"
                                                @click="trashSupplier(s)"
                                            >
                                                <Trash2 :size="16" />
                                            </button>
                                        </template>
                                        <template v-else>
                                            <button
                                                v-if="canDelete"
                                                type="button"
                                                class="mr-2 inline-flex rounded-lg p-2 text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-emerald-950/30"
                                                title="Restore"
                                                @click="restoreSupplier(s)"
                                            >
                                                <RotateCcw :size="16" />
                                            </button>
                                            <button
                                                v-if="canDelete"
                                                type="button"
                                                class="inline-flex rounded-lg p-2 text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950/30"
                                                title="Delete Permanently"
                                                @click="forceDeleteSupplier(s)"
                                            >
                                                <Trash2 :size="16" />
                                            </button>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <Modal :show="modalOpen" max-width="lg" @close="closeModal">
            <div
                class="border-b border-gray-200 bg-gray-50 px-6 py-4 dark:border-gray-700 dark:bg-gray-900/50"
            >
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ editingId ? 'Edit supplier' : 'New supplier' }}
                </h3>
            </div>
            <form class="space-y-4 p-6 dark:bg-gray-800" @submit.prevent="submitModal">
                <div>
                    <InputLabel for="name" value="Supplier Name *" />
                    <TextInput id="name" v-model="form.name" type="text" required autocomplete="organization" />
                    <InputError class="mt-1" :message="form.errors.name" />
                </div>
                <div>
                    <InputLabel for="alphabet" value="Short Name" />
                    <TextInput id="alphabet" v-model="form.alphabet" type="text" class="uppercase" maxlength="5" />
                    <InputError class="mt-1" :message="form.errors.alphabet" />
                </div>
                <div>
                    <InputLabel for="country" value="Origin" />
                    <input id="country" v-model="form.country" type="text" :class="inputCls" />
                    <InputError class="mt-1" :message="form.errors.country" />
                </div>
                <div>
                    <InputLabel for="excel_input" value="Excel Input" />
                    <TextInput id="excel_input" v-model="form.excel_input" type="text" />
                    <InputError class="mt-1" :message="form.errors.excel_input" />
                </div>
                <div>
                    <InputLabel for="contact" value="Contact (optional)" />
                    <TextInput id="contact" v-model="form.contact" type="text" />
                    <InputError class="mt-1" :message="form.errors.contact" />
                </div>
                <div>
                    <InputLabel for="code" value="Supplier Code" />
                    <TextInput id="code" v-model="form.code" type="text" class="font-mono" maxlength="50" />
                    <InputError class="mt-1" :message="form.errors.code" />
                </div>
                <label
                    v-if="editingId"
                    class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700"
                >
                    <input
                        v-model="form.is_active"
                        type="checkbox"
                        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700"
                    />
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Active</span>
                </label>
                <InputError class="mt-1" :message="form.errors.is_active" />

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-5 dark:border-gray-700">
                    <SecondaryButton type="button" @click="closeModal">Cancel</SecondaryButton>
                    <PrimaryButton
                        type="submit"
                        class="!normal-case tracking-normal font-semibold"
                        :disabled="form.processing"
                        :class="{ 'opacity-50': form.processing }"
                    >
                        Save
                    </PrimaryButton>
                </div>
            </form>
        </Modal>

    </AuthenticatedLayout>
</template>

<style scoped>
/* ── Filter Panel ── */
.filter-panel {
    border-radius: 12px;
    border: 1.5px solid #d1d5db;
    background: #ffffff;
    overflow: visible;
    box-shadow: 0 2px 8px rgba(0,0,0,0.07);
}
:global(.dark .filter-panel) {
    background: #1e293b;
    border-color: #334155;
    box-shadow: 0 2px 8px rgba(0,0,0,0.35);
}

/* Header */
.filter-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    padding: 12px 16px;
    background: #f8fafc;
    border-bottom: 1.5px solid #e5e7eb;
    border-radius: 12px 12px 0 0;
}
:global(.dark .filter-panel-header) {
    background: #0f172a;
    border-bottom-color: #334155;
}

.filter-icon-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 7px;
    background: #e0e7ff;
    color: #4f46e5;
    flex-shrink: 0;
}
:global(.dark .filter-icon-wrap) {
    background: rgba(99,102,241,0.2);
    color: #818cf8;
}

/* Count badge */
.filter-count-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    background: #f1f5f9;
    border: 1.5px solid #e2e8f0;
    color: #374151;
}
.filter-count-badge--active {
    background: #eef2ff;
    border-color: #a5b4fc;
    color: #3730a3;
}
:global(.dark .filter-count-badge) {
    background: #1e293b;
    border-color: #475569;
    color: #e2e8f0;
}
:global(.dark .filter-count-badge--active) {
    background: rgba(99,102,241,0.12);
    border-color: #6366f1;
    color: #a5b4fc;
}
.filter-count-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
}

/* Reset btn */
.filter-reset-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 5px 11px;
    border-radius: 7px;
    font-size: 11px;
    font-weight: 700;
    border: 1.5px solid;
    cursor: pointer;
    transition: all 0.12s ease;
}
.filter-reset-btn--idle {
    border-color: #e2e8f0;
    background: #f8fafc;
    color: #9ca3af;
    opacity: 0.5;
    cursor: not-allowed;
}
.filter-reset-btn--active {
    border-color: #fca5a5;
    background: #fff1f1;
    color: #dc2626;
}
.filter-reset-btn--active:hover {
    background: #fee2e2;
    border-color: #f87171;
    transform: translateY(-1px);
}
:global(.dark .filter-reset-btn--active) {
    background: rgba(220,38,38,0.08);
    border-color: rgba(248,113,113,0.4);
    color: #fca5a5;
}

/* Inputs grid */
.filter-inputs-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
    gap: 10px;
    padding: 14px 16px 16px;
    background: #ffffff;
    border-radius: 0 0 12px 12px;
    overflow: visible;
}
:global(.dark .filter-inputs-grid) {
    background: #1e293b;
}

.filter-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    position: relative;
}

.filter-label {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: #6b7280;
}
:global(.dark .filter-label) {
    color: #64748b;
}

/* Input wrapper */
.filter-input-wrap {
    position: relative;
    display: flex;
    align-items: center;
    border-radius: 8px;
    border: 1.5px solid #d1d5db;
    background: #ffffff;
    transition: border-color 0.12s, box-shadow 0.12s;
}
.filter-input-wrap:focus-within {
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
}
.filter-input-wrap--search {
    border-color: #a5b4fc;
    background: #fafbff;
}
.filter-input-wrap--search:focus-within {
    border-color: #4f46e5;
    box-shadow: 0 0 0 3px rgba(99,102,241,0.18);
}
.filter-input-wrap--open {
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
    border-bottom-left-radius: 0;
    border-bottom-right-radius: 0;
    border-bottom-color: transparent;
}
:global(.dark .filter-input-wrap) {
    background: #0f172a;
    border-color: #475569;
}
:global(.dark .filter-input-wrap:focus-within) {
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99,102,241,0.2);
}

.filter-input {
    flex: 1;
    min-width: 0;
    padding: 7px 10px;
    background: transparent;
    border: none;
    outline: none;
    font-size: 13px;
    font-weight: 500;
    color: #111827;
    border-radius: inherit;
}
.filter-input::placeholder {
    color: #9ca3af;
    font-weight: 400;
    font-size: 12px;
}
:global(.dark .filter-input) {
    color: #f1f5f9;
}
:global(.dark .filter-input::placeholder) {
    color: #4b5563;
}

/* Clear btn */
.filter-clear-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 18px; height: 18px;
    margin-right: 7px;
    border-radius: 50%;
    background: #e5e7eb;
    color: #6b7280;
    border: none;
    cursor: pointer;
    flex-shrink: 0;
    transition: background 0.1s, color 0.1s;
}
.filter-clear-btn:hover {
    background: #fecaca;
    color: #dc2626;
}
:global(.dark .filter-clear-btn) {
    background: #334155;
    color: #64748b;
}
:global(.dark .filter-clear-btn:hover) {
    background: rgba(220,38,38,0.18);
    color: #f87171;
}

/* Suggestion dropdown */
.suggest-dropdown {
    position: absolute;
    top: 100%;
    left: -1.5px;
    right: -1.5px;
    z-index: 50;
    background: #ffffff;
    border: 1.5px solid #6366f1;
    border-top: none;
    border-radius: 0 0 8px 8px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.1);
    overflow: hidden;
    max-height: 220px;
    overflow-y: auto;
}
:global(.dark .suggest-dropdown) {
    background: #0f172a;
    border-color: #6366f1;
    box-shadow: 0 6px 20px rgba(0,0,0,0.4);
}

.suggest-item {
    display: block;
    width: 100%;
    text-align: left;
    padding: 8px 12px;
    font-size: 12.5px;
    font-weight: 500;
    color: #1f2937;
    background: transparent;
    border: none;
    cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
    transition: background 0.1s;
}
.suggest-item:last-child { border-bottom: none; }
.suggest-item:hover {
    background: #eef2ff;
    color: #4338ca;
}
:global(.dark .suggest-item) {
    color: #e2e8f0;
    border-bottom-color: #1e293b;
}
:global(.dark .suggest-item:hover) {
    background: rgba(99,102,241,0.12);
    color: #a5b4fc;
}
</style>

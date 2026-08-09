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
import { Hash, Pencil, Plus, Trash2, RotateCcw } from 'lucide-vue-next';

const props = defineProps({
    yarnCounts: { type: Array, default: () => [] },
    showTrashed: { type: Boolean, default: false },
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
        permissions.value.includes('manage yarn counts') ||
        permissions.value.includes('manage bookings'),
);

const canCreate = computed(() => canManage.value || permissions.value.includes('create yarn counts'));
const canEdit = computed(() => canManage.value || permissions.value.includes('edit yarn counts'));
const canDelete = computed(() => canManage.value || permissions.value.includes('delete yarn counts'));
const canAnyAction = computed(() => canCreate.value || canEdit.value || canDelete.value);

const showTrash = computed(() => Boolean(page.props.showTrashed));
const toggleTrashHref = computed(() => (showTrash.value ? route('yarn-counts.index') : route('yarn-counts.index', { trash: 1 })));

// ── Filters ─────────────────────────────────────────────────────────────────
const tableFilters = reactive({ keyword: '', count_value: '', code: '', status: '' });
const activeSuggestField = ref(null);

const normalizeText = (v) => String(v ?? '').toLowerCase().trim();

const fieldExtractor = {
    count_value: (c) => c.count_value ?? '',
    code:        (c) => c.code ?? '',
};

const suggestions = computed(() => {
    const field = activeSuggestField.value;
    if (!field || !fieldExtractor[field]) return [];
    const q = normalizeText(tableFilters[field]);
    if (!q) return [];
    const seen = new Set();
    const results = [];
    for (const row of props.yarnCounts) {
        const val = String(fieldExtractor[field](row)).trim();
        if (!val) continue;
        const norm = val.toLowerCase();
        if (norm.includes(q) && !seen.has(norm)) {
            seen.add(norm);
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
function onFieldFocus(field) { activeSuggestField.value = field; }
function onFieldBlur() { setTimeout(() => { activeSuggestField.value = null; }, 150); }

const hasActiveFilters = computed(() => Object.values(tableFilters).some((v) => normalizeText(v) !== ''));

const filteredCounts = computed(() => {
    const keyword = normalizeText(tableFilters.keyword);
    return props.yarnCounts.filter((c) => {
        const f = {
            count_value: normalizeText(c.count_value),
            code:        normalizeText(c.code),
        };
        const keywordOk = !keyword || Object.values(f).some((v) => v.includes(keyword));
        const statusOk =
            !tableFilters.status
            || (tableFilters.status === 'active' && c.is_active)
            || (tableFilters.status === 'inactive' && !c.is_active);
        return keywordOk
            && (!normalizeText(tableFilters.count_value) || f.count_value.includes(normalizeText(tableFilters.count_value)))
            && (!normalizeText(tableFilters.code)        || f.code.includes(normalizeText(tableFilters.code)))
            && statusOk;
    });
});

function resetFilters() { Object.keys(tableFilters).forEach((k) => (tableFilters[k] = '')); }

// ── Selection (bulk actions) ────────────────────────────────────────────────
const selectedIds = ref([]);

watch(
    () => props.yarnCounts.map((r) => r.id).join(',') + '|' + String(showTrash.value),
    () => {
        selectedIds.value = [];
    },
);

const allVisibleSelected = computed(() => {
    if (!filteredCounts.value.length) return false;
    return filteredCounts.value.every((r) => selectedIds.value.includes(r.id));
});

function toggleSelectAllVisible() {
    if (!filteredCounts.value.length) return;
    if (allVisibleSelected.value) {
        const visible = new Set(filteredCounts.value.map((r) => r.id));
        selectedIds.value = selectedIds.value.filter((id) => !visible.has(id));
        return;
    }
    const merged = new Set(selectedIds.value);
    filteredCounts.value.forEach((r) => merged.add(r.id));
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

async function trashRow(row) {
    if (!canDelete.value || showTrash.value) return;
    const r = await showDeleteConfirm(
        `Delete yarn count "${row.count_value}"?<br><span class="text-sm text-gray-500">It will be moved to Trash.</span>`,
    );
    if (!r?.isConfirmed) return;
    router.delete(route('yarn-counts.destroy', row.id), { preserveScroll: true, preserveState: true });
}

async function restoreRow(row) {
    if (!canDelete.value || !showTrash.value) return;
    const r = await showConfirm(`Restore yarn count "${row.count_value}"?`, 'Confirm');
    if (!r?.isConfirmed) return;
    router.post(route('yarn-counts.restore', row.id), {}, { preserveScroll: true, preserveState: true });
}

async function forceDeleteRow(row) {
    if (!canDelete.value || !showTrash.value) return;
    const r = await showDeleteConfirm(
        `Permanently delete yarn count "${row.count_value}"?<br><span class="text-sm text-red-500 font-semibold">This action cannot be undone.</span>`,
    );
    if (!r?.isConfirmed) return;
    router.delete(route('yarn-counts.force-destroy', row.id), { preserveScroll: true, preserveState: true });
}

async function bulkTrashSelected() {
    if (!canDelete.value || showTrash.value || !selectedIds.value.length) return;
    const r = await showDeleteConfirm(
        `Delete <strong>${selectedIds.value.length}</strong> yarn counts?<br><span class="text-sm text-gray-500">They will be moved to Trash.</span>`,
    );
    if (!r?.isConfirmed) return;
    router.delete(route('yarn-counts.bulk-destroy'), {
        data: { ids: [...selectedIds.value] },
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { selectedIds.value = []; },
    });
}

async function bulkRestoreSelected() {
    if (!canDelete.value || !showTrash.value || !selectedIds.value.length) return;
    const r = await showConfirm(`Restore ${selectedIds.value.length} yarn counts?`, 'Confirm');
    if (!r?.isConfirmed) return;
    router.post(
        route('yarn-counts.bulk-restore'),
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
        `Permanently delete <strong>${selectedIds.value.length}</strong> yarn counts?<br><span class="text-sm text-red-500 font-semibold">This action cannot be undone.</span>`,
    );
    if (!r?.isConfirmed) return;
    router.delete(route('yarn-counts.bulk-force-destroy'), {
        data: { ids: [...selectedIds.value] },
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { selectedIds.value = []; },
    });
}

// ── Modal ────────────────────────────────────────────────────────────────────
const modalOpen = ref(false);
const editingId = ref(null);

const form = useForm({
    count_value: '',
    code: '',
    is_active: true,
});

function openCreate() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    modalOpen.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    form.count_value = row.count_value ?? '';
    form.code        = row.code ?? '';
    form.is_active   = row.is_active !== false;
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
        form.put(route('yarn-counts.update', editingId.value), {
            preserveScroll: true,
            onSuccess: closeModal,
        });
    } else {
        form.post(route('yarn-counts.store'), {
            preserveScroll: true,
            onSuccess: closeModal,
        });
    }
}

</script>

<template>
    <Head title="Yarn Counts" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                    <Hash class="text-indigo-600 dark:text-indigo-400 shrink-0" :size="22" />
                    Yarn Counts
                </h2>
                <div class="flex flex-wrap gap-2">
                    <Link
                        v-if="canManage && !showTrash"
                        :href="route('yarn-counts.import.page')"
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
                        <Plus :size="18" /> Add count
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="py-6 sm:py-8">
            <div class="mx-auto max-w-7xl sm:px-4 lg:px-4 xl:max-w-[1440px] 2xl:max-w-[1600px] space-y-4">

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
                            <div class="filter-count-badge" :class="filteredCounts.length < yarnCounts.length ? 'filter-count-badge--active' : ''">
                                <span class="filter-count-dot" :class="filteredCounts.length < yarnCounts.length ? 'bg-indigo-500' : 'bg-gray-300 dark:bg-gray-600'" />
                                <span class="tabular-nums">{{ filteredCounts.length }}<span class="text-gray-400 dark:text-gray-500 font-normal"> / {{ yarnCounts.length }}</span></span>
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
                            <label class="filter-label" for="yarn_count_keyword">
                                <svg viewBox="0 0 14 14" fill="none" class="w-3 h-3"><circle cx="6" cy="6" r="4" stroke="currentColor" stroke-width="1.6"/><path d="M10 10l2.5 2.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                Global Search
                            </label>
                            <div class="filter-input-wrap filter-input-wrap--search">
                                <input id="yarn_count_keyword" v-model="tableFilters.keyword" type="text" class="filter-input" placeholder="Search anything…" autocomplete="off" />
                                <button v-if="tableFilters.keyword" type="button" class="filter-clear-btn" @click="tableFilters.keyword = ''">
                                    <svg viewBox="0 0 12 12" fill="none" class="w-2.5 h-2.5"><path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                </button>
                            </div>
                        </div>

                        <!-- Count Value -->
                        <div class="filter-field">
                            <label class="filter-label" for="yarn_count_value_filter">Count Value</label>
                            <div class="filter-input-wrap" :class="activeSuggestField === 'count_value' && suggestions.length ? 'filter-input-wrap--open' : ''">
                                <input id="yarn_count_value_filter" v-model="tableFilters.count_value" type="text" class="filter-input tabular-nums font-semibold" placeholder="e.g. 30/1, 20/1…" autocomplete="off" @focus="onFieldFocus('count_value')" @blur="onFieldBlur" />
                                <button v-if="tableFilters.count_value" type="button" class="filter-clear-btn" @click="tableFilters.count_value = ''">
                                    <svg viewBox="0 0 12 12" fill="none" class="w-2.5 h-2.5"><path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                </button>
                                <div v-if="activeSuggestField === 'count_value' && suggestions.length" class="suggest-dropdown">
                                    <button v-for="s in suggestions" :key="s" type="button" class="suggest-item tabular-nums font-semibold" @mousedown.prevent="applySuggestion('count_value', s)">{{ s }}</button>
                                </div>
                            </div>
                        </div>

                        <!-- Code -->
                        <div class="filter-field">
                            <label class="filter-label" for="yarn_count_code_filter">Code</label>
                            <div class="filter-input-wrap" :class="activeSuggestField === 'code' && suggestions.length ? 'filter-input-wrap--open' : ''">
                                <input id="yarn_count_code_filter" v-model="tableFilters.code" type="text" class="filter-input font-mono" placeholder="Count code…" autocomplete="off" @focus="onFieldFocus('code')" @blur="onFieldBlur" />
                                <button v-if="tableFilters.code" type="button" class="filter-clear-btn" @click="tableFilters.code = ''">
                                    <svg viewBox="0 0 12 12" fill="none" class="w-2.5 h-2.5"><path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                </button>
                                <div v-if="activeSuggestField === 'code' && suggestions.length" class="suggest-dropdown">
                                    <button v-for="s in suggestions" :key="s" type="button" class="suggest-item font-mono" @mousedown.prevent="applySuggestion('code', s)">{{ s }}</button>
                                </div>
                            </div>
                        </div>

                        <!-- Status -->
                        <div class="filter-field">
                            <label class="filter-label" for="yarn_count_status_filter">Status</label>
                            <div class="filter-select-wrap" :class="tableFilters.status ? 'filter-select-wrap--active' : ''">
                                <select id="yarn_count_status_filter" v-model="tableFilters.status" class="filter-select">
                                    <option value="">All Status</option>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                                <svg viewBox="0 0 12 12" fill="none" class="filter-select-chevron w-3 h-3 pointer-events-none"><path d="M2 4l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
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
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">Count Value</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">Code</th>
                                    <!-- Active -->
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">
                                        Active
                                    </th>
                                    <th
                                        v-if="canAnyAction"
                                        class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400"
                                    >
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                <tr v-if="!filteredCounts.length">
                                    <td
                                        :colspan="(canDelete ? 1 : 0) + (canAnyAction ? 1 : 0) + 3"
                                        class="px-6 py-16 text-center text-sm text-gray-500 dark:text-gray-400"
                                    >
                                        <div class="flex flex-col items-center gap-2">
                                            <Hash class="opacity-30" :size="40" stroke-width="1.25" />
                                            <p class="font-medium text-gray-700 dark:text-gray-300">
                                                {{ hasActiveFilters ? 'No results match the current filters' : 'No yarn counts yet' }}
                                            </p>
                                            <button
                                                v-if="hasActiveFilters"
                                                type="button"
                                                class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 text-sm font-medium"
                                                @click="resetFilters"
                                            >
                                                Clear filters
                                            </button>
                                            <button
                                                v-else-if="canCreate && !showTrash"
                                                type="button"
                                                class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 text-sm font-medium"
                                                @click="openCreate"
                                            >
                                                Add a count value
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr
                                    v-for="c in filteredCounts"
                                    :key="c.id"
                                    class="transition-colors hover:bg-gray-50/80 dark:hover:bg-gray-700/40"
                                >
                                    <td v-if="canDelete" class="w-10 px-4 py-3">
                                        <input
                                            type="checkbox"
                                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700"
                                            :checked="selectedIds.includes(c.id)"
                                            @change="toggleRowSelection(c.id)"
                                        />
                                    </td>
                                    <td class="px-4 py-3 text-base font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                                        {{ c.count_value }}
                                    </td>
                                    <td class="px-4 py-3 font-mono text-sm text-gray-700 dark:text-gray-200">
                                        {{ c.code || '—' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset"
                                            :class="c.is_active
                                                ? 'bg-emerald-100 text-emerald-800 ring-emerald-600/15 dark:bg-emerald-900/40 dark:text-emerald-300'
                                                : 'bg-gray-100 text-gray-700 ring-gray-500/15 dark:bg-gray-700 dark:text-gray-300'"
                                        >
                                            {{ c.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td v-if="canAnyAction" class="px-4 py-3 text-right whitespace-nowrap">
                                        <template v-if="!showTrash">
                                            <button
                                                v-if="canEdit"
                                                type="button"
                                                class="mr-2 inline-flex rounded-lg p-2 text-indigo-600 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-indigo-950/40"
                                                title="Edit"
                                                @click="openEdit(c)"
                                            >
                                                <Pencil :size="16" />
                                            </button>
                                            <button
                                                v-if="canDelete"
                                                type="button"
                                                class="inline-flex rounded-lg p-2 text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                                                title="Move to Trash"
                                                @click="trashRow(c)"
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
                                                @click="restoreRow(c)"
                                            >
                                                <RotateCcw :size="16" />
                                            </button>
                                            <button
                                                v-if="canDelete"
                                                type="button"
                                                class="inline-flex rounded-lg p-2 text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950/30"
                                                title="Delete Permanently"
                                                @click="forceDeleteRow(c)"
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

        <Modal v-if="!showTrash" :show="modalOpen" max-width="md" @close="closeModal">
            <div class="border-b border-gray-200 bg-gray-50 px-6 py-4 dark:border-gray-700 dark:bg-gray-900/50">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ editingId ? 'Edit yarn count' : 'New yarn count' }}
                </h3>
            </div>
            <form class="space-y-4 p-6 dark:bg-gray-800" @submit.prevent="submitModal">
                <div>
                    <InputLabel for="count_value" value="Count Value *" />
                    <TextInput id="count_value" v-model="form.count_value" type="text" required class="tabular-nums font-semibold" />
                    <InputError class="mt-1" :message="form.errors.count_value" />
                </div>
                <div>
                    <InputLabel for="code" value="Code" />
                    <TextInput id="code" v-model="form.code" type="text" class="font-mono" />
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
.filter-panel { border-radius: 12px; border: 1.5px solid #d1d5db; background: #fff; overflow: visible; box-shadow: 0 2px 8px rgba(0,0,0,0.07); }
:global(.dark .filter-panel) { background: #1e293b; border-color: #334155; box-shadow: 0 2px 8px rgba(0,0,0,0.35); }
.filter-panel-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; padding:12px 16px; background:#f8fafc; border-bottom:1.5px solid #e5e7eb; border-radius:12px 12px 0 0; }
:global(.dark .filter-panel-header) { background:#0f172a; border-bottom-color:#334155; }
.filter-icon-wrap { display:flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:7px; background:#e0e7ff; color:#4f46e5; flex-shrink:0; }
:global(.dark .filter-icon-wrap) { background:rgba(99,102,241,0.2); color:#818cf8; }
.filter-count-badge { display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:700; background:#f1f5f9; border:1.5px solid #e2e8f0; color:#374151; }
.filter-count-badge--active { background:#eef2ff; border-color:#a5b4fc; color:#3730a3; }
:global(.dark .filter-count-badge) { background:#1e293b; border-color:#475569; color:#e2e8f0; }
:global(.dark .filter-count-badge--active) { background:rgba(99,102,241,0.12); border-color:#6366f1; color:#a5b4fc; }
.filter-count-dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
.filter-reset-btn { display:inline-flex; align-items:center; gap:4px; padding:5px 11px; border-radius:7px; font-size:11px; font-weight:700; border:1.5px solid; cursor:pointer; transition:all 0.12s ease; }
.filter-reset-btn--idle { border-color:#e2e8f0; background:#f8fafc; color:#9ca3af; opacity:0.5; cursor:not-allowed; }
.filter-reset-btn--active { border-color:#fca5a5; background:#fff1f1; color:#dc2626; }
.filter-reset-btn--active:hover { background:#fee2e2; border-color:#f87171; transform:translateY(-1px); }
:global(.dark .filter-reset-btn--active) { background:rgba(220,38,38,0.08); border-color:rgba(248,113,113,0.4); color:#fca5a5; }
.filter-inputs-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:10px; padding:14px 16px 16px; background:#fff; border-radius:0 0 12px 12px; overflow:visible; }
:global(.dark .filter-inputs-grid) { background:#1e293b; }
.filter-field { display:flex; flex-direction:column; gap:4px; position:relative; }
.filter-label { display:inline-flex; align-items:center; gap:3px; font-size:10px; font-weight:700; letter-spacing:0.07em; text-transform:uppercase; color:#6b7280; }
:global(.dark .filter-label) { color:#64748b; }
.filter-input-wrap { position:relative; display:flex; align-items:center; border-radius:8px; border:1.5px solid #d1d5db; background:#fff; transition:border-color 0.12s,box-shadow 0.12s; }
.filter-input-wrap:focus-within { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.15); }
.filter-input-wrap--search { border-color:#a5b4fc; background:#fafbff; }
.filter-input-wrap--search:focus-within { border-color:#4f46e5; box-shadow:0 0 0 3px rgba(99,102,241,0.18); }
.filter-input-wrap--open { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.15); border-bottom-left-radius:0; border-bottom-right-radius:0; border-bottom-color:transparent; }
:global(.dark .filter-input-wrap) { background:#0f172a; border-color:#475569; }
:global(.dark .filter-input-wrap:focus-within) { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.2); }
.filter-input { flex:1; min-width:0; padding:7px 10px; background:transparent; border:none; outline:none; font-size:13px; font-weight:500; color:#111827; border-radius:inherit; }
.filter-input::placeholder { color:#9ca3af; font-weight:400; font-size:12px; }
:global(.dark .filter-input) { color:#f1f5f9; }
:global(.dark .filter-input::placeholder) { color:#4b5563; }
.filter-clear-btn { display:flex; align-items:center; justify-content:center; width:18px; height:18px; margin-right:7px; border-radius:50%; background:#e5e7eb; color:#6b7280; border:none; cursor:pointer; flex-shrink:0; transition:background 0.1s,color 0.1s; }
.filter-clear-btn:hover { background:#fecaca; color:#dc2626; }
:global(.dark .filter-clear-btn) { background:#334155; color:#64748b; }
:global(.dark .filter-clear-btn:hover) { background:rgba(220,38,38,0.18); color:#f87171; }
/* Select */
.filter-select-wrap { position:relative; display:flex; align-items:center; border-radius:8px; border:1.5px solid #d1d5db; background:#fff; transition:border-color 0.12s,box-shadow 0.12s; }
.filter-select-wrap--active { border-color:#a5b4fc; background:#fafbff; }
.filter-select-wrap:focus-within { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.15); }
:global(.dark .filter-select-wrap) { background:#0f172a; border-color:#475569; }
:global(.dark .filter-select-wrap--active) { border-color:#6366f1; background:rgba(99,102,241,0.07); }
.filter-select { flex:1; padding:7px 28px 7px 10px; background:transparent; border:none; outline:none; font-size:13px; font-weight:500; color:#111827; appearance:none; cursor:pointer; }
:global(.dark .filter-select) { color:#f1f5f9; }
.filter-select-chevron { position:absolute; right:9px; color:#9ca3af; }
/* Suggestion dropdown */
.suggest-dropdown { position:absolute; top:100%; left:-1.5px; right:-1.5px; z-index:50; background:#fff; border:1.5px solid #6366f1; border-top:none; border-radius:0 0 8px 8px; box-shadow:0 6px 20px rgba(0,0,0,0.1); overflow:hidden; max-height:220px; overflow-y:auto; }
:global(.dark .suggest-dropdown) { background:#0f172a; border-color:#6366f1; box-shadow:0 6px 20px rgba(0,0,0,0.4); }
.suggest-item { display:block; width:100%; text-align:left; padding:8px 12px; font-size:12.5px; font-weight:500; color:#1f2937; background:transparent; border:none; cursor:pointer; border-bottom:1px solid #f3f4f6; transition:background 0.1s; }
.suggest-item:last-child { border-bottom:none; }
.suggest-item:hover { background:#eef2ff; color:#4338ca; }
:global(.dark .suggest-item) { color:#e2e8f0; border-bottom-color:#1e293b; }
:global(.dark .suggest-item:hover) { background:rgba(99,102,241,0.12); color:#a5b4fc; }
</style>

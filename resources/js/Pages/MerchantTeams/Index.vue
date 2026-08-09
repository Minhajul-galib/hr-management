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
import { UsersRound, Pencil, Plus, Trash2, RotateCcw } from 'lucide-vue-next';

const props = defineProps({
    teams: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
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
        permissions.value.includes('manage merchandisers') ||
        permissions.value.includes('manage bookings'),
);

const canCreate = computed(() => canManage.value || permissions.value.includes('create merchandisers'));
const canEdit = computed(() => canManage.value || permissions.value.includes('edit merchandisers'));
const canDelete = computed(() => canManage.value || permissions.value.includes('delete merchandisers'));
const canAnyAction = computed(() => canCreate.value || canEdit.value || canDelete.value);

const showTrash = computed(() => Boolean(page.props.showTrashed));
const toggleTrashHref = computed(() => (showTrash.value ? route('merchant-teams.index') : route('merchant-teams.index', { trash: 1 })));

// ── Modal ───────────────────────────────────────────────────────────────────
const modalOpen = ref(false);
const editingId = ref(null);
const tableFilters = reactive({
    head_name: '',
    ep_code: '',
    department: '',
    designation: '',
    team_name: '',
    team_code: '',
});

// Suggestion state
const activeSuggestField = ref(null);

// Map filter key → value extractor from team row
const fieldExtractor = {
    head_name:   (t) => t.head?.name || t.head_name || '',
    ep_code:     (t) => t.ep_code ?? '',
    department:  (t) => t.department?.name ?? '',
    designation: (t) => t.head_designation ?? '',
    team_name:   (t) => t.name ?? '',
    team_code:   (t) => t.code ?? '',
};

const suggestions = computed(() => {
    const field = activeSuggestField.value;
    if (!field || !fieldExtractor[field]) return [];
    const q = normalizeText(tableFilters[field]);
    if (!q) return [];
    const seen = new Set();
    const results = [];
    for (const row of props.teams ?? []) {
        const val = String(fieldExtractor[field](row)).trim();
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
function onFieldFocus(field) { activeSuggestField.value = field; }
function onFieldBlur() { setTimeout(() => { activeSuggestField.value = null; }, 150); }

const form = useForm({
    name: '',
    code: '',
    ep_code: '',
    head_name: '',
    head_designation: '',
    head_user_id: '',
    department_id: '',
    is_active: true,
});

const selectCls =
    'mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';

const normalizeText = (value) => String(value ?? '').toLowerCase().trim();

const hasActiveFilters = computed(() =>
    Object.values(tableFilters).some((value) => normalizeText(value) !== ''),
);

const filteredTeams = computed(() => {
    return (props.teams ?? []).filter((t) => {
        const fields = {
            head_name:   normalizeText(t.head?.name || t.head_name),
            ep_code:     normalizeText(t.ep_code),
            department:  normalizeText(t.department?.name),
            designation: normalizeText(t.head_designation),
            team_name:   normalizeText(t.name),
            team_code:   normalizeText(t.code),
        };

        return (!normalizeText(tableFilters.head_name)   || fields.head_name.includes(normalizeText(tableFilters.head_name)))
            && (!normalizeText(tableFilters.ep_code)      || fields.ep_code.includes(normalizeText(tableFilters.ep_code)))
            && (!normalizeText(tableFilters.department)   || fields.department.includes(normalizeText(tableFilters.department)))
            && (!normalizeText(tableFilters.designation)  || fields.designation.includes(normalizeText(tableFilters.designation)))
            && (!normalizeText(tableFilters.team_name)    || fields.team_name.includes(normalizeText(tableFilters.team_name)))
            && (!normalizeText(tableFilters.team_code)    || fields.team_code.includes(normalizeText(tableFilters.team_code)));
    });
});

const selectedIds = ref([]);

watch(
    () => (props.teams ?? []).map((r) => r.id).join(',') + '|' + String(showTrash.value),
    () => {
        selectedIds.value = [];
    },
);

const allVisibleSelected = computed(() => {
    if (!filteredTeams.value.length) return false;
    return filteredTeams.value.every((r) => selectedIds.value.includes(r.id));
});

function toggleSelectAllVisible() {
    if (!filteredTeams.value.length) return;
    if (allVisibleSelected.value) {
        const visible = new Set(filteredTeams.value.map((r) => r.id));
        selectedIds.value = selectedIds.value.filter((id) => !visible.has(id));
        return;
    }
    const merged = new Set(selectedIds.value);
    filteredTeams.value.forEach((r) => merged.add(r.id));
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

async function trashTeam(row) {
    if (!canDelete.value || showTrash.value) return;
    const r = await showDeleteConfirm(
        `Delete merchandiser "${row.head_name || row.name}"?<br><span class="text-sm text-gray-500">It will be moved to Trash.</span>`,
    );
    if (!r?.isConfirmed) return;
    router.delete(route('merchant-teams.destroy', row.id), { preserveScroll: true, preserveState: true });
}

async function restoreTeam(row) {
    if (!canDelete.value || !showTrash.value) return;
    const r = await showConfirm(`Restore merchandiser "${row.head_name || row.name}"?`, 'Confirm');
    if (!r?.isConfirmed) return;
    router.post(route('merchant-teams.restore', row.id), {}, { preserveScroll: true, preserveState: true });
}

async function forceDeleteTeam(row) {
    if (!canDelete.value || !showTrash.value) return;
    const r = await showDeleteConfirm(
        `Permanently delete merchandiser "${row.head_name || row.name}"?<br><span class="text-sm text-red-500 font-semibold">This action cannot be undone.</span>`,
    );
    if (!r?.isConfirmed) return;
    router.delete(route('merchant-teams.force-destroy', row.id), { preserveScroll: true, preserveState: true });
}

async function bulkTrashSelected() {
    if (!canDelete.value || showTrash.value || !selectedIds.value.length) return;
    const r = await showDeleteConfirm(
        `Delete <strong>${selectedIds.value.length}</strong> merchandisers?<br><span class="text-sm text-gray-500">They will be moved to Trash.</span>`,
    );
    if (!r?.isConfirmed) return;
    router.delete(route('merchant-teams.bulk-destroy'), {
        data: { ids: [...selectedIds.value] },
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { selectedIds.value = []; },
    });
}

async function bulkRestoreSelected() {
    if (!canDelete.value || !showTrash.value || !selectedIds.value.length) return;
    const r = await showConfirm(`Restore ${selectedIds.value.length} merchandisers?`, 'Confirm');
    if (!r?.isConfirmed) return;
    router.post(
        route('merchant-teams.bulk-restore'),
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
        `Permanently delete <strong>${selectedIds.value.length}</strong> merchandisers?<br><span class="text-sm text-red-500 font-semibold">This action cannot be undone.</span>`,
    );
    if (!r?.isConfirmed) return;
    router.delete(route('merchant-teams.bulk-force-destroy'), {
        data: { ids: [...selectedIds.value] },
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { selectedIds.value = []; },
    });
}

function openCreate() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    modalOpen.value = true;
}

function openEdit(team) {
    editingId.value = team.id;
    form.name             = team.name ?? '';
    form.code             = team.code ?? '';
    form.ep_code          = team.ep_code ?? '';
    form.head_name        = team.head_name ?? '';
    form.head_designation = team.head_designation ?? '';
    form.head_user_id     = team.head_user_id != null ? String(team.head_user_id) : '';
    form.department_id    = team.department_id != null ? String(team.department_id) : '';
    form.is_active        = team.is_active !== false;
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
        form.put(route('merchant-teams.update', editingId.value), {
            preserveScroll: true,
            onSuccess: closeModal,
        });
    } else {
        form.post(route('merchant-teams.store'), {
            preserveScroll: true,
            onSuccess: closeModal,
        });
    }
}
</script>

<template>
    <Head title="Merchandisers" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                    <UsersRound class="text-indigo-600 dark:text-indigo-400 shrink-0" :size="22" />
                    Merchandisers
                </h2>
                <div class="flex flex-wrap gap-2">
                    <Link
                        v-if="canManage && !showTrash"
                        :href="route('merchant-teams.import.page')"
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
                        <Plus :size="18" /> Add merchandiser
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
                            <div class="filter-count-badge" :class="filteredTeams.length < teams.length ? 'filter-count-badge--active' : ''">
                                <span class="filter-count-dot" :class="filteredTeams.length < teams.length ? 'bg-indigo-500' : 'bg-gray-300 dark:bg-gray-600'" />
                                <span class="tabular-nums">{{ filteredTeams.length }}<span class="text-gray-400 dark:text-gray-500 font-normal"> / {{ teams.length }}</span></span>
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

                        <!-- Name search -->
                        <div class="filter-field">
                            <label class="filter-label" for="team_head_name">
                                <svg viewBox="0 0 14 14" fill="none" class="w-3 h-3"><circle cx="6" cy="6" r="4" stroke="currentColor" stroke-width="1.6"/><path d="M10 10l2.5 2.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                Name
                            </label>
                            <div class="filter-input-wrap" :class="activeSuggestField === 'head_name' && suggestions.length ? 'filter-input-wrap--open' : ''">
                                <input id="team_head_name" v-model="tableFilters.head_name" type="text" class="filter-input" placeholder="Search name…" autocomplete="off" @focus="onFieldFocus('head_name')" @blur="onFieldBlur" />
                                <button v-if="tableFilters.head_name" type="button" class="filter-clear-btn" @click="tableFilters.head_name = ''">
                                    <svg viewBox="0 0 12 12" fill="none" class="w-2.5 h-2.5"><path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                </button>
                                <div v-if="activeSuggestField === 'head_name' && suggestions.length" class="suggest-dropdown">
                                    <button v-for="s in suggestions" :key="s" type="button" class="suggest-item" @mousedown.prevent="applySuggestion('head_name', s)">{{ s }}</button>
                                </div>
                            </div>
                        </div>

                        <!-- EP Code -->
                        <div class="filter-field">
                            <label class="filter-label" for="team_ep_code">EP Code</label>
                            <div class="filter-input-wrap" :class="activeSuggestField === 'ep_code' && suggestions.length ? 'filter-input-wrap--open' : ''">
                                <input id="team_ep_code" v-model="tableFilters.ep_code" type="text" class="filter-input font-mono" placeholder="EP Code…" autocomplete="off" @focus="onFieldFocus('ep_code')" @blur="onFieldBlur" />
                                <button v-if="tableFilters.ep_code" type="button" class="filter-clear-btn" @click="tableFilters.ep_code = ''"><svg viewBox="0 0 12 12" fill="none" class="w-2.5 h-2.5"><path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>
                                <div v-if="activeSuggestField === 'ep_code' && suggestions.length" class="suggest-dropdown">
                                    <button v-for="s in suggestions" :key="s" type="button" class="suggest-item font-mono" @mousedown.prevent="applySuggestion('ep_code', s)">{{ s }}</button>
                                </div>
                            </div>
                        </div>

                        <!-- Department -->
                        <div class="filter-field">
                            <label class="filter-label" for="team_department">Department</label>
                            <div class="filter-input-wrap" :class="activeSuggestField === 'department' && suggestions.length ? 'filter-input-wrap--open' : ''">
                                <input id="team_department" v-model="tableFilters.department" type="text" class="filter-input" placeholder="Department…" autocomplete="off" @focus="onFieldFocus('department')" @blur="onFieldBlur" />
                                <button v-if="tableFilters.department" type="button" class="filter-clear-btn" @click="tableFilters.department = ''"><svg viewBox="0 0 12 12" fill="none" class="w-2.5 h-2.5"><path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>
                                <div v-if="activeSuggestField === 'department' && suggestions.length" class="suggest-dropdown">
                                    <button v-for="s in suggestions" :key="s" type="button" class="suggest-item" @mousedown.prevent="applySuggestion('department', s)">{{ s }}</button>
                                </div>
                            </div>
                        </div>

                        <!-- Designation -->
                        <div class="filter-field">
                            <label class="filter-label" for="team_designation">Designation</label>
                            <div class="filter-input-wrap" :class="activeSuggestField === 'designation' && suggestions.length ? 'filter-input-wrap--open' : ''">
                                <input id="team_designation" v-model="tableFilters.designation" type="text" class="filter-input" placeholder="Designation…" autocomplete="off" @focus="onFieldFocus('designation')" @blur="onFieldBlur" />
                                <button v-if="tableFilters.designation" type="button" class="filter-clear-btn" @click="tableFilters.designation = ''"><svg viewBox="0 0 12 12" fill="none" class="w-2.5 h-2.5"><path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>
                                <div v-if="activeSuggestField === 'designation' && suggestions.length" class="suggest-dropdown">
                                    <button v-for="s in suggestions" :key="s" type="button" class="suggest-item" @mousedown.prevent="applySuggestion('designation', s)">{{ s }}</button>
                                </div>
                            </div>
                        </div>

                        <!-- Team Name -->
                        <div class="filter-field">
                            <label class="filter-label" for="team_name_filter">Team Name</label>
                            <div class="filter-input-wrap" :class="activeSuggestField === 'team_name' && suggestions.length ? 'filter-input-wrap--open' : ''">
                                <input id="team_name_filter" v-model="tableFilters.team_name" type="text" class="filter-input" placeholder="Team name…" autocomplete="off" @focus="onFieldFocus('team_name')" @blur="onFieldBlur" />
                                <button v-if="tableFilters.team_name" type="button" class="filter-clear-btn" @click="tableFilters.team_name = ''"><svg viewBox="0 0 12 12" fill="none" class="w-2.5 h-2.5"><path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>
                                <div v-if="activeSuggestField === 'team_name' && suggestions.length" class="suggest-dropdown">
                                    <button v-for="s in suggestions" :key="s" type="button" class="suggest-item" @mousedown.prevent="applySuggestion('team_name', s)">{{ s }}</button>
                                </div>
                            </div>
                        </div>

                        <!-- Team Code -->
                        <div class="filter-field">
                            <label class="filter-label" for="team_code_filter">Team Code</label>
                            <div class="filter-input-wrap" :class="activeSuggestField === 'team_code' && suggestions.length ? 'filter-input-wrap--open' : ''">
                                <input id="team_code_filter" v-model="tableFilters.team_code" type="text" class="filter-input font-mono" placeholder="Team code…" autocomplete="off" @focus="onFieldFocus('team_code')" @blur="onFieldBlur" />
                                <button v-if="tableFilters.team_code" type="button" class="filter-clear-btn" @click="tableFilters.team_code = ''"><svg viewBox="0 0 12 12" fill="none" class="w-2.5 h-2.5"><path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>
                                <div v-if="activeSuggestField === 'team_code' && suggestions.length" class="suggest-dropdown">
                                    <button v-for="s in suggestions" :key="s" type="button" class="suggest-item font-mono" @mousedown.prevent="applySuggestion('team_code', s)">{{ s }}</button>
                                </div>
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
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">EP Code</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">Name</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">Department</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">Designation</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">Team Name</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">Team Code</th>
                                    <th v-if="canAnyAction" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                <tr v-if="!filteredTeams.length">
                                    <td
                                        :colspan="(canDelete ? 1 : 0) + (canAnyAction ? 1 : 0) + 6"
                                        class="px-6 py-16 text-center text-sm text-gray-500 dark:text-gray-400"
                                    >
                                        <div class="flex flex-col items-center gap-2">
                                            <UsersRound class="opacity-30" :size="40" stroke-width="1.25" />
                                            <p class="font-medium text-gray-700 dark:text-gray-300">
                                                {{ hasActiveFilters ? 'No results matched the current filters' : 'No merchandisers defined' }}
                                            </p>
                                            <button
                                                v-if="canCreate && !hasActiveFilters && !showTrash"
                                                type="button"
                                                class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 text-sm font-medium"
                                                @click="openCreate"
                                            >
                                                Add a merchandiser
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
                                    v-for="t in filteredTeams"
                                    :key="t.id"
                                    class="transition-colors hover:bg-gray-50/80 dark:hover:bg-gray-700/40"
                                >
                                    <td v-if="canDelete" class="w-10 px-4 py-3">
                                        <input
                                            type="checkbox"
                                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700"
                                            :checked="selectedIds.includes(t.id)"
                                            @change="toggleRowSelection(t.id)"
                                        />
                                    </td>
                                    <td class="px-4 py-3 font-mono text-sm text-gray-700 dark:text-gray-300">
                                        {{ t.ep_code || '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ t.head?.name || t.head_name || '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-800 dark:text-gray-200">
                                        {{ t.department?.name || '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-800 dark:text-gray-200">
                                        {{ t.head_designation || '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ t.name }}
                                    </td>
                                    <td class="px-4 py-3 font-mono text-sm text-gray-700 dark:text-gray-200 whitespace-nowrap">
                                        {{ t.code || '—' }}
                                    </td>
                                    <td v-if="canAnyAction" class="px-4 py-3 text-right whitespace-nowrap">
                                        <template v-if="!showTrash">
                                            <button
                                                v-if="canEdit"
                                                type="button"
                                                class="mr-2 inline-flex rounded-lg p-2 text-indigo-600 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-indigo-950/40"
                                                title="Edit"
                                                @click="openEdit(t)"
                                            >
                                                <Pencil :size="16" />
                                            </button>
                                            <button
                                                v-if="canDelete"
                                                type="button"
                                                class="inline-flex rounded-lg p-2 text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                                                title="Move to Trash"
                                                @click="trashTeam(t)"
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
                                                @click="restoreTeam(t)"
                                            >
                                                <RotateCcw :size="16" />
                                            </button>
                                            <button
                                                v-if="canDelete"
                                                type="button"
                                                class="inline-flex rounded-lg p-2 text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950/30"
                                                title="Delete Permanently"
                                                @click="forceDeleteTeam(t)"
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

        <!-- Add / Edit modal -->
        <Modal v-if="!showTrash" :show="modalOpen" max-width="lg" @close="closeModal">
            <div class="border-b border-gray-200 bg-gray-50 px-6 py-4 dark:border-gray-700 dark:bg-gray-900/50">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ editingId ? 'Edit merchandiser' : 'New merchandiser' }}
                </h3>
            </div>
            <form class="space-y-4 p-6 dark:bg-gray-800" @submit.prevent="submitModal">
                <!-- Team Name + Code -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel for="name" value="Team Name *" />
                        <TextInput id="name" v-model="form.name" type="text" required />
                        <InputError class="mt-1" :message="form.errors.name" />
                    </div>
                    <div>
                        <InputLabel for="code" value="Team Code" />
                        <TextInput id="code" v-model="form.code" type="text" class="font-mono" />
                        <InputError class="mt-1" :message="form.errors.code" />
                    </div>
                </div>
                <!-- EP Code + Head Name -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel for="ep_code" value="EP Code" />
                        <TextInput id="ep_code" v-model="form.ep_code" type="text" class="font-mono" />
                        <InputError class="mt-1" :message="form.errors.ep_code" />
                    </div>
                    <div>
                        <InputLabel for="head_name" value="Head Name" />
                        <TextInput id="head_name" v-model="form.head_name" type="text" />
                        <InputError class="mt-1" :message="form.errors.head_name" />
                    </div>
                </div>
                <!-- Designation -->
                <div>
                    <InputLabel for="head_designation" value="Designation" />
                    <TextInput id="head_designation" v-model="form.head_designation" type="text" />
                    <InputError class="mt-1" :message="form.errors.head_designation" />
                </div>
                <!-- Department -->
                <div>
                    <InputLabel for="department_id" value="Department" />
                    <select id="department_id" v-model="form.department_id" :class="selectCls">
                        <option value="">None</option>
                        <option v-for="d in departments" :key="d.id" :value="String(d.id)">{{ d.name }}</option>
                    </select>
                    <InputError class="mt-1" :message="form.errors.department_id" />
                </div>
                <!-- System user (optional) -->
                <div>
                    <InputLabel for="head_user_id" value="System User (optional)" />
                    <select id="head_user_id" v-model="form.head_user_id" :class="selectCls">
                        <option value="">None</option>
                        <option v-for="u in users" :key="u.id" :value="String(u.id)">{{ u.name }}</option>
                    </select>
                    <InputError class="mt-1" :message="form.errors.head_user_id" />
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
:global(.dark .filter-count-badge) { background: #1e293b; border-color: #475569; color: #e2e8f0; }
:global(.dark .filter-count-badge--active) { background: rgba(99,102,241,0.12); border-color: #6366f1; color: #a5b4fc; }
.filter-count-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
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
.filter-reset-btn--idle { border-color: #e2e8f0; background: #f8fafc; color: #9ca3af; opacity: 0.5; cursor: not-allowed; }
.filter-reset-btn--active { border-color: #fca5a5; background: #fff1f1; color: #dc2626; }
.filter-reset-btn--active:hover { background: #fee2e2; border-color: #f87171; transform: translateY(-1px); }
:global(.dark .filter-reset-btn--active) { background: rgba(220,38,38,0.08); border-color: rgba(248,113,113,0.4); color: #fca5a5; }
.filter-inputs-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 10px;
    padding: 14px 16px 16px;
    background: #ffffff;
    border-radius: 0 0 12px 12px;
    overflow: visible;
}
:global(.dark .filter-inputs-grid) { background: #1e293b; }
.filter-field { display: flex; flex-direction: column; gap: 4px; position: relative; }
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
:global(.dark .filter-label) { color: #64748b; }
.filter-input-wrap {
    position: relative;
    display: flex;
    align-items: center;
    border-radius: 8px;
    border: 1.5px solid #d1d5db;
    background: #ffffff;
    transition: border-color 0.12s, box-shadow 0.12s;
}
.filter-input-wrap:focus-within { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.15); }
.filter-input-wrap--search { border-color: #a5b4fc; background: #fafbff; }
.filter-input-wrap--search:focus-within { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(99,102,241,0.18); }
.filter-input-wrap--open { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.15); border-bottom-left-radius: 0; border-bottom-right-radius: 0; border-bottom-color: transparent; }
:global(.dark .filter-input-wrap) { background: #0f172a; border-color: #475569; }
:global(.dark .filter-input-wrap:focus-within) { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.2); }
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
.filter-input::placeholder { color: #9ca3af; font-weight: 400; font-size: 12px; }
:global(.dark .filter-input) { color: #f1f5f9; }
:global(.dark .filter-input::placeholder) { color: #4b5563; }
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
.filter-clear-btn:hover { background: #fecaca; color: #dc2626; }
:global(.dark .filter-clear-btn) { background: #334155; color: #64748b; }
:global(.dark .filter-clear-btn:hover) { background: rgba(220,38,38,0.18); color: #f87171; }
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
:global(.dark .suggest-dropdown) { background: #0f172a; border-color: #6366f1; box-shadow: 0 6px 20px rgba(0,0,0,0.4); }
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
.suggest-item:hover { background: #eef2ff; color: #4338ca; }
:global(.dark .suggest-item) { color: #e2e8f0; border-bottom-color: #1e293b; }
:global(.dark .suggest-item:hover) { background: rgba(99,102,241,0.12); color: #a5b4fc; }
</style>

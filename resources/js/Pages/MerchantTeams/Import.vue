<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';
import { UsersRound, Upload, Download, ArrowLeft, CheckCircle2, AlertTriangle, Plus, Trash2 } from 'lucide-vue-next';

const props = defineProps({
    departments: { type: Array, default: () => [] },
});

// idle | previewing | preview | importing | done
const stage = ref('idle');
const file = ref(null);
const dragging = ref(false);
const rows = ref([]);
const previewError = ref(null);
const importResult = ref(null);

const hasInvalidRows = computed(() => rows.value.some((r) => !r.team_name?.trim()));

const pickFile = (e) => {
    const f = e.target.files?.[0];
    if (f) { file.value = f; runPreview(); }
};

const onDrop = (e) => {
    dragging.value = false;
    const f = e.dataTransfer?.files?.[0];
    if (f) { file.value = f; runPreview(); }
};

const runPreview = async () => {
    if (!file.value) return;
    stage.value = 'previewing';
    previewError.value = null;
    try {
        const fd = new FormData();
        fd.append('file', file.value);
        const res = await axios.post(route('merchant-teams.import.preview'), fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        rows.value = (res.data.rows || []).map((r) => ({ ...r }));
        stage.value = 'preview';
    } catch (e) {
        previewError.value = e?.response?.data?.error || e?.message || 'Preview failed';
        stage.value = 'idle';
    }
};

const addRow = () => {
    rows.value.push({
        row: null,
        ep_code: '',
        head_name: '',
        department: '',
        head_designation: '',
        team_name: '',
        team_code: '',
    });
};

const removeRow = (i) => {
    rows.value.splice(i, 1);
};

const confirmImport = async () => {
    stage.value = 'importing';
    previewError.value = null;
    try {
        const res = await axios.post(route('merchant-teams.import.confirm'), { rows: rows.value });
        importResult.value = res.data;
        stage.value = 'done';
    } catch (e) {
        previewError.value = e?.response?.data?.message || e?.message || 'Import failed';
        stage.value = 'preview';
    }
};

const reset = () => {
    stage.value = 'idle';
    file.value = null;
    rows.value = [];
    previewError.value = null;
    importResult.value = null;
};
</script>

<template>
    <Head title="Import Merchandisers" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <Link
                        :href="route('merchant-teams.index')"
                        class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-100 dark:border-gray-600 dark:hover:bg-gray-700"
                    >
                        <ArrowLeft :size="18" /> Back
                    </Link>
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2 leading-tight">
                        <UsersRound class="text-indigo-600 dark:text-indigo-400 shrink-0" :size="22" />
                        Import Merchandisers
                    </h2>
                </div>
                <a
                    :href="route('merchant-teams.template')"
                    class="inline-flex items-center gap-2 rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-700 dark:bg-gray-700 dark:hover:bg-gray-600"
                >
                    <Download :size="18" /> Download Template
                </a>
            </div>
        </template>

        <div class="py-6 sm:py-8">
            <div class="mx-auto max-w-7xl sm:px-4 lg:px-4 xl:max-w-[1440px] 2xl:max-w-[1600px] space-y-5">

                <!-- Error banner -->
                <div
                    v-if="previewError"
                    class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800/40 dark:bg-red-900/20 dark:text-red-200"
                >
                    {{ previewError }}
                </div>

                <!-- Step 1: Drop zone -->
                <div
                    v-if="stage === 'idle' || stage === 'previewing'"
                    class="rounded-xl border-2 border-dashed p-12 text-center transition-colors"
                    :class="dragging
                        ? 'border-indigo-500 bg-indigo-50/60 dark:bg-indigo-900/10'
                        : 'border-gray-300 bg-white dark:bg-gray-800 dark:border-gray-700'"
                    @dragenter.prevent="dragging = true"
                    @dragover.prevent="dragging = true"
                    @dragleave.prevent="dragging = false"
                    @drop.prevent="onDrop"
                >
                    <div class="mx-auto max-w-sm space-y-3">
                        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-indigo-50 text-indigo-600 dark:bg-indigo-900/30 dark:text-indigo-300">
                            <Upload :size="26" />
                        </div>
                        <p class="text-base font-semibold text-gray-900 dark:text-gray-100">
                            Drag & drop Excel / CSV here
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            After uploading you can edit every cell before saving.
                        </p>
                        <p class="text-xs text-gray-400 dark:text-gray-500">
                            Columns: EP Code · Name · Department · Designation · Team Name · Team Code
                        </p>
                        <div class="pt-2">
                            <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500">
                                <Upload :size="16" />
                                Choose file
                                <input type="file" accept=".xlsx,.xls,.csv" class="hidden" @change="pickFile" />
                            </label>
                        </div>
                        <p v-if="file" class="text-xs text-gray-500 dark:text-gray-400">{{ file.name }}</p>
                        <div v-if="stage === 'previewing'" class="flex items-center justify-center gap-2 pt-2 text-sm text-indigo-600 dark:text-indigo-400">
                            <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
                            </svg>
                            Reading file…
                        </div>
                    </div>
                </div>

                <!-- Step 2: Editable preview table -->
                <div
                    v-if="stage === 'preview' || stage === 'importing'"
                    class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800"
                >
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 dark:border-gray-700 px-5 py-3.5">
                        <div class="flex items-center gap-3">
                            <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                                {{ rows.length }} row(s) — click any cell to edit
                            </p>
                            <span
                                v-if="hasInvalidRows"
                                class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"
                            >
                                <AlertTriangle :size="11" />
                                Missing Team Name
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700"
                                @click="reset"
                            >
                                ← Change file
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-indigo-300 px-3 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-50 dark:border-indigo-700 dark:text-indigo-300 dark:hover:bg-indigo-900/30"
                                @click="addRow"
                            >
                                <Plus :size="13" /> Add row
                            </button>
                        </div>
                    </div>

                    <datalist id="department-options">
                        <option v-for="d in departments" :key="d.id" :value="d.name" />
                    </datalist>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-900/50">
                                <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                                    <th class="w-10 px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">#</th>
                                    <th class="px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">EP Code</th>
                                    <th class="px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Name</th>
                                    <th class="px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Department</th>
                                    <th class="px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Designation</th>
                                    <th class="px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Team Name <span class="text-red-500">*</span>
                                    </th>
                                    <th class="px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Team Code</th>
                                    <th class="w-10 px-2 py-2.5"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                                <tr
                                    v-for="(r, i) in rows"
                                    :key="i"
                                    class="group transition-colors"
                                    :class="!r.team_name?.trim()
                                        ? 'bg-red-50/70 dark:bg-red-900/10'
                                        : 'hover:bg-indigo-50/30 dark:hover:bg-indigo-900/10'"
                                >
                                    <td class="w-10 px-3 py-1 font-mono text-xs text-gray-400 dark:text-gray-500">{{ r.row ?? i + 2 }}</td>
                                    <td class="px-2 py-1"><input v-model="r.ep_code"          placeholder="—" class="cell-input font-mono" /></td>
                                    <td class="px-2 py-1"><input v-model="r.head_name"        placeholder="—" class="cell-input" /></td>
                                    <td class="px-2 py-1">
                                        <input
                                            v-model="r.department"
                                            list="department-options"
                                            placeholder="Type or select…"
                                            class="cell-input"
                                        />
                                    </td>
                                    <td class="px-2 py-1"><input v-model="r.head_designation" placeholder="—" class="cell-input" /></td>
                                    <td class="px-2 py-1">
                                        <input
                                            v-model="r.team_name"
                                            placeholder="Required"
                                            :class="['cell-input font-medium', !r.team_name?.trim() ? 'text-red-600 placeholder-red-400 dark:text-red-400 dark:placeholder-red-400' : '']"
                                        />
                                    </td>
                                    <td class="px-2 py-1"><input v-model="r.team_code" placeholder="—" class="cell-input font-mono" /></td>
                                    <td class="w-10 px-2 py-1">
                                        <button
                                            type="button"
                                            class="invisible inline-flex rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600 group-hover:visible dark:hover:bg-red-900/30 dark:hover:text-red-400"
                                            title="Remove row"
                                            @click="removeRow(i)"
                                        >
                                            <Trash2 :size="14" />
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="!rows.length">
                                    <td colspan="8" class="px-4 py-10 text-center text-sm text-gray-400 dark:text-gray-500">
                                        No rows — add one manually or upload a file.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 dark:border-gray-700 px-5 py-4">
                        <p v-if="hasInvalidRows" class="flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-400">
                            <AlertTriangle :size="13" />
                            Rows with empty Team Name will be skipped.
                        </p>
                        <p v-else class="text-xs text-gray-400 dark:text-gray-500">All rows look good.</p>

                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                            :disabled="!rows.length || stage === 'importing'"
                            @click="confirmImport"
                        >
                            <svg v-if="stage === 'importing'" class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
                            </svg>
                            <CheckCircle2 v-else :size="17" />
                            {{ stage === 'importing' ? 'Importing…' : 'Confirm Import' }}
                        </button>
                    </div>
                </div>

                <!-- Step 3: Done -->
                <div
                    v-if="stage === 'done' && importResult"
                    class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-6 space-y-5"
                >
                    <div class="flex items-center gap-2 text-base font-semibold text-gray-900 dark:text-gray-100">
                        <CheckCircle2 class="text-emerald-500" :size="22" />
                        Import complete
                    </div>
                    <div class="flex flex-wrap gap-6 text-sm">
                        <span class="text-emerald-700 dark:text-emerald-400">
                            Imported / Updated: <strong>{{ importResult.imported }}</strong>
                        </span>
                        <span v-if="importResult.skipped" class="text-amber-700 dark:text-amber-400">
                            Skipped (no team name): <strong>{{ importResult.skipped }}</strong>
                        </span>
                        <span v-if="importResult.errors?.length" class="text-red-700 dark:text-red-400">
                            Errors: <strong>{{ importResult.errors.length }}</strong>
                        </span>
                    </div>

                    <div v-if="importResult.errors?.length" class="overflow-hidden rounded-lg border border-red-200 dark:border-red-800/40">
                        <table class="min-w-full text-xs">
                            <thead class="bg-red-50 dark:bg-red-900/20">
                                <tr>
                                    <th class="px-3 py-2 text-left font-semibold text-red-700 dark:text-red-300">Row</th>
                                    <th class="px-3 py-2 text-left font-semibold text-red-700 dark:text-red-300">Team Name</th>
                                    <th class="px-3 py-2 text-left font-semibold text-red-700 dark:text-red-300">Error</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-red-100 dark:divide-red-900/30 bg-white dark:bg-gray-800">
                                <tr v-for="err in importResult.errors" :key="err.row">
                                    <td class="px-3 py-1.5 font-mono text-gray-600 dark:text-gray-300">{{ err.row ?? '—' }}</td>
                                    <td class="px-3 py-1.5 text-gray-700 dark:text-gray-300">{{ err.team_name || '—' }}</td>
                                    <td class="px-3 py-1.5 text-red-700 dark:text-red-300">{{ err.error }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <Link
                            :href="route('merchant-teams.index')"
                            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
                        >
                            Go to Merchandisers →
                        </Link>
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                            @click="reset"
                        >
                            Import more
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.cell-input {
    @apply w-full min-w-[80px] rounded border border-transparent bg-transparent px-1.5 py-0.5 text-sm text-gray-800 transition-colors dark:text-gray-100;
}
.cell-input:hover {
    @apply border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-700/50;
}
.cell-input:focus {
    @apply border-indigo-400 bg-white outline-none dark:border-indigo-500 dark:bg-gray-700;
}
</style>

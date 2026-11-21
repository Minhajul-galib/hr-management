<script setup>
/**
 * Review step of an Excel import, laid out like the sheet: every uploaded row
 * with its outcome (new / update / unchanged / problem / sample), problems
 * marked on the cell, what an update changes, what an empty cell will hold,
 * and a free code suggested where a code is missing or already used.
 *
 * Cells can be edited, rows added or removed; each change is checked again on
 * the server (nothing is saved). "Import" sends the reviewed rows; rows that
 * still have problems, and untouched sample rows, are skipped.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { AlertTriangle, CheckCircle2, FileSpreadsheet, Loader2, Plus, RefreshCw, Sparkles, Trash2, Wand2, X } from 'lucide-vue-next';
import { useSweetAlert } from '@/composables/useSweetAlert';

const props = defineProps({
    definition: { type: Object, required: true },
    /** From the preview endpoint: { file, rows, summary } */
    preview: { type: Object, required: true },
    checkUrl: { type: String, required: true },
    importUrl: { type: String, required: true },
});

const emit = defineEmits(['close', 'imported']);

const PAGE_SIZE = 50;
const CHECK_DELAY_MS = 600;

const columns = computed(() => props.definition.import.columns);
const letter = (index) => {
    let n = index + 1;
    let out = '';
    while (n > 0) {
        out = String.fromCharCode(65 + ((n - 1) % 26)) + out;
        n = Math.floor((n - 1) / 26);
    }
    return out;
};
const columnLetter = (key) => letter(columns.value.findIndex((c) => c.key === key));
const columnLabel = (key) => columns.value.find((c) => c.key === key)?.label ?? key;

// ----- rows ------------------------------------------------------------------

let uid = 0;
const blankCells = () => Object.fromEntries(columns.value.map((c) => [c.key, '']));

const rows = ref(
    props.preview.rows.map((result) => ({
        uid: ++uid,
        row: result.row,
        cells: { ...blankCells(), ...result.cells },
        sample: result.sample,
        result,
        stale: false,
    })),
);

const touched = ref(false); // anything changed since the upload

const isBlank = (row) => Object.values(row.cells).every((v) => String(v ?? '').trim() === '');

const statusOf = (row) => (isBlank(row) ? 'blank' : row.result?.status ?? 'pending');
const hasWarnings = (row) => !!row.result && Object.keys(row.result.warnings || {}).length > 0;
const needsAttention = (row) => statusOf(row) === 'error' || hasWarnings(row);

const counts = computed(() => {
    const c = { all: 0, new: 0, update: 0, unchanged: 0, error: 0, sample: 0, attention: 0, blank: 0 };
    for (const row of rows.value) {
        const status = statusOf(row);
        c.all++;
        if (status in c) c[status]++;
        if (needsAttention(row)) c.attention++;
    }
    return c;
});

const toImport = computed(() => counts.value.new + counts.value.update);

// ----- filter and pages -----------------------------------------------------

const FILTERS = [
    { key: 'all', label: 'All rows' },
    { key: 'attention', label: 'Needs attention' },
    { key: 'new', label: 'New' },
    { key: 'update', label: 'Updates' },
    { key: 'unchanged', label: 'Unchanged' },
    { key: 'sample', label: 'Samples' },
];

const filter = ref(counts.value.attention ? 'attention' : 'all');
const page = ref(1);
// Rows edited under a filter stay in view until the filter changes, so a fixed row doesn't vanish mid-edit.
const pinned = ref(new Set());

const matches = (row) => {
    switch (filter.value) {
        case 'all':
            return true;
        case 'attention':
            return needsAttention(row);
        default:
            return statusOf(row) === filter.value;
    }
};

const visible = computed(() => rows.value.filter((row) => matches(row) || pinned.value.has(row.uid)));
const pageCount = computed(() => Math.max(1, Math.ceil(visible.value.length / PAGE_SIZE)));
const pageRows = computed(() => visible.value.slice((page.value - 1) * PAGE_SIZE, page.value * PAGE_SIZE));

watch(filter, () => {
    page.value = 1;
    pinned.value = new Set();
});
watch(pageCount, (count) => {
    if (page.value > count) page.value = count;
});

// ----- checking --------------------------------------------------------------

const checking = ref(false);
const checkProblem = ref('');
let sequence = 0;
let timer = null;
let controller = null;

const payload = () =>
    rows.value
        .filter((row) => !isBlank(row))
        .map((row) => ({ row: row.row, cells: row.cells, sample: row.sample }));

const check = async () => {
    clearTimeout(timer);
    controller?.abort();
    controller = new AbortController();
    const mine = ++sequence;

    checking.value = true;
    checkProblem.value = '';
    try {
        const { data } = await axios.post(
            props.checkUrl,
            { rows: payload() },
            { signal: controller.signal, headers: { Accept: 'application/json' } },
        );
        if (mine !== sequence) return; // a newer check is on its way

        const byRow = new Map(data.rows.map((r) => [r.row, r]));
        for (const row of rows.value) {
            row.result = byRow.get(row.row) ?? null;
            row.stale = false;
        }
    } catch (error) {
        if (axios.isCancel(error) || mine !== sequence) return;
        checkProblem.value = 'The rows could not be checked. Check your connection and press Re-check.';
    } finally {
        if (mine === sequence) checking.value = false;
    }
};

const scheduleCheck = () => {
    clearTimeout(timer);
    timer = setTimeout(check, CHECK_DELAY_MS);
};

onBeforeUnmount(() => {
    clearTimeout(timer);
    controller?.abort();
});

const edited = (row) => {
    touched.value = true;
    row.stale = true;
    // Typing over a sample row makes it a real row, as the template says.
    row.sample = false;
    pinned.value.add(row.uid);
    scheduleCheck();
};

const setCell = (row, key, value) => {
    row.cells[key] = value;
    edited(row);
};

const applySuggestion = (row, key) => setCell(row, key, row.result.suggestions[key]);

// Suggestions that fix a problem (not the "did you mean a new code?" hint on a rename).
const fixable = computed(() =>
    rows.value.flatMap((row) =>
        statusOf(row) === 'error'
            ? Object.keys(row.result?.suggestions || {})
                  .filter((key) => row.result.errors?.[key])
                  .map((key) => [row, key])
            : [],
    ),
);

const applyAllFixes = () => {
    for (const [row, key] of fixable.value) {
        row.cells[key] = row.result.suggestions[key];
        edited(row);
    }
    check();
};

const importAnyway = (row) => {
    row.sample = false;
    row.stale = true;
    touched.value = true;
    pinned.value.add(row.uid);
    check();
};

const removeRow = (row) => {
    rows.value = rows.value.filter((r) => r.uid !== row.uid);
    touched.value = true;
    scheduleCheck();
};

const addRow = async () => {
    const number = Math.max(1, ...rows.value.map((r) => r.row)) + 1;
    const row = { uid: ++uid, row: number, cells: blankCells(), sample: false, result: null, stale: false };
    rows.value.push(row);
    touched.value = true;
    filter.value = 'all';
    await nextTick();
    page.value = pageCount.value;
    pinned.value = new Set([row.uid]);
    await nextTick();
    document.querySelector(`[data-cell="${row.uid}:${columns.value[0].key}"]`)?.focus();
};

// Enter / Shift+Enter move down / up in the same column, like a spreadsheet.
const moveFocus = (row, key, step) => {
    const list = pageRows.value;
    const next = list[list.findIndex((r) => r.uid === row.uid) + step];
    if (next) document.querySelector(`[data-cell="${next.uid}:${key}"]`)?.focus();
};

// ----- cell helpers ----------------------------------------------------------

const errorOf = (row, key) => row.result?.errors?.[key];
const warningOf = (row, key) => row.result?.warnings?.[key];
const wasOf = (row, key) => row.result?.was?.[key];
const fillHint = (row, key) => {
    const value = row.result?.fill?.[key];
    if (value === undefined || value === '') return '';
    return row.result.fill_kind === 'kept' ? `keeps ${value}` : `${value} (default)`;
};

const cellClass = (row, column) => {
    if (statusOf(row) === 'sample') return 'bg-amber-50/70 italic text-amber-900 dark:bg-amber-900/10 dark:text-amber-200';
    if (errorOf(row, column.key)) return 'bg-red-50 ring-1 ring-inset ring-red-400 dark:bg-red-900/25 dark:ring-red-500';
    if (warningOf(row, column.key)) return 'bg-amber-50 ring-1 ring-inset ring-amber-400 dark:bg-amber-900/25 dark:ring-amber-500';
    if (wasOf(row, column.key) !== undefined) return 'bg-blue-50 dark:bg-blue-900/20';
    return '';
};

const choiceMissing = (column, value) => value !== '' && value != null && !column.choices.some((c) => c.value === value);

/** Messages listed under a row, each with the cell it belongs to. */
const notes = (row) => {
    const result = row.result;
    if (!result || statusOf(row) === 'sample') return [];
    const list = [];
    for (const column of columns.value) {
        const key = column.key;
        if (result.errors?.[key]) list.push({ kind: 'error', key, text: result.errors[key] });
        if (result.warnings?.[key]) list.push({ kind: 'warning', key, text: result.warnings[key] });
    }
    for (const text of result.row_errors || []) list.push({ kind: 'error', key: null, text });
    return list;
};

const minWidth = (column) =>
    column.key === 'code' ? 'min-w-[8rem]' : ['name', 'title'].includes(column.key) ? 'min-w-[14rem]' : column.type === 'textarea' ? 'min-w-[16rem]' : 'min-w-[9rem]';

// ----- status badge ----------------------------------------------------------

const STATUS = {
    new: { label: 'New', class: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' },
    update: { label: 'Update', class: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300' },
    unchanged: { label: 'Unchanged', class: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' },
    error: { label: 'Problem', class: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' },
    sample: { label: 'Sample', class: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' },
    blank: { label: 'Empty', class: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' },
    pending: { label: 'Checking', class: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' },
};

const number = (n) => Number(n ?? 0).toLocaleString();
const plural = computed(() => props.definition.label.toLowerCase());
const singular = computed(() => props.definition.singular.toLowerCase());

// ----- finish ----------------------------------------------------------------

const importing = ref(false);
const waiting = computed(() => checking.value || rows.value.some((r) => r.stale));

const skippedNote = computed(() => {
    const parts = [];
    if (counts.value.error) parts.push(`${number(counts.value.error)} with problems`);
    if (counts.value.sample) parts.push(`${number(counts.value.sample)} sample`);
    if (counts.value.unchanged) parts.push(`${number(counts.value.unchanged)} unchanged`);
    return parts.length ? `Skipped: ${parts.join(', ')}.` : '';
});

const submit = () => {
    if (!toImport.value || waiting.value || importing.value) return;

    router.post(
        props.importUrl,
        { file_name: props.preview.file, rows: payload() },
        {
            preserveScroll: true,
            onStart: () => (importing.value = true),
            onFinish: () => (importing.value = false),
            onSuccess: () => emit('imported'),
        },
    );
};

const cancel = async () => {
    if (touched.value) {
        const { showConfirm } = useSweetAlert();
        const answer = await showConfirm('Your changes in this review will be lost. Nothing has been imported.', 'Discard this import?');
        if (!answer.isConfirmed) return;
    }
    emit('close');
};
</script>

<template>
    <section class="rounded-lg bg-white shadow-sm ring-1 ring-indigo-200 dark:bg-gray-800 dark:ring-indigo-900" aria-label="Review import">
        <!-- Header -->
        <div class="flex flex-col gap-3 border-b border-gray-200 p-4 sm:flex-row sm:items-start sm:justify-between dark:border-gray-700">
            <div class="min-w-0">
                <h3 class="flex items-center gap-2 font-semibold text-gray-900 dark:text-gray-100">
                    <FileSpreadsheet :size="18" class="shrink-0 text-indigo-500" />
                    <span class="truncate">Review import — {{ preview.file }}</span>
                </h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Nothing is saved yet. Fix marked cells here (they are checked again as you type), apply a suggested code, or remove a row —
                    then import. Rows that still have problems are skipped.
                </p>
            </div>
            <button type="button" class="shrink-0 self-end text-gray-400 hover:text-gray-600 sm:self-start dark:hover:text-gray-300" aria-label="Close review" @click="cancel">
                <X :size="18" />
            </button>
        </div>

        <!-- Filters and tools -->
        <div class="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap gap-1.5" role="tablist" aria-label="Show rows">
                <button
                    v-for="item in FILTERS"
                    :key="item.key"
                    type="button"
                    role="tab"
                    :aria-selected="filter === item.key"
                    :class="[
                        'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors',
                        filter === item.key
                            ? 'bg-indigo-600 text-white'
                            : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600',
                    ]"
                    @click="filter = item.key"
                >
                    <AlertTriangle v-if="item.key === 'attention' && counts.attention" :size="12" />
                    {{ item.label }}
                    <span :class="['rounded-full px-1.5 text-[11px]', filter === item.key ? 'bg-indigo-500' : 'bg-white dark:bg-gray-800']">
                        {{ number(counts[item.key]) }}
                    </span>
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span v-if="waiting" class="inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                    <Loader2 :size="14" class="animate-spin" /> Checking…
                </span>
                <button
                    v-if="fixable.length"
                    type="button"
                    class="inline-flex items-center gap-1 rounded-md bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-900/40 dark:text-indigo-300 dark:hover:bg-indigo-900/60"
                    @click="applyAllFixes"
                >
                    <Wand2 :size="14" /> Apply {{ number(fixable.length) }} suggested {{ fixable.length === 1 ? 'fix' : 'fixes' }}
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                    @click="addRow"
                >
                    <Plus :size="14" /> Add row
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                    :disabled="checking"
                    @click="check"
                >
                    <RefreshCw :size="14" :class="checking ? 'animate-spin' : ''" /> Re-check
                </button>
            </div>
        </div>

        <p v-if="checkProblem" class="mx-4 mb-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ checkProblem }}</p>

        <!-- The sheet -->
        <div class="max-h-[65vh] overflow-auto border-y border-gray-200 dark:border-gray-700">
            <table class="min-w-full border-separate border-spacing-0 text-sm">
                <thead class="sticky top-0 z-10">
                    <tr class="bg-gray-100 text-center text-[11px] font-medium text-gray-400 dark:bg-gray-900 dark:text-gray-500">
                        <th class="sticky left-0 z-20 w-12 border-b border-r border-gray-200 bg-gray-100 dark:border-gray-700 dark:bg-gray-900"></th>
                        <th class="border-b border-r border-gray-200 dark:border-gray-700"></th>
                        <th v-for="(column, i) in columns" :key="column.key" class="border-b border-r border-gray-200 py-0.5 dark:border-gray-700">{{ letter(i) }}</th>
                        <th class="border-b border-gray-200 dark:border-gray-700"></th>
                    </tr>
                    <tr class="bg-gray-50 text-left text-xs font-semibold text-gray-600 dark:bg-gray-900/80 dark:text-gray-300">
                        <th class="sticky left-0 z-20 border-b border-r border-gray-200 bg-gray-50 px-2 py-2 text-center dark:border-gray-700 dark:bg-gray-900">Row</th>
                        <th class="border-b border-r border-gray-200 px-2 py-2 dark:border-gray-700">Status</th>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            :class="['border-b border-r border-gray-200 px-2 py-2 whitespace-nowrap dark:border-gray-700', minWidth(column)]"
                        >
                            {{ column.label }}<span v-if="column.required" class="text-red-500" title="Required"> *</span>
                            <span v-if="column.match" class="ml-1 rounded bg-indigo-100 px-1 text-[10px] font-medium text-indigo-700 dark:bg-indigo-900/50 dark:text-indigo-300" title="Rows are matched by this — it must be unique">unique</span>
                        </th>
                        <th class="border-b border-gray-200 px-2 py-2 dark:border-gray-700"><span class="sr-only">Remove</span></th>
                    </tr>
                </thead>

                <tbody>
                    <template v-for="row in pageRows" :key="row.uid">
                        <tr :class="['group', row.stale ? 'opacity-70' : '']">
                            <td class="sticky left-0 z-[5] border-b border-r border-gray-200 bg-gray-50 px-2 text-center text-xs text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
                                {{ row.row }}
                            </td>
                            <td class="border-b border-r border-gray-200 px-2 dark:border-gray-700">
                                <span :class="['inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium', STATUS[statusOf(row)].class]">
                                    {{ STATUS[statusOf(row)].label }}
                                </span>
                            </td>
                            <td
                                v-for="column in columns"
                                :key="column.key"
                                :class="['border-b border-r border-gray-200 p-0 align-top dark:border-gray-700', cellClass(row, column)]"
                                :title="errorOf(row, column.key) || warningOf(row, column.key) || undefined"
                            >
                                <select
                                    v-if="column.choices"
                                    :value="row.cells[column.key]"
                                    :data-cell="`${row.uid}:${column.key}`"
                                    :aria-label="`${column.label}, row ${row.row}`"
                                    class="block w-full cursor-pointer border-0 bg-transparent py-1.5 pl-2 pr-7 text-sm text-inherit focus:ring-2 focus:ring-inset focus:ring-indigo-500"
                                    @change="setCell(row, column.key, $event.target.value)"
                                >
                                    <option value="">{{ fillHint(row, column.key) ? `— ${fillHint(row, column.key)} —` : '—' }}</option>
                                    <option v-if="choiceMissing(column, row.cells[column.key])" :value="row.cells[column.key]">⚠ {{ row.cells[column.key] }}</option>
                                    <option v-for="choice in column.choices" :key="choice.value" :value="choice.value">{{ choice.label }}</option>
                                </select>
                                <input
                                    v-else
                                    :value="row.cells[column.key]"
                                    type="text"
                                    :inputmode="column.type === 'number' ? 'decimal' : undefined"
                                    :placeholder="fillHint(row, column.key)"
                                    :data-cell="`${row.uid}:${column.key}`"
                                    :aria-label="`${column.label}, row ${row.row}`"
                                    :class="[
                                        'block w-full border-0 bg-transparent px-2 py-1.5 text-sm text-inherit placeholder:italic placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-500 dark:placeholder:text-gray-500',
                                        column.uppercase ? 'uppercase placeholder:normal-case' : '',
                                        column.lowercase ? 'lowercase placeholder:normal-case' : '',
                                        column.type === 'number' ? 'text-right' : '',
                                    ]"
                                    @input="setCell(row, column.key, $event.target.value)"
                                    @keydown.enter.prevent="moveFocus(row, column.key, $event.shiftKey ? -1 : 1)"
                                />
                                <p v-if="wasOf(row, column.key) !== undefined" class="px-2 pb-1 text-[11px] leading-tight text-blue-700 dark:text-blue-300">
                                    was: <span class="line-through">{{ wasOf(row, column.key) || 'empty' }}</span>
                                </p>
                                <button
                                    v-if="row.result?.suggestions?.[column.key] && statusOf(row) !== 'sample'"
                                    type="button"
                                    class="mx-1 mb-1 inline-flex items-center gap-1 rounded bg-indigo-600 px-1.5 py-0.5 text-[11px] font-medium text-white hover:bg-indigo-700"
                                    :title="`Use ${row.result.suggestions[column.key]}`"
                                    @click="applySuggestion(row, column.key)"
                                >
                                    <Sparkles :size="11" /> Use {{ row.result.suggestions[column.key] }}
                                </button>
                            </td>
                            <td class="border-b border-gray-200 px-1 text-center dark:border-gray-700">
                                <button
                                    type="button"
                                    class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30 dark:hover:text-red-400"
                                    :title="`Remove row ${row.row} from this import`"
                                    @click="removeRow(row)"
                                >
                                    <Trash2 :size="15" />
                                </button>
                            </td>
                        </tr>

                        <!-- What needs attention in this row -->
                        <tr v-if="statusOf(row) === 'sample'">
                            <td class="sticky left-0 z-[5] border-b border-r border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900"></td>
                            <td :colspan="columns.length + 2" class="border-b border-gray-200 bg-amber-50/50 px-3 py-1.5 text-xs text-amber-800 dark:border-gray-700 dark:bg-amber-900/10 dark:text-amber-300">
                                Sample row from the template — it will be skipped. Type over it to use it for a real {{ singular }}, or
                                <button type="button" class="font-semibold underline hover:no-underline" @click="importAnyway(row)">import it as it is</button>.
                            </td>
                        </tr>
                        <tr v-else-if="notes(row).length">
                            <td class="sticky left-0 z-[5] border-b border-r border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900"></td>
                            <td :colspan="columns.length + 2" class="border-b border-gray-200 px-3 py-1.5 dark:border-gray-700">
                                <ul class="space-y-0.5 text-xs">
                                    <li
                                        v-for="(note, i) in notes(row)"
                                        :key="i"
                                        :class="['flex items-start gap-1.5', note.kind === 'error' ? 'text-red-700 dark:text-red-400' : 'text-amber-700 dark:text-amber-400']"
                                    >
                                        <AlertTriangle :size="12" class="mt-0.5 shrink-0" />
                                        <span>
                                            <b v-if="note.key" class="font-semibold">{{ columnLetter(note.key) }} · {{ columnLabel(note.key) }}:</b>
                                            {{ note.text }}
                                            <button
                                                v-if="note.key && row.result.suggestions?.[note.key]"
                                                type="button"
                                                class="ml-1 font-semibold text-indigo-600 underline hover:no-underline dark:text-indigo-400"
                                                @click="applySuggestion(row, note.key)"
                                            >
                                                Use {{ row.result.suggestions[note.key] }}
                                            </button>
                                        </span>
                                    </li>
                                </ul>
                            </td>
                        </tr>
                    </template>

                    <tr v-if="!pageRows.length">
                        <td :colspan="columns.length + 3" class="px-6 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                            <CheckCircle2 :size="20" class="mx-auto mb-1 text-green-500" />
                            {{ filter === 'attention' ? 'Nothing needs attention.' : 'No rows here.' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Footer -->
        <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                <div v-if="pageCount > 1" class="flex items-center gap-1">
                    <button type="button" class="rounded border border-gray-300 px-2 py-1 disabled:opacity-40 dark:border-gray-600" :disabled="page === 1" @click="page--">‹</button>
                    <span>Page {{ page }} of {{ pageCount }}</span>
                    <button type="button" class="rounded border border-gray-300 px-2 py-1 disabled:opacity-40 dark:border-gray-600" :disabled="page === pageCount" @click="page++">›</button>
                </div>
                <span>
                    <span class="inline-block h-2.5 w-2.5 rounded-sm bg-red-200 align-middle dark:bg-red-800"></span> problem
                    <span class="ml-2 inline-block h-2.5 w-2.5 rounded-sm bg-amber-200 align-middle dark:bg-amber-800"></span> check / sample
                    <span class="ml-2 inline-block h-2.5 w-2.5 rounded-sm bg-blue-200 align-middle dark:bg-blue-800"></span> changed
                    <span class="ml-2 italic">grey text = value an empty cell will get</span>
                </span>
            </div>

            <div class="flex flex-col items-stretch gap-1 sm:items-end">
                <div class="flex justify-end gap-2">
                    <SecondaryButton type="button" :disabled="importing" @click="cancel">Cancel</SecondaryButton>
                    <PrimaryButton type="button" :disabled="!toImport || waiting || importing" @click="submit">
                        <Loader2 v-if="importing" :size="16" class="mr-1.5 animate-spin" />
                        {{ importing ? 'Importing…' : toImport ? `Import ${number(toImport)} ${toImport === 1 ? singular : plural}` : 'Nothing to import' }}
                    </PrimaryButton>
                </div>
                <p v-if="skippedNote" class="text-right text-xs text-gray-500 dark:text-gray-400">{{ skippedNote }}</p>
            </div>
        </div>
    </section>
</template>

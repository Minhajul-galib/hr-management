<script setup>
/**
 * Holidays Setup — the public-holiday list, the weekly off days, and the Excel
 * import. Holidays here are marked on everyone's calendar.
 *
 * Dates arrive as plain YYYY-MM-DD strings and are only turned into Date
 * objects at local midnight for display, so nothing shifts with the time zone.
 */
import { computed, nextTick, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { useSweetAlert } from '@/composables/useSweetAlert';
import {
    AlertTriangle,
    CalendarDays,
    CalendarOff,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    CloudUpload,
    Download,
    FileSpreadsheet,
    Pencil,
    Plus,
    Search,
    Trash2,
    X,
} from 'lucide-vue-next';

const props = defineProps({
    year: { type: Number, required: true },
    years: { type: Array, default: () => [] },
    holidays: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({ holidays: 0, days: 0 }) },
    next: { type: Object, default: null },
    settings: { type: Object, default: () => ({ weekday_off: [] }) },
    importResult: { type: Object, default: null },
    limits: { type: Object, default: () => ({ max_rows: 1000, max_mb: 5 }) },
    // What this person may do: { create, edit, delete, import }.
    can: { type: Object, default: () => ({}) },
});

const MIN_YEAR = 2000;
const MAX_YEAR = 2100;

const inputClass =
    'block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
const cardClass = 'bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg';

// ----- dates -------------------------------------------------------------------

const pad = (n) => String(n).padStart(2, '0');
const toDate = (iso) => {
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, m - 1, d);
};
const format = (iso, options) => new Intl.DateTimeFormat('en-GB', options).format(toDate(iso));
const weekday = (iso) => format(iso, { weekday: 'short' });
const dayMonth = (iso) => format(iso, { day: 'numeric', month: 'short' });
const fullDate = (iso) => format(iso, { day: 'numeric', month: 'short', year: 'numeric' });
const monthShort = (iso) => format(iso, { month: 'short' });
const dayNumber = (iso) => toDate(iso).getDate();
const monthHeading = (iso) => format(iso, { month: 'long', year: 'numeric' });

const todayIso = () => {
    const now = new Date();
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
};
const daysBetween = (from, to) => Math.round((toDate(to) - toDate(from)) / 86400000);
const lastDay = (holiday) => holiday.end_date ?? holiday.start_date;
const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;

const rangeLabel = (holiday) =>
    holiday.end_date
        ? `${weekday(holiday.start_date)}, ${dayMonth(holiday.start_date)} – ${weekday(holiday.end_date)}, ${fullDate(holiday.end_date)}`
        : `${weekday(holiday.start_date)}, ${fullDate(holiday.start_date)}`;

/** past | now | upcoming, against the viewer's own today. */
const timing = (holiday) => {
    const today = todayIso();
    if (lastDay(holiday) < today) return 'past';
    if (holiday.start_date <= today) return 'now';
    return 'upcoming';
};

const TIMING_BADGE = {
    past: { label: 'Past', class: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' },
    now: { label: 'Happening now', class: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' },
    upcoming: { label: 'Upcoming', class: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300' },
};

const countdown = (holiday) => {
    if (timing(holiday) === 'now') return 'Happening now';
    const n = daysBetween(todayIso(), holiday.start_date);
    return n === 1 ? 'Tomorrow' : `In ${n} days`;
};

// ----- year, search, filter ------------------------------------------------------

const search = ref('');
const status = ref('all');
const STATUS_FILTERS = [
    { value: 'all', label: 'All' },
    { value: 'upcoming', label: 'Upcoming' },
    { value: 'past', label: 'Past' },
];

const goToYear = (year) => {
    year = Number(year);
    if (!year || year === props.year || year < MIN_YEAR || year > MAX_YEAR) return;

    router.get(
        route('calendar.holidays.setup'),
        { year },
        { preserveState: true, preserveScroll: true, replace: true, only: ['year', 'years', 'holidays', 'stats'] },
    );
};

const filtered = computed(() => {
    const query = search.value.trim().toLowerCase();

    return props.holidays.filter((holiday) => {
        const when = timing(holiday);
        if (status.value === 'upcoming' && when === 'past') return false;
        if (status.value === 'past' && when !== 'past') return false;
        if (!query) return true;

        const haystack = [
            holiday.title,
            holiday.description,
            fullDate(holiday.start_date),
            holiday.end_date ? fullDate(holiday.end_date) : '',
        ];

        return haystack.join(' ').toLowerCase().includes(query);
    });
});

/** Holidays are sorted by date already; split them under month headings. */
const groups = computed(() => {
    const result = [];

    for (const holiday of filtered.value) {
        const key = holiday.start_date.slice(0, 7);
        let group = result[result.length - 1];
        if (!group || group.key !== key) {
            group = { key, label: monthHeading(holiday.start_date), items: [] };
            result.push(group);
        }
        group.items.push(holiday);
    }

    return result;
});

// ----- weekly off days -------------------------------------------------------------

const DAYS = [
    { value: 0, short: 'Sun', long: 'Sunday' },
    { value: 1, short: 'Mon', long: 'Monday' },
    { value: 2, short: 'Tue', long: 'Tuesday' },
    { value: 3, short: 'Wed', long: 'Wednesday' },
    { value: 4, short: 'Thu', long: 'Thursday' },
    { value: 5, short: 'Fri', long: 'Friday' },
    { value: 6, short: 'Sat', long: 'Saturday' },
];

const weekly = useForm({ weekday_off: [...props.settings.weekday_off] });

const isOff = (day) => weekly.weekday_off.includes(day);
const setOffDays = (days) => (weekly.weekday_off = [...new Set(days)].sort((a, b) => a - b));
const toggleDay = (day) => setOffDays(isOff(day) ? weekly.weekday_off.filter((d) => d !== day) : [...weekly.weekday_off, day]);

const offSummary = computed(() => {
    const names = DAYS.filter((d) => isOff(d.value)).map((d) => d.long);
    if (!names.length) return 'No weekly off days — every weekday counts as a working day.';
    return `${names.join(' and ')} ${names.length === 1 ? 'is' : 'are'} off every week.`;
});

const offShort = computed(() => {
    const names = DAYS.filter((d) => props.settings.weekday_off.includes(d.value)).map((d) => d.short);
    return names.length ? names.join(', ') : 'None';
});

const saveWeekly = () =>
    weekly.post(route('calendar.holidays.settings.update'), { preserveScroll: true, preserveState: true });

// Once saved, the server's answer becomes the new "unchanged" state.
watch(
    () => props.settings.weekday_off,
    (days) => {
        weekly.defaults({ weekday_off: [...days] });
        weekly.reset();
    },
    { deep: true },
);

// ----- add / edit ----------------------------------------------------------------------

const showForm = ref(false);
const editing = ref(null);
const multiDay = ref(false);

const form = useForm({ title: '', description: '', start: '', end: '' }).transform((data) => ({
    ...data,
    end: multiDay.value ? data.end : '',
}));

const defaultStart = () => (props.year === new Date().getFullYear() ? todayIso() : `${props.year}-01-01`);

const openCreate = () => {
    editing.value = null;
    multiDay.value = false;
    form.defaults({ title: '', description: '', start: defaultStart(), end: '' });
    form.reset();
    form.clearErrors();
    showForm.value = true;
};

const openEdit = (holiday) => {
    editing.value = holiday;
    multiDay.value = !!holiday.end_date;
    form.defaults({
        title: holiday.title,
        description: holiday.description ?? '',
        start: holiday.start_date,
        end: holiday.end_date ?? '',
    });
    form.reset();
    form.clearErrors();
    showForm.value = true;
};

const closeForm = () => {
    showForm.value = false;
};

const submitForm = () => {
    const options = { preserveScroll: true, preserveState: true, onSuccess: closeForm };

    if (editing.value) {
        form.put(route('calendar.holidays.update', editing.value.id), options);
    } else {
        form.post(route('calendar.holidays.store'), options);
    }
};

// Keep the end date from falling behind the start date.
watch(
    () => form.start,
    (start) => {
        if (multiDay.value && form.end && start && form.end < start) form.end = start;
    },
);

watch(multiDay, (on) => {
    if (on && !form.end) form.end = form.start;
});

const isIso = (value) => /^\d{4}-\d{2}-\d{2}$/.test(value);

const formSummary = computed(() => {
    if (!isIso(form.start)) return '';

    const end = multiDay.value && isIso(form.end) && form.end >= form.start ? form.end : form.start;
    const days = daysBetween(form.start, end) + 1;

    return days === 1
        ? `${plural(1, 'day')} · ${weekday(form.start)}, ${fullDate(form.start)}`
        : `${plural(days, 'day')} · ${weekday(form.start)} ${dayMonth(form.start)} – ${weekday(end)} ${dayMonth(end)}`;
});

// ----- delete -----------------------------------------------------------------------------

const escapeHtml = (value) =>
    String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

const remove = async (holiday) => {
    const { showDeleteConfirm } = useSweetAlert();
    const result = await showDeleteConfirm(
        `Delete the holiday "<b>${escapeHtml(holiday.title)}</b>" (${escapeHtml(rangeLabel(holiday))})?<br>` +
            'It will no longer be marked on the calendar.',
    );

    if (result.isConfirmed) {
        router.delete(route('calendar.holidays.destroy', holiday.id), { preserveScroll: true, preserveState: true });
    }
};

// ----- Excel import --------------------------------------------------------------------------

const ALLOWED_EXTENSIONS = ['xlsx', 'xls', 'csv'];

const showImport = ref(false);
const fileInput = ref(null);
const dragging = ref(false);
const fileProblem = ref('');
const importForm = useForm({ file: null });

const fileSize = (bytes) => (bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`);

const openImport = () => {
    importForm.reset();
    importForm.clearErrors();
    fileProblem.value = '';
    showImport.value = true;
};

const closeImport = () => {
    if (!importForm.processing) showImport.value = false;
};

const chooseFile = (file) => {
    fileProblem.value = '';
    importForm.clearErrors();
    if (!file) return;

    const extension = file.name.split('.').pop().toLowerCase();
    if (!ALLOWED_EXTENSIONS.includes(extension)) {
        fileProblem.value = 'Choose an Excel file (.xlsx or .xls) or a .csv file.';
        return;
    }
    if (file.size > props.limits.max_mb * 1024 * 1024) {
        fileProblem.value = `That file is larger than ${props.limits.max_mb} MB.`;
        return;
    }

    importForm.file = file;
};

const onDrop = (event) => {
    dragging.value = false;
    chooseFile(event.dataTransfer?.files?.[0]);
};

const clearFile = () => {
    importForm.file = null;
    fileProblem.value = '';
    if (fileInput.value) fileInput.value.value = '';
};

const submitImport = () => {
    if (!importForm.file) return;

    importForm.post(route('calendar.holidays.import'), {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            showImport.value = false;
            clearFile();
        },
    });
};

// What the last upload did. It arrives once, right after the upload, and stays
// on the page (a dialog would hide the success popup the layout shows).
const lastImport = ref(props.importResult);
const importPanel = ref(null);

watch(
    () => props.importResult,
    (result) => {
        if (!result) return;
        lastImport.value = result;
        nextTick(() => importPanel.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    },
);

const more = (total, shown) => Math.max(0, total - shown.length);
</script>

<template>
    <Head title="Holidays Setup" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">Holidays Setup</h2>
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                        Public holidays and weekly off days. They are marked on everyone's calendar.
                    </p>
                </div>

                <div v-if="can.import || can.create" class="flex flex-wrap items-center gap-2">
                    <a
                        v-if="can.import"
                        :href="route('calendar.holidays.template')"
                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm transition duration-150 ease-in-out hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                    >
                        <Download :size="16" class="mr-1.5" />
                        Template
                    </a>
                    <SecondaryButton v-if="can.import" type="button" @click="openImport">
                        <FileSpreadsheet :size="16" class="mr-1.5" />
                        Import Excel
                    </SecondaryButton>
                    <PrimaryButton v-if="can.create" type="button" @click="openCreate">
                        <Plus :size="16" class="mr-1.5" />
                        Add Holiday
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-4 lg:px-4 xl:max-w-[1440px] 2xl:max-w-[1600px]">
                    <!-- Result of the last import -->
                    <section
                        v-if="lastImport"
                        ref="importPanel"
                        :class="[
                            cardClass,
                            'border-l-4 p-5',
                            lastImport.fatal || (!lastImport.created_count && lastImport.total)
                                ? 'border-red-500'
                                : lastImport.skipped_count || lastImport.error_count
                                  ? 'border-amber-500'
                                  : 'border-green-500',
                        ]"
                        aria-live="polite"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <h3 class="flex items-center gap-2 font-semibold text-gray-900 dark:text-gray-100">
                                    <FileSpreadsheet :size="18" class="shrink-0 text-gray-400" />
                                    <span class="truncate">Import result — {{ lastImport.file }}</span>
                                </h3>

                                <p v-if="lastImport.fatal" class="mt-2 text-sm text-red-700 dark:text-red-400">
                                    {{ lastImport.fatal }}
                                </p>
                                <p v-else-if="!lastImport.total" class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                    The file has no holiday rows to import.
                                </p>
                                <div v-else class="mt-2 flex flex-wrap gap-2 text-sm">
                                    <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-0.5 font-medium text-green-800 dark:bg-green-900/40 dark:text-green-300">
                                        <CheckCircle2 :size="14" /> {{ lastImport.created_count }} added
                                    </span>
                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                                        {{ lastImport.skipped_count }} skipped
                                    </span>
                                    <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-0.5 font-medium text-red-800 dark:bg-red-900/40 dark:text-red-300">
                                        <AlertTriangle :size="14" /> {{ lastImport.error_count }} need attention
                                    </span>
                                    <span class="self-center text-gray-500 dark:text-gray-400">of {{ lastImport.total }} rows</span>
                                </div>
                            </div>
                            <button
                                type="button"
                                class="shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                aria-label="Dismiss import result"
                                @click="lastImport = null"
                            >
                                <X :size="18" />
                            </button>
                        </div>

                        <div v-if="lastImport.errors.length || lastImport.skipped.length" class="mt-4 grid gap-4 lg:grid-cols-2">
                            <div v-if="lastImport.errors.length">
                                <h4 class="text-sm font-medium text-red-700 dark:text-red-400">Rows to fix, then upload again</h4>
                                <ul class="mt-2 max-h-60 divide-y divide-gray-100 overflow-y-auto rounded-md border border-gray-200 text-sm dark:divide-gray-700 dark:border-gray-700">
                                    <li v-for="item in lastImport.errors" :key="item.row" class="flex gap-3 px-3 py-2">
                                        <span class="w-14 shrink-0 text-gray-500 dark:text-gray-400">Row {{ item.row }}</span>
                                        <span class="text-gray-800 dark:text-gray-200">{{ item.message }}</span>
                                    </li>
                                </ul>
                                <p v-if="more(lastImport.error_count, lastImport.errors)" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    …and {{ more(lastImport.error_count, lastImport.errors) }} more.
                                </p>
                            </div>

                            <div v-if="lastImport.skipped.length">
                                <h4 class="text-sm font-medium text-amber-700 dark:text-amber-400">Skipped — nothing to do</h4>
                                <ul class="mt-2 max-h-60 divide-y divide-gray-100 overflow-y-auto rounded-md border border-gray-200 text-sm dark:divide-gray-700 dark:border-gray-700">
                                    <li v-for="item in lastImport.skipped" :key="item.row" class="flex gap-3 px-3 py-2">
                                        <span class="w-14 shrink-0 text-gray-500 dark:text-gray-400">Row {{ item.row }}</span>
                                        <span class="text-gray-800 dark:text-gray-200">
                                            <b class="font-medium">{{ item.title }}</b> — {{ item.reason }}
                                        </span>
                                    </li>
                                </ul>
                                <p v-if="more(lastImport.skipped_count, lastImport.skipped)" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    …and {{ more(lastImport.skipped_count, lastImport.skipped) }} more.
                                </p>
                            </div>
                        </div>
                    </section>

                    <!-- Summary -->
                    <div class="grid grid-cols-2 gap-4 px-4 sm:px-0 xl:grid-cols-4">
                        <div :class="[cardClass, 'p-4']">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Holidays in {{ year }}</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ stats.holidays }}</p>
                        </div>
                        <div :class="[cardClass, 'p-4']">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Days off in {{ year }}</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ stats.days }}</p>
                        </div>
                        <div :class="[cardClass, 'col-span-2 p-4 sm:col-span-1']">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Next holiday</p>
                            <template v-if="next">
                                <p class="mt-1 truncate text-lg font-semibold text-gray-900 dark:text-gray-100" :title="next.title">{{ next.title }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ countdown(next) }} · {{ dayMonth(next.start_date) }}</p>
                            </template>
                            <p v-else class="mt-1 text-sm text-gray-500 dark:text-gray-400">None scheduled</p>
                        </div>
                        <div :class="[cardClass, 'col-span-2 p-4 sm:col-span-1']">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Weekly off</p>
                            <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ offShort }}</p>
                        </div>
                    </div>

                    <div class="grid gap-6 lg:grid-cols-3">
                        <!-- Holiday list -->
                        <section :class="[cardClass, 'lg:col-span-2']">
                            <div class="flex flex-col gap-3 border-b border-gray-200 p-4 dark:border-gray-700 sm:p-5 md:flex-row md:items-center md:justify-between">
                                <div class="flex items-center gap-1">
                                    <button
                                        type="button"
                                        class="rounded-md p-2 text-gray-500 hover:bg-gray-100 disabled:opacity-30 dark:text-gray-400 dark:hover:bg-gray-700"
                                        :disabled="year <= MIN_YEAR"
                                        aria-label="Previous year"
                                        @click="goToYear(year - 1)"
                                    >
                                        <ChevronLeft :size="18" />
                                    </button>
                                    <select
                                        :value="year"
                                        aria-label="Year"
                                        class="rounded-md border-gray-300 py-1.5 pl-3 pr-8 text-sm font-semibold focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                        @change="goToYear($event.target.value)"
                                    >
                                        <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                                    </select>
                                    <button
                                        type="button"
                                        class="rounded-md p-2 text-gray-500 hover:bg-gray-100 disabled:opacity-30 dark:text-gray-400 dark:hover:bg-gray-700"
                                        :disabled="year >= MAX_YEAR"
                                        aria-label="Next year"
                                        @click="goToYear(year + 1)"
                                    >
                                        <ChevronRight :size="18" />
                                    </button>
                                </div>

                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                    <div class="relative">
                                        <Search :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                                        <input
                                            v-model="search"
                                            type="text"
                                            placeholder="Search holidays..."
                                            autocomplete="off"
                                            class="w-full rounded-md border border-gray-300 py-2 pl-9 pr-8 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:w-56"
                                        />
                                        <button
                                            v-if="search"
                                            type="button"
                                            class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                            aria-label="Clear search"
                                            @click="search = ''"
                                        >
                                            <X :size="16" />
                                        </button>
                                    </div>
                                    <div class="inline-flex rounded-md border border-gray-300 p-0.5 dark:border-gray-600" role="group" aria-label="Show">
                                        <button
                                            v-for="option in STATUS_FILTERS"
                                            :key="option.value"
                                            type="button"
                                            :aria-pressed="status === option.value"
                                            :class="[
                                                'rounded px-3 py-1 text-xs font-medium transition-colors',
                                                status === option.value
                                                    ? 'bg-indigo-600 text-white'
                                                    : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700',
                                            ]"
                                            @click="status = option.value"
                                        >
                                            {{ option.label }}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Nothing at all this year -->
                            <div v-if="!holidays.length" class="px-6 py-14 text-center">
                                <CalendarOff :size="40" class="mx-auto text-gray-300 dark:text-gray-600" />
                                <h3 class="mt-3 font-medium text-gray-900 dark:text-gray-100">No holidays in {{ year }} yet</h3>
                                <p v-if="can.create || can.import" class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    Add them one by one, or upload a whole year from an Excel file.
                                </p>
                                <div v-if="can.create || can.import" class="mt-5 flex flex-wrap justify-center gap-2">
                                    <PrimaryButton v-if="can.create" type="button" @click="openCreate">
                                        <Plus :size="16" class="mr-1.5" />
                                        Add Holiday
                                    </PrimaryButton>
                                    <SecondaryButton v-if="can.import" type="button" @click="openImport">
                                        <FileSpreadsheet :size="16" class="mr-1.5" />
                                        Import Excel
                                    </SecondaryButton>
                                </div>
                            </div>

                            <!-- Search / filter matched nothing -->
                            <p v-else-if="!filtered.length" class="px-6 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                                Nothing matches your search.
                            </p>

                            <div v-else>
                                <div v-for="group in groups" :key="group.key">
                                    <h3 class="bg-gray-50 px-5 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-900/50 dark:text-gray-400">
                                        {{ group.label }}
                                    </h3>
                                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                                        <li
                                            v-for="holiday in group.items"
                                            :key="holiday.id"
                                            class="flex items-start gap-4 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/40 sm:px-5"
                                        >
                                            <div
                                                :class="[
                                                    'w-14 shrink-0 overflow-hidden rounded-lg border text-center',
                                                    timing(holiday) === 'past' ? 'border-gray-200 opacity-70 dark:border-gray-600' : 'border-red-200 dark:border-red-900',
                                                ]"
                                                aria-hidden="true"
                                            >
                                                <div class="bg-red-600 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
                                                    {{ monthShort(holiday.start_date) }}
                                                </div>
                                                <div class="pt-1 text-xl font-bold leading-none text-gray-900 dark:text-gray-100">
                                                    {{ dayNumber(holiday.start_date) }}
                                                </div>
                                                <div class="pb-1 text-[10px] text-gray-500 dark:text-gray-400">{{ weekday(holiday.start_date) }}</div>
                                            </div>

                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ holiday.title }}</p>
                                                    <span
                                                        v-if="holiday.days > 1"
                                                        class="rounded bg-red-50 px-1.5 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-300"
                                                    >
                                                        {{ plural(holiday.days, 'day') }}
                                                    </span>
                                                </div>
                                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ rangeLabel(holiday) }}</p>
                                                <p v-if="holiday.description" class="mt-0.5 line-clamp-2 text-sm text-gray-500 dark:text-gray-400">
                                                    {{ holiday.description }}
                                                </p>
                                            </div>

                                            <div class="flex shrink-0 items-center gap-3">
                                                <span :class="['hidden rounded-full px-2.5 py-0.5 text-xs font-medium sm:inline-flex', TIMING_BADGE[timing(holiday)].class]">
                                                    {{ TIMING_BADGE[timing(holiday)].label }}
                                                </span>
                                                <button
                                                    v-if="can.edit"
                                                    type="button"
                                                    class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300"
                                                    :title="`Edit ${holiday.title}`"
                                                    :aria-label="`Edit ${holiday.title}`"
                                                    @click="openEdit(holiday)"
                                                >
                                                    <Pencil :size="16" />
                                                </button>
                                                <button
                                                    v-if="can.delete"
                                                    type="button"
                                                    class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300"
                                                    :title="`Delete ${holiday.title}`"
                                                    :aria-label="`Delete ${holiday.title}`"
                                                    @click="remove(holiday)"
                                                >
                                                    <Trash2 :size="16" />
                                                </button>
                                            </div>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </section>

                        <!-- Side column -->
                        <div class="space-y-6">
                            <section :class="[cardClass, 'p-5']">
                                <h3 class="flex items-center gap-2 font-semibold text-gray-900 dark:text-gray-100">
                                    <CalendarDays :size="18" class="text-gray-400" />
                                    Weekly off days
                                </h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    Days the office is closed every week. They are shaded on the calendar.
                                </p>

                                <div class="mt-4 grid grid-cols-7 gap-1.5" role="group" aria-label="Weekly off days">
                                    <button
                                        v-for="day in DAYS"
                                        :key="day.value"
                                        type="button"
                                        :aria-pressed="isOff(day.value)"
                                        :title="day.long"
                                        :disabled="!can.edit"
                                        :class="[
                                            'rounded-md border py-2 text-xs font-semibold transition-colors disabled:cursor-default',
                                            isOff(day.value)
                                                ? 'border-indigo-600 bg-indigo-600 text-white'
                                                : 'border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700',
                                        ]"
                                        @click="toggleDay(day.value)"
                                    >
                                        {{ day.short }}
                                    </button>
                                </div>

                                <div v-if="can.edit" class="mt-3 flex flex-wrap gap-x-3 text-xs">
                                    <button type="button" class="text-indigo-600 hover:underline dark:text-indigo-400" @click="setOffDays([5])">Friday only</button>
                                    <button type="button" class="text-indigo-600 hover:underline dark:text-indigo-400" @click="setOffDays([5, 6])">Friday &amp; Saturday</button>
                                    <button type="button" class="text-indigo-600 hover:underline dark:text-indigo-400" @click="setOffDays([6, 0])">Saturday &amp; Sunday</button>
                                    <button type="button" class="text-gray-500 hover:underline dark:text-gray-400" @click="setOffDays([])">Clear</button>
                                </div>

                                <p class="mt-3 text-sm text-gray-700 dark:text-gray-300">{{ offSummary }}</p>
                                <InputError class="mt-2" :message="weekly.errors.weekday_off" />

                                <div v-if="can.edit" class="mt-4 flex items-center gap-3">
                                    <PrimaryButton type="button" :disabled="!weekly.isDirty || weekly.processing" @click="saveWeekly">
                                        Save
                                    </PrimaryButton>
                                    <span v-if="weekly.isDirty" class="text-xs text-amber-600 dark:text-amber-400">Unsaved changes</span>
                                </div>
                            </section>

                            <section v-if="can.import" :class="[cardClass, 'p-5']">
                                <h3 class="flex items-center gap-2 font-semibold text-gray-900 dark:text-gray-100">
                                    <FileSpreadsheet :size="18" class="text-gray-400" />
                                    Bulk import from Excel
                                </h3>
                                <ol class="mt-3 space-y-2 text-sm text-gray-600 dark:text-gray-300">
                                    <li class="flex gap-2"><span class="font-semibold text-indigo-600 dark:text-indigo-400">1.</span> Download the template.</li>
                                    <li class="flex gap-2"><span class="font-semibold text-indigo-600 dark:text-indigo-400">2.</span> Add one holiday per row — date, name, and an end date for multi-day holidays.</li>
                                    <li class="flex gap-2"><span class="font-semibold text-indigo-600 dark:text-indigo-400">3.</span> Upload it. Anything skipped is listed with its row number.</li>
                                </ol>
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <a
                                        :href="route('calendar.holidays.template')"
                                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                    >
                                        <Download :size="14" class="mr-1.5" />
                                        Template
                                    </a>
                                    <PrimaryButton type="button" @click="openImport">
                                        <CloudUpload :size="14" class="mr-1.5" />
                                        Upload file
                                    </PrimaryButton>
                                </div>
                            </section>
                        </div>
                    </div>
            </div>
        </div>

        <!-- Add / edit a holiday -->
        <Modal :show="showForm" max-width="lg" @close="closeForm">
            <form class="p-6" @submit.prevent="submitForm">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ editing ? 'Edit holiday' : 'Add holiday' }}
                </h3>

                <div class="mt-5 space-y-4">
                    <div>
                        <InputLabel for="holiday_title" value="Holiday name *" />
                        <input
                            id="holiday_title"
                            v-model="form.title"
                            type="text"
                            maxlength="255"
                            placeholder="e.g. Victory Day"
                            :class="[inputClass, 'mt-1']"
                            required
                            autofocus
                        />
                        <InputError class="mt-1" :message="form.errors.title" />
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel for="holiday_start" :value="multiDay ? 'First day *' : 'Date *'" />
                            <input
                                id="holiday_start"
                                v-model="form.start"
                                type="date"
                                :min="`${MIN_YEAR}-01-01`"
                                :max="`${MAX_YEAR}-12-31`"
                                :class="[inputClass, 'mt-1']"
                                required
                            />
                            <InputError class="mt-1" :message="form.errors.start" />
                        </div>

                        <div v-if="multiDay">
                            <InputLabel for="holiday_end" value="Last day *" />
                            <input
                                id="holiday_end"
                                v-model="form.end"
                                type="date"
                                :min="form.start || `${MIN_YEAR}-01-01`"
                                :max="`${MAX_YEAR}-12-31`"
                                :class="[inputClass, 'mt-1']"
                                required
                            />
                            <InputError class="mt-1" :message="form.errors.end" />
                        </div>
                    </div>

                    <label class="flex items-center gap-2">
                        <input
                            v-model="multiDay"
                            type="checkbox"
                            class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:border-gray-600"
                        />
                        <span class="text-sm text-gray-700 dark:text-gray-300">Runs for several days (e.g. Eid)</span>
                    </label>

                    <p v-if="formSummary" class="rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">
                        {{ formSummary }}
                    </p>

                    <div>
                        <InputLabel for="holiday_description" value="Description" />
                        <textarea
                            id="holiday_description"
                            v-model="form.description"
                            rows="2"
                            maxlength="1000"
                            placeholder="Optional note"
                            :class="[inputClass, 'mt-1']"
                        ></textarea>
                        <InputError class="mt-1" :message="form.errors.description" />
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton type="button" @click="closeForm">Cancel</SecondaryButton>
                    <PrimaryButton type="submit" :disabled="form.processing">
                        {{ editing ? 'Save changes' : 'Add holiday' }}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>

        <!-- Import from Excel -->
        <Modal :show="showImport" max-width="lg" :closeable="!importForm.processing" @close="closeImport">
            <form class="p-6" @submit.prevent="submitImport">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Import holidays from Excel</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Use the template so the columns match.
                    <a :href="route('calendar.holidays.template')" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                        Download template
                    </a>
                </p>

                <div
                    :class="[
                        'mt-5 flex cursor-pointer flex-col items-center rounded-lg border-2 border-dashed px-6 py-8 text-center transition-colors',
                        dragging
                            ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/20'
                            : 'border-gray-300 hover:border-indigo-400 dark:border-gray-600',
                    ]"
                    role="button"
                    tabindex="0"
                    @click="fileInput?.click()"
                    @keydown.enter.prevent="fileInput?.click()"
                    @keydown.space.prevent="fileInput?.click()"
                    @dragover.prevent="dragging = true"
                    @dragleave.prevent="dragging = false"
                    @drop.prevent="onDrop"
                >
                    <template v-if="importForm.file">
                        <FileSpreadsheet :size="32" class="text-green-600" />
                        <p class="mt-2 max-w-full truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ importForm.file.name }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ fileSize(importForm.file.size) }}</p>
                        <button
                            type="button"
                            class="mt-2 text-xs text-red-600 hover:underline dark:text-red-400"
                            :disabled="importForm.processing"
                            @click.stop="clearFile"
                        >
                            Choose a different file
                        </button>
                    </template>
                    <template v-else>
                        <CloudUpload :size="32" class="text-gray-400" />
                        <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                            <span class="font-medium text-indigo-600 dark:text-indigo-400">Choose a file</span> or drop it here
                        </p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            .xlsx, .xls or .csv · up to {{ limits.max_mb }} MB · up to {{ limits.max_rows.toLocaleString() }} rows
                        </p>
                    </template>

                    <input
                        ref="fileInput"
                        type="file"
                        class="sr-only"
                        accept=".xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv"
                        tabindex="-1"
                        @change="chooseFile($event.target.files?.[0])"
                    />
                </div>

                <InputError class="mt-2" :message="fileProblem || importForm.errors.file" />

                <ul class="mt-4 space-y-1 text-xs text-gray-500 dark:text-gray-400">
                    <li>• Columns: <b>Date</b>, <b>Holiday Name</b>, and optionally <b>End Date</b> and <b>Description</b>.</li>
                    <li>• A day that is already a holiday is skipped, so uploading twice is safe.</li>
                    <li>• Text dates: yyyy-mm-dd, or day first (16/12/2026).</li>
                </ul>

                <div v-if="importForm.processing && importForm.progress" class="mt-4">
                    <div class="h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-full bg-indigo-600 transition-all" :style="{ width: `${importForm.progress.percentage}%` }"></div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton type="button" :disabled="importForm.processing" @click="closeImport">Cancel</SecondaryButton>
                    <PrimaryButton type="submit" :disabled="!importForm.file || importForm.processing">
                        {{ importForm.processing ? 'Importing…' : 'Import' }}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>

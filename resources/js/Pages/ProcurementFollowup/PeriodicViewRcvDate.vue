<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import {
    BarChart3, Filter, RefreshCw, ClipboardList, TrendingUp,
    Package, CheckCircle2, AlertCircle, Calendar,
} from 'lucide-vue-next';
import { useApexChartTheme } from '@/composables/useApexChartTheme';

const { chartForeColor, chartGridColor, chartTooltipTheme, chartAxisTitleColor } = useApexChartTheme();

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    filterOptions: {
        type: Object,
        default: () => ({ fiscalYears: [] }),
    },
    merchantTeamNameMap: { type: Object, default: () => ({}) }, 
});

const page = usePage();
const permissions = computed(() => page.props.auth?.permissions || []);
const roles = computed(() => page.props.auth?.roles || []);
const isSuperAdmin = computed(() => roles.value.includes('Super System Admin'));

const canViewDataTable = computed(
    () =>
        isSuperAdmin.value ||
        permissions.value.includes('view procurement followup') ||
        permissions.value.includes('manage procurement followup') ||
        permissions.value.includes('view bookings') ||
        permissions.value.includes('manage bookings'),
);

const canViewPeriodicView = computed(
    () =>
        isSuperAdmin.value ||
        permissions.value.includes('view periodic view') ||
        permissions.value.includes('manage procurement followup') ||
        permissions.value.includes('manage bookings'),
);

const filterState = reactive({
    period:      'fiscal_year',
    fiscal_year: 'all',
    date_from:   '',
    date_to:     '',
});

const loading  = ref(false);
const data     = ref(null);
const errorMsg = ref(null);

// ── Fetch ──────────────────────────────────────────────────────────────────

const fetchData = async () => {
    if (filterState.period === 'fiscal_year' && !filterState.fiscal_year) return;
    if (filterState.period === 'custom' && (!filterState.date_from || !filterState.date_to)) return;

    loading.value  = true;
    errorMsg.value = null;
    try {
        const params = { period: filterState.period };
        if (filterState.period === 'fiscal_year' && filterState.fiscal_year && filterState.fiscal_year !== 'all') {
            params.fiscal_year = filterState.fiscal_year;
        }
        if (filterState.period === 'custom') {
            params.date_from = filterState.date_from;
            params.date_to   = filterState.date_to;
        }

        const res = await axios.get(route('procurement-followup.periodic-view-rcv-date-data'), { params });
        data.value = res.data;
    } catch (e) {
        errorMsg.value = e?.response?.data?.message || e?.message || 'Failed to load data';
    } finally {
        loading.value = false;
    }
};

let fetchTimer = null;
watch(
    () => ({ period: filterState.period, fiscal_year: filterState.fiscal_year, date_from: filterState.date_from, date_to: filterState.date_to }),
    () => { clearTimeout(fetchTimer); fetchTimer = setTimeout(fetchData, 280); },
    { deep: true },
);
onUnmounted(() => clearTimeout(fetchTimer));
onMounted(fetchData);

// ── Filter actions ────────────────────────────────────────────────────────

const availableFiscalYears = computed(() => props.filterOptions.fiscalYears ?? []);

const selectFiscalYear = (fy) => {
    filterState.fiscal_year = fy;
    filterState.period      = 'fiscal_year';
    filterState.date_from   = '';
    filterState.date_to     = '';
};

const selectPeriod = (p) => {
    if (p === 'fiscal_year') {
        filterState.period      = 'fiscal_year';
        filterState.fiscal_year = filterState.fiscal_year || 'all';
        filterState.date_from   = '';
        filterState.date_to     = '';
        return;
    }
    if (p === 'custom') {
        filterState.period      = 'custom';
        filterState.fiscal_year = 'all';
    }
};

// ── Computed ──────────────────────────────────────────────────────────────

const periodLabel = computed(() => ({
    'fiscal_year': filterState.fiscal_year === 'all' ? 'All Years' : (filterState.fiscal_year ? `FY ${filterState.fiscal_year}` : 'Fiscal Year'),
    'custom':      filterState.date_from && filterState.date_to
                       ? `${filterState.date_from} → ${filterState.date_to}`
                       : 'Custom Range',
}[filterState.period] ?? 'Select Period'));

const periodPillOn  = 'bg-emerald-600 border-emerald-600 text-white hover:bg-emerald-500';
const periodPillOff = 'bg-white border-gray-300 text-gray-800 hover:bg-emerald-50 hover:border-emerald-400 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-700/60 dark:hover:border-emerald-500';
const periodBase    = 'inline-flex items-center justify-center px-4 py-2 rounded-xl text-sm border font-semibold shadow-sm transition-all duration-150 cursor-pointer select-none active:scale-[0.98]';

// ── Helpers ───────────────────────────────────────────────────────────────

const resolveTeamName = (val) => props.merchantTeamNameMap[val] ?? val;

const fmtNum = (n) => {
    if (n === null || n === undefined || n === '') return '-';
    const x = Number(n);
    return Number.isNaN(x) ? '-' : x.toLocaleString(undefined, { maximumFractionDigits: 0 });
};

const fmtK = (n) => {
    const x = Number(n);
    if (Number.isNaN(x)) return '0';
    if (x >= 1_000_000) return (x / 1_000_000).toFixed(1) + 'M';
    if (x >= 1_000)     return (x / 1_000).toFixed(1) + 'K';
    return x.toFixed(0);
};

// KG → Metric Ton (1 MT = 1000 KG)
const fmtMT = (n) => {
    const x = Number(n);
    if (Number.isNaN(x)) return '0 MT';
    return (x / 1000).toLocaleString(undefined, { maximumFractionDigits: 2 }) + ' MT';
};

// ── Chart data ────────────────────────────────────────────────────────────

const metrics = computed(() => data.value?.metrics || {});
const charts  = computed(() => data.value?.charts  || {});

const COLORS = [
    '#10b981', '#6366f1', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#f97316', '#84cc16',
    '#ec4899', '#14b8a6', '#a855f7', '#0ea5e9', '#eab308', '#22c55e', '#f43f5e', '#d946ef',
];

const COUNT_COLOR_PALETTES = [
    ['#10b981', '#6ee7b7', '#a7f3d0', '#059669'],
    ['#6366f1', '#a5b4fc', '#c7d2fe', '#4338ca'],
    ['#f59e0b', '#fcd34d', '#fde68a', '#d97706'],
    ['#ef4444', '#fca5a5', '#fecaca', '#dc2626'],
    ['#8b5cf6', '#c4b5fd', '#ddd6fe', '#7c3aed'],
    ['#06b6d4', '#67e8f9', '#a5f3fc', '#0891b2'],
    ['#f97316', '#fdba74', '#fed7aa', '#ea580c'],
    ['#84cc16', '#bef264', '#d9f99d', '#65a30d'],
    ['#ec4899', '#f9a8d4', '#fbcfe8', '#db2777'],
    ['#14b8a6', '#5eead4', '#99f6e4', '#0d9488'],
];

const baseChart = (extra = {}) => ({
    chart: { toolbar: { show: false }, fontFamily: 'inherit', animations: { enabled: true, speed: 600 }, foreColor: chartForeColor() },
    dataLabels: { enabled: false },
    tooltip: { shared: true, intersect: false, theme: chartTooltipTheme() },
    legend: { position: 'top', horizontalAlign: 'left', fontSize: '12px', labels: { colors: chartForeColor() } },
    grid: { borderColor: chartGridColor() },
    ...extra,
});

const trendIsYearMode = computed(() =>
    filterState.period === 'fiscal_year' && (!filterState.fiscal_year || filterState.fiscal_year === 'all')
);

function buildQuarterlyData(raw) {
    if (!raw?.categories?.length || !raw?.series?.length) return { categories: [], series: [] };
    const acc = {};
    raw.categories.forEach((ym, i) => {
        const str  = String(ym ?? '');
        const year = str.slice(0, 4);
        const mon  = parseInt(str.slice(5, 7), 10);
        if (!year || parseInt(year, 10) < 2000 || !mon) return;
        const bucket = `${year}-Q${Math.ceil(mon / 3)}`;
        if (!acc[bucket]) acc[bucket] = {};
        raw.series.forEach(s => {
            acc[bucket][s.name] = (acc[bucket][s.name] ?? 0) + (parseFloat(s.data?.[i]) || 0);
        });
    });
    const buckets = Object.keys(acc).sort();
    if (!buckets.length) return { categories: [], series: [] };
    return {
        categories: buckets,
        series: raw.series.map(s => ({
            name: s.name,
            data: buckets.map(b => Math.round((acc[b]?.[s.name] ?? 0) * 1000) / 1000),
        })),
    };
}

const ALL_MONTHS   = [1,2,3,4,5,6,7,8,9,10,11,12];
const MONTH_SHORT  = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const yarnTimeAvgMode          = ref(false);
const yarnTimeSelectedMonths   = ref([...ALL_MONTHS]);

function toggleMonth(m) {
    const idx = yarnTimeSelectedMonths.value.indexOf(m);
    if (idx === -1) {
        yarnTimeSelectedMonths.value = [...yarnTimeSelectedMonths.value, m].sort((a,b) => a-b);
    } else if (yarnTimeSelectedMonths.value.length > 1) {
        yarnTimeSelectedMonths.value = yarnTimeSelectedMonths.value.filter(x => x !== m);
    }
}

function buildAvgData(raw, selectedMonths = ALL_MONTHS) {
    if (!raw?.categories?.length || !raw?.series?.length) return { categories: [], series: [] };
    const allowed = new Set(selectedMonths);
    const acc = {};
    raw.categories.forEach((ym, i) => {
        const mon = parseInt(String(ym ?? '').slice(5, 7), 10);
        if (!mon || mon < 1 || mon > 12 || !allowed.has(mon)) return;
        if (!acc[mon]) acc[mon] = {};
        raw.series.forEach(s => {
            if (!acc[mon][s.name]) acc[mon][s.name] = { sum: 0, count: 0 };
            const val = parseFloat(s.data?.[i]) || 0;
            if (val > 0) { acc[mon][s.name].sum += val; acc[mon][s.name].count += 1; }
        });
    });
    const months = [...allowed].filter(m => acc[m]).sort((a, b) => a - b);
    if (!months.length) return { categories: [], series: [] };
    return {
        categories: months.map(m => MONTH_SHORT[m - 1]),
        series: raw.series.map(s => ({
            name: s.name,
            data: months.map(m => {
                const d = acc[m]?.[s.name];
                return (!d || d.count === 0) ? 0 : Math.round((d.sum / d.count) * 1000) / 1000;
            }),
        })),
    };
}

const yarnChartDisplay = ref({ categories: [], series: [] });

watch(
    [() => charts.value.yarn_count_time_series, yarnTimeAvgMode, yarnTimeSelectedMonths],
    ([raw, avgMode]) => {
        yarnChartDisplay.value = avgMode
            ? buildAvgData(raw, yarnTimeSelectedMonths.value)
            : buildQuarterlyData(raw);
    },
    { deep: true, immediate: true },
);

const yarnTimeAvgYearRange = computed(() => {
    const cats = charts.value.yarn_count_time_series?.categories;
    if (!cats?.length) return '';
    const years = cats.map(ym => parseInt(String(ym ?? '').slice(0, 4), 10)).filter(y => y >= 2000);
    if (!years.length) return '';
    const min = Math.min(...years);
    const max = Math.max(...years);
    return min === max ? String(min) : `${min}–${max}`;
});

// Yearly aggregation for monthly trend when all years selected
const yearlyTrendData = computed(() => {
    const raw = charts.value.monthly_trend;
    if (!raw?.categories?.length) return { categories: [], series: [] };
    if (!trendIsYearMode.value) return { categories: raw.categories, series: raw.series || [] };

    const sumMap   = {};
    const countMap = {};
    raw.categories.forEach((ym, i) => {
        const str  = String(ym ?? '');
        const year = str.slice(0, 4);
        const mon  = parseInt(str.slice(5, 7), 10);
        if (!mon || parseInt(year, 10) < 2000) return;
        const q      = mon <= 3 ? 'Q1 (Jan–Mar)' : mon <= 6 ? 'Q2 (Apr–Jun)' : mon <= 9 ? 'Q3 (Jul–Sep)' : 'Q4 (Oct–Dec)';
        const bucket = `${year} ${q}`;
        if (!sumMap[bucket]) { sumMap[bucket] = {}; countMap[bucket] = 0; }
        countMap[bucket]++;
        (raw.series || []).forEach(s => {
            sumMap[bucket][s.name] = (sumMap[bucket][s.name] ?? 0) + (Number(s.data?.[i]) || 0);
        });
    });

    const buckets = Object.keys(sumMap).sort();
    return {
        categories: buckets,
        series: (raw.series || []).map(s => ({
            name: s.name,
            data: buckets.map(b => {
                const cnt = countMap[b] || 1;
                return Math.round(((sumMap[b]?.[s.name] ?? 0) / cnt) * 100) / 100;
            }),
        })),
    };
});

// Chart options

const monthlyOpts = computed(() => {
    const cats   = yearlyTrendData.value.categories || [];
    const isYear = trendIsYearMode.value;
    return {
        ...baseChart(),
        chart: { ...baseChart().chart, id: 'pv-rcv-monthly-area', type: 'area', height: 320 },
        stroke: { curve: 'smooth', width: 2 },
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05 } },
        colors: ['#10b981'],
        xaxis: {
            categories: cats,
            tickAmount: Math.min(isYear ? cats.length : 12, cats.length),
            labels: {
                rotate: isYear ? -30 : -45,
                style: { fontSize: isYear ? '12px' : '11px' },
            },
        },
        yaxis: { labels: { formatter: (v) => fmtK(v) } },
        tooltip: { theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' KG' } },
    };
});

const yarnCountOpts = computed(() => ({
    ...baseChart(),
    chart: { ...baseChart().chart, id: 'pv-rcv-yarn-count', type: 'bar', height: 320 },
    plotOptions: { bar: { borderRadius: 4, columnWidth: '60%' } },
    colors: ['#10b981'],
    xaxis: { categories: charts.value.yarn_count_wise?.categories || [], labels: { rotate: -40, style: { fontSize: '11px' } } },
    yaxis: { labels: { formatter: (v) => fmtK(v) } },
    tooltip: { theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' KG' } },
}));

const yarnCountTimeOpts = computed(() => {
    const cats = yarnChartDisplay.value.categories;
    return {
        ...baseChart(),
        chart: { ...baseChart().chart, id: 'pv-rcv-yarn-time', type: 'line', height: 380 },
        stroke: { curve: 'smooth', width: 2 },
        markers: { size: 4, hover: { size: 6 } },
        colors: COLORS,
        dataLabels: {
            enabled: true,
            formatter: (v) => v > 0 ? fmtK(v) : '',
            style: { fontSize: '10px', fontWeight: 600 },
            background: { enabled: true, borderRadius: 3, opacity: 0.85, borderWidth: 0 },
        },
        xaxis: {
            categories: cats,
            tickAmount: Math.min(18, cats.length),
            labels: { rotate: -45, style: { fontSize: '11px' } },
        },
        yaxis: {
            labels: { formatter: (v) => fmtK(v) },
            ...(yarnTimeAvgMode.value ? { title: { text: 'Avg KG / month', style: { fontSize: '11px' } } } : {}),
        },
        tooltip: { shared: true, intersect: false, theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' KG' } },
        legend: { position: 'top', horizontalAlign: 'left', fontSize: '12px', labels: { colors: chartForeColor() } },
        grid: { borderColor: chartGridColor(), strokeDashArray: 3 },
    };
});

const merchantTeamChartHeight = computed(() => {
    const n = merchantYarnBreakdownSorted.value.categories?.length ?? 0;
    return Math.min(720, Math.max(420, (n * 34) + 220));
});

const merchantYarnBreakdownSorted = computed(() => {
    const raw = charts.value.merchant_yarn_breakdown;
    if (!raw?.categories?.length) return { categories: [], series: [] };
    const indices = raw.categories
        .map((_, i) => i)
        .sort((a, b) => String(raw.categories[a]).localeCompare(String(raw.categories[b])));
    return {
        categories: indices.map(i => resolveTeamName(raw.categories[i])),
        series: (raw.series || []).map(s => ({
            ...s,
            data: indices.map(i => s.data?.[i] ?? 0),
        })),
    };
});

const merchantTeamQtyOpts = computed(() => ({
    chart: {
        id: 'pv-rcv-merchant-qty',
        type: 'bar',
        height: merchantTeamChartHeight.value,
        stacked: true,
        fontFamily: 'inherit',
        animations: { enabled: true, speed: 600 },
        toolbar: { show: false },
        foreColor: chartForeColor(),
    },
    plotOptions: {
        bar: {
            columnWidth: '55%',
            borderRadius: 6,
            borderRadiusApplication: 'end',
            borderRadiusWhenStacked: 'last',
            dataLabels: {
                total: {
                    enabled: true,
                    style: { fontSize: '11px', fontWeight: 700 },
                    formatter: (v) => fmtK(v),
                },
            },
        },
    },
    dataLabels: { enabled: false },
    colors: COLORS,
    stroke: { width: 1, colors: ['#fff'] },
    xaxis: {
        categories: merchantYarnBreakdownSorted.value.categories,
        labels: { rotate: -35, style: { fontSize: '11px' } },
        axisBorder: { show: false },
        axisTicks: { show: false },
    },
    yaxis: {
        labels: { formatter: (v) => fmtK(v), style: { fontSize: '11px' } },
        title: { text: 'Received KG', style: { fontSize: '11px', color: chartAxisTitleColor() } },
    },
    legend: { position: 'right', offsetY: 40, fontSize: '11px', markers: { width: 9, height: 9, radius: 50 } },
    tooltip: { shared: true, intersect: false, theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' KG' } },
    fill: { opacity: 1 },
    grid: { borderColor: chartGridColor(), strokeDashArray: 4 },
}));

const fiberOpts = computed(() => ({
    chart: { id: 'pv-rcv-fiber-donut', type: 'donut', height: 300, toolbar: { show: false }, fontFamily: 'inherit', animations: { speed: 600 }, foreColor: chartForeColor() },
    labels: charts.value.fiber_distribution?.labels || [],
    colors: COLORS,
    legend: { position: 'bottom', fontSize: '11px' },
    plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: 'Total', formatter: (w) => fmtMT(w.globals.seriesTotals.reduce((a, b) => a + b, 0)) } } } } },
    tooltip: { theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' KG' } },
}));

const bookingTypeOpts = computed(() => ({
    chart: { id: 'pv-rcv-booking-donut', type: 'donut', height: 300, toolbar: { show: false }, fontFamily: 'inherit', animations: { speed: 600 }, foreColor: chartForeColor() },
    labels: charts.value.booking_type_split?.labels || [],
    colors: ['#10b981', '#f59e0b', '#6366f1', '#ef4444'],
    legend: { position: 'bottom', fontSize: '12px' },
    plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: 'Total', formatter: (w) => fmtMT(w.globals.seriesTotals.reduce((a, b) => a + b, 0)) } } } } },
    tooltip: { theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' KG' } },
}));

const yarnCountHorizontalOpts = computed(() => ({
    chart: {
        id: 'pv-rcv-yarn-type',
        type: 'bar',
        height: 520,
        stacked: true,
        toolbar: { show: false },
        fontFamily: 'inherit',
        animations: { enabled: true, speed: 600 },
        foreColor: chartForeColor(),
    },
    plotOptions: {
        bar: {
            horizontal: true,
            barHeight: '60%',
            dataLabels: {
                total: {
                    enabled: true,
                    offsetX: 4,
                    style: { fontSize: '11px', fontWeight: 700 },
                    formatter: (v) => fmtK(v),
                },
            },
        },
    },
    stroke: { width: 1, colors: ['#fff'] },
    dataLabels: { enabled: false },
    colors: ['#10b981', '#f59e0b', '#6366f1', '#ef4444', '#8b5cf6', '#06b6d4'],
    xaxis: {
        categories: charts.value.yarn_count_type_breakdown?.categories || [],
        labels: { formatter: (v) => fmtK(v), style: { fontSize: '11px' } },
    },
    yaxis: { labels: { style: { fontSize: '11px' } } },
    tooltip: { theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' KG' } },
    fill: { opacity: 1 },
    legend: { position: 'top', horizontalAlign: 'left', offsetX: 40, fontSize: '12px' },
    grid: { borderColor: chartGridColor(), strokeDashArray: 3 },
}));

// Has-data flags
const hasTrendData    = computed(() => (yearlyTrendData.value.categories?.length ?? 0) > 0);
const hasYarnData     = computed(() => (charts.value.yarn_count_wise?.categories?.length ?? 0) > 0);
const hasYarnTimeData = computed(() => yarnChartDisplay.value.categories.length > 0);
const hasMerchantData = computed(() => (merchantYarnBreakdownSorted.value.categories?.length ?? 0) > 0);
const hasFiberData    = computed(() => (charts.value.fiber_distribution?.series?.length ?? 0) > 0);
const hasBookingData  = computed(() => (charts.value.booking_type_split?.series?.length ?? 0) > 0);
</script>

<template>
    <Head title="PERIODIC VIEW — RCV DATE" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2 leading-tight">
                        <Calendar class="text-emerald-600 dark:text-emerald-400 shrink-0" :size="22" />
                        Procurement Followup — Periodic View (Rcv Date)
                    </h2>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-700">
                            {{ periodLabel }}
                        </span>
                        <span class="ml-2 text-gray-400 dark:text-gray-500">Grouped by received_date · metric: received_qty</span>
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        v-if="canViewPeriodicView"
                        :href="route('procurement-followup.periodic-view')"
                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-gray-700"
                    >
                        <BarChart3 :size="15" /> Periodic View (PI Date)
                    </Link>
                    <Link
                        v-if="canViewDataTable"
                        :href="route('procurement-followup.index')"
                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-gray-700"
                    >
                        <ClipboardList :size="15" /> Data Table
                    </Link>
                    <button
                        type="button"
                        :disabled="loading"
                        class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-60"
                        @click="fetchData"
                    >
                        <RefreshCw :size="15" :class="{ 'animate-spin': loading }" />
                        {{ loading ? 'Loading…' : 'Refresh' }}
                    </button>
                </div>
            </div>
        </template>

        <div class="py-6 sm:py-8">
            <div class="mx-auto max-w-7xl sm:px-4 xl:max-w-[1600px] space-y-5">

                <!-- ── PERIOD FILTER ── -->
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 overflow-hidden">
                    <div class="bg-gradient-to-r from-emerald-50 via-white to-white dark:from-emerald-900/20 dark:via-gray-800 dark:to-gray-800 px-5 py-4 flex items-center gap-3">
                        <div class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-600 text-white shadow-sm flex-shrink-0">
                            <Filter :size="16" />
                        </div>
                        <span class="text-base font-bold text-gray-900 dark:text-gray-100 tracking-tight">Period</span>
                        <span class="text-xs text-gray-400 dark:text-gray-500 ml-1">— filter by received_date</span>
                        <span v-if="loading" class="ml-1">
                            <RefreshCw :size="13" class="animate-spin text-emerald-400" />
                        </span>
                    </div>

                    <div class="border-t border-gray-100 dark:border-gray-700/50 px-5 py-5">
                        <div class="flex flex-wrap items-center gap-4">

                            <!-- Year dropdown -->
                            <div
                                class="transition-opacity duration-200"
                                :class="filterState.period === 'custom' ? 'opacity-40 pointer-events-none' : ''"
                            >
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wider">Year (Rcv Date)</label>
                                <select
                                    v-if="availableFiscalYears.length"
                                    :value="filterState.fiscal_year"
                                    @change="selectFiscalYear($event.target.value)"
                                    class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-sm text-gray-700 dark:text-gray-100 px-3 py-2 shadow-sm focus:border-emerald-500 focus:ring-emerald-500"
                                >
                                    <option value="all">All Years</option>
                                    <option v-for="fy in availableFiscalYears" :key="fy" :value="String(fy)">{{ fy }}</option>
                                </select>
                                <p v-else class="text-xs text-gray-400 dark:text-gray-500 italic">No received date data</p>
                            </div>

                            <!-- OR separator -->
                            <div class="flex items-center gap-2 self-end pb-2">
                                <div class="h-px w-6 bg-gray-300 dark:bg-gray-600" />
                                <span class="text-xs text-gray-400 font-medium">or</span>
                                <div class="h-px w-6 bg-gray-300 dark:bg-gray-600" />
                            </div>

                            <!-- Custom Range -->
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wider">Custom Range</label>
                                <div
                                    v-if="filterState.period !== 'custom'"
                                    class="cursor-pointer"
                                >
                                    <button type="button" :class="[periodBase, periodPillOff]" @click="selectPeriod('custom')">
                                        Select Date Range
                                    </button>
                                </div>
                                <div v-else class="flex flex-wrap items-center gap-2">
                                    <input
                                        type="date"
                                        v-model="filterState.date_from"
                                        class="rounded-lg border border-gray-300 text-sm px-3 py-2 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                    />
                                    <span class="text-sm text-gray-400 font-medium">to</span>
                                    <input
                                        type="date"
                                        v-model="filterState.date_to"
                                        :min="filterState.date_from"
                                        class="rounded-lg border border-gray-300 text-sm px-3 py-2 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                    />
                                    <button
                                        v-if="filterState.date_from && filterState.date_to"
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-500"
                                        @click="fetchData"
                                    >
                                        <RefreshCw :size="13" :class="{ 'animate-spin': loading }" /> Apply
                                    </button>
                                    <button
                                        type="button"
                                        class="text-xs text-gray-400 hover:text-red-500 transition-colors"
                                        @click="selectPeriod('fiscal_year')"
                                    >Cancel</button>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <div class="space-y-5">

                <!-- ── ERROR ── -->
                <div
                    v-if="errorMsg"
                    class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800/40 dark:bg-red-900/20 dark:text-red-200 flex items-center gap-2"
                >
                    <AlertCircle :size="16" class="shrink-0" />
                    {{ errorMsg }}
                </div>

                <!-- ── KPI CARDS ── -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-2 xl:grid-cols-2 gap-3">

                    <div class="relative overflow-hidden rounded-2xl border border-emerald-200 bg-white shadow-sm dark:border-emerald-900/50 dark:bg-gray-800">
                        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-emerald-500 to-emerald-700" />
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold text-gray-500 dark:text-gray-400">Total Received KG</div>
                                    <div class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100 tabular-nums">
                                        {{ fmtMT(metrics.total_received_kg) }}
                                    </div>
                                    <div class="mt-1 text-xs text-gray-400 dark:text-gray-500 truncate">grouped by received_date</div>
                                    <div class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400 tabular-nums">
                                        {{ fmtNum(metrics.total_received_kg) }} KG
                                    </div>
                                </div>
                                <div class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300 flex-shrink-0">
                                    <CheckCircle2 :size="18" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="relative overflow-hidden rounded-2xl border border-indigo-200 bg-white shadow-sm dark:border-indigo-900/50 dark:bg-gray-800">
                        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-indigo-500 to-indigo-700" />
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold text-gray-500 dark:text-gray-400">Total Receipts</div>
                                    <div class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100 tabular-nums">
                                        {{ fmtNum(metrics.total_receipts) }}
                                    </div>
                                    <div class="mt-1 text-xs text-gray-400 dark:text-gray-500 truncate">records with received_date</div>
                                </div>
                                <div class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300 flex-shrink-0">
                                    <ClipboardList :size="18" />
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- ── MONTHLY RECEIVED TREND ── -->
                <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                    <div class="mb-1">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ trendIsYearMode ? 'Yearly' : 'Monthly' }} Received KG Trend</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">received_qty grouped by received_date</p>
                    </div>
                    <div v-if="loading" class="flex items-center justify-center h-64 text-gray-400">
                        <RefreshCw :size="24" class="animate-spin" />
                    </div>
                    <div v-else-if="!hasTrendData" class="flex items-center justify-center h-64 text-gray-400 text-sm">No data for selected period</div>
                    <apexchart
                        v-else
                        type="area"
                        width="100%"
                        height="320"
                        :options="monthlyOpts"
                        :series="yearlyTrendData.series"
                        :key="'rcv-trend-' + filterState.period + filterState.fiscal_year + filterState.date_from + filterState.date_to"
                    />
                </div>

                <!-- ── YARN COUNT vs TIME ── -->
                <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                    <div class="flex items-start justify-between gap-3 mb-1">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Yarn Count vs Time (Received KG)</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                <template v-if="yarnTimeAvgMode">Month-wise average received KG per yarn count (top 10)</template>
                                <template v-else>Quarterly received KG per yarn count (top 10) · Q1–Q4 per year</template>
                            </p>
                        </div>
                        <div class="tab-pill-group shrink-0">
                            <button
                                type="button"
                                @click="yarnTimeAvgMode = !yarnTimeAvgMode"
                                class="tab-pill focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-1"
                                :class="yarnTimeAvgMode ? 'tab-pill-active' : 'tab-pill-visible-idle'"
                                :aria-pressed="yarnTimeAvgMode"
                            >
                                Average
                                <template v-if="yarnTimeAvgMode && yarnTimeAvgYearRange">
                                    <span class="ml-1 opacity-75 font-normal">({{ yarnTimeAvgYearRange }})</span>
                                </template>
                            </button>
                        </div>
                    </div>
                    <div v-if="yarnTimeAvgMode" class="flex flex-wrap gap-1 mt-2 mb-3">
                        <button
                            v-for="m in ALL_MONTHS"
                            :key="m"
                            type="button"
                            @click="toggleMonth(m)"
                            class="px-2 py-0.5 rounded text-xs font-semibold border transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400"
                            :class="yarnTimeSelectedMonths.includes(m)
                                ? 'bg-emerald-600 border-emerald-600 text-white'
                                : 'bg-white border-gray-300 text-gray-500 hover:border-emerald-400 hover:text-emerald-600 dark:bg-gray-800 dark:border-gray-600 dark:text-gray-300 dark:hover:border-emerald-500 dark:hover:text-emerald-400'"
                        >{{ MONTH_SHORT[m - 1] }}</button>
                        <button
                            v-if="yarnTimeSelectedMonths.length < 12"
                            type="button"
                            @click="yarnTimeSelectedMonths = [...ALL_MONTHS]"
                            class="px-2 py-0.5 rounded text-xs font-semibold border border-dashed border-gray-300 text-gray-400 hover:border-emerald-400 hover:text-emerald-500 transition-colors ml-1"
                        >All</button>
                    </div>
                    <div v-if="loading" class="flex items-center justify-center h-72 text-gray-400">
                        <RefreshCw :size="24" class="animate-spin" />
                    </div>
                    <div v-else-if="!hasYarnTimeData" class="flex items-center justify-center h-72 text-gray-400 text-sm">No data for selected period</div>
                    <apexchart
                        v-else
                        type="line"
                        width="100%"
                        height="380"
                        :options="yarnCountTimeOpts"
                        :series="yarnChartDisplay.series"
                        :key="'rcv-yarn-time-' + yarnTimeAvgMode + '-' + (yarnChartDisplay.categories[0] ?? '') + '-' + yarnChartDisplay.categories.length"
                    />
                </div>

                <!-- ── YARN COUNT BAR ── -->
                <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-0.5">Received KG by Yarn Count</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Top 20 yarn counts by received_qty</p>
                    <div v-if="loading" class="flex items-center justify-center h-56 text-gray-400"><RefreshCw :size="22" class="animate-spin" /></div>
                    <div v-else-if="!hasYarnData" class="flex items-center justify-center h-56 text-gray-400 text-sm">No data</div>
                    <apexchart
                        v-else
                        type="bar"
                        width="100%"
                        height="320"
                        :options="yarnCountOpts"
                        :series="charts.yarn_count_wise?.series || []"
                        :key="'rcv-yarn-' + filterState.period + filterState.fiscal_year"
                    />
                </div>

                <!-- ── MERCHANT TEAM ── -->
                <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-0.5">Merchant Team — Received KG by Yarn Count</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Total received_qty per merchandising team, stacked by yarn count</p>
                    <div v-if="loading" class="flex items-center justify-center h-56 text-gray-400"><RefreshCw :size="22" class="animate-spin" /></div>
                    <div v-else-if="!hasMerchantData" class="flex items-center justify-center h-56 text-gray-400 text-sm">No data</div>
                    <apexchart
                        v-else
                        type="bar"
                        width="100%"
                        :height="merchantTeamChartHeight"
                        :options="merchantTeamQtyOpts"
                        :series="merchantYarnBreakdownSorted.series"
                        :key="'rcv-team-' + filterState.period + filterState.fiscal_year"
                    />
                </div>

                <!-- ── FIBER + BOOKING TYPE + YARN COUNT HORIZONTAL ── -->
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">

                    <div class="space-y-4">
                        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3">Fiber Type Distribution (Received KG)</h3>
                            <div v-if="loading" class="flex items-center justify-center h-56 text-gray-400"><RefreshCw :size="22" class="animate-spin" /></div>
                            <div v-else-if="!hasFiberData" class="flex items-center justify-center h-56 text-gray-400 text-sm">No data</div>
                            <apexchart
                                v-else
                                type="donut"
                                height="300"
                                :options="fiberOpts"
                                :series="charts.fiber_distribution?.series || []"
                                :key="'rcv-fiber-' + filterState.period + filterState.fiscal_year"
                            />
                        </div>

                        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3">Booking Type Split — CF vs CC (Received KG)</h3>
                            <div v-if="loading" class="flex items-center justify-center h-56 text-gray-400"><RefreshCw :size="22" class="animate-spin" /></div>
                            <div v-else-if="!hasBookingData" class="flex items-center justify-center h-56 text-gray-400 text-sm">No data</div>
                            <apexchart
                                v-else
                                type="donut"
                                height="300"
                                :options="bookingTypeOpts"
                                :series="charts.booking_type_split?.series || []"
                                :key="'rcv-btype-' + filterState.period + filterState.fiscal_year"
                            />
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-0.5">Yarn Count vs Booking Type (Received KG)</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">received_qty per yarn count — stacked by booking type (CF / CC)</p>
                        <div v-if="loading" class="flex items-center justify-center h-56 text-gray-400"><RefreshCw :size="22" class="animate-spin" /></div>
                        <div v-else-if="!(charts.yarn_count_type_breakdown?.series?.length)" class="flex items-center justify-center h-56 text-gray-400 text-sm">No data</div>
                        <apexchart
                            v-else
                            type="bar"
                            width="100%"
                            height="520"
                            :options="yarnCountHorizontalOpts"
                            :series="charts.yarn_count_type_breakdown?.series || []"
                            :key="'rcv-yarn-type-' + filterState.period + filterState.fiscal_year"
                        />
                    </div>

                </div>

                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.tab-pill-group {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 4px;
    border-radius: 12px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border: 1px solid #e2e8f0;
    box-shadow:
        0 1px 3px rgba(0,0,0,0.06),
        inset 0 1px 0 rgba(255,255,255,0.8);
}

:global(.dark .tab-pill-group) {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    border-color: #334155;
    box-shadow:
        0 1px 3px rgba(0,0,0,0.3),
        inset 0 1px 0 rgba(255,255,255,0.04);
}

.tab-pill {
    position: relative;
    padding: 5px 14px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.02em;
    border: 1px solid transparent;
    cursor: pointer;
    transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
    white-space: nowrap;
    overflow: hidden;
}

.tab-pill-idle {
    color: #64748b;
    background: transparent;
    border-color: transparent;
}

.tab-pill-idle:hover {
    color: #334155;
    background: rgba(255,255,255,0.9);
    border-color: #e2e8f0;
    box-shadow: 0 1px 4px rgba(0,0,0,0.07);
}

:global(.dark .tab-pill-idle) {
    color: #94a3b8;
}

:global(.dark .tab-pill-idle:hover) {
    color: #e2e8f0;
    background: rgba(255,255,255,0.06);
    border-color: #475569;
}

.tab-pill-visible-idle {
    color: #059669;
    background: rgba(16, 185, 129, 0.07);
    border-color: rgba(16, 185, 129, 0.35);
}

.tab-pill-visible-idle:hover {
    color: #047857;
    background: rgba(16, 185, 129, 0.14);
    border-color: rgba(16, 185, 129, 0.55);
    box-shadow: 0 1px 4px rgba(16, 185, 129, 0.18);
}

:global(.dark .tab-pill-visible-idle) {
    color: #6ee7b7;
    background: rgba(16, 185, 129, 0.12);
    border-color: rgba(16, 185, 129, 0.3);
}

:global(.dark .tab-pill-visible-idle:hover) {
    color: #a7f3d0;
    background: rgba(16, 185, 129, 0.2);
    border-color: rgba(16, 185, 129, 0.5);
}

.tab-pill-active {
    color: #fff;
    background: linear-gradient(135deg, #10b981 0%, #059669 60%, #047857 100%);
    border-color: rgba(16, 185, 129, 0.4);
    box-shadow:
        0 2px 8px rgba(16, 185, 129, 0.4),
        0 1px 2px rgba(16, 185, 129, 0.3),
        inset 0 1px 0 rgba(255,255,255,0.2);
    transform: translateY(-0.5px);
}

.tab-pill-active::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 8px;
    background: linear-gradient(
        105deg,
        transparent 35%,
        rgba(255,255,255,0.18) 50%,
        transparent 65%
    );
    background-size: 200% 100%;
    animation: tab-shimmer 2.8s ease-in-out infinite;
}

@keyframes tab-shimmer {
    0%   { background-position: -200% 0; }
    60%  { background-position:  200% 0; }
    100% { background-position:  200% 0; }
}

:global(.dark .tab-pill-active) {
    box-shadow:
        0 2px 10px rgba(16, 185, 129, 0.5),
        0 1px 3px rgba(16, 185, 129, 0.4),
        inset 0 1px 0 rgba(255,255,255,0.12);
}
</style>

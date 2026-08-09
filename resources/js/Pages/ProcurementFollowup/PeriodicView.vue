<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import {
    BarChart3, Filter, RefreshCw, ClipboardList, TrendingUp,
    Package, CheckCircle2, AlertCircle, X,
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

const canViewAnalysis = computed(
    () =>
        isSuperAdmin.value ||
        permissions.value.includes('view pi wise analysis') ||
        permissions.value.includes('manage procurement followup') ||
        permissions.value.includes('manage bookings'),
);

const canViewPeriodicViewRcvDate = computed(
    () =>
        isSuperAdmin.value ||
        permissions.value.includes('view periodic view rcv date') ||
        permissions.value.includes('manage procurement followup') ||
        permissions.value.includes('manage bookings'),
);

const canViewDataTable = computed(
    () =>
        isSuperAdmin.value ||
        permissions.value.includes('view procurement followup') ||
        permissions.value.includes('manage procurement followup') ||
        permissions.value.includes('view bookings') ||
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

        const res = await axios.get(route('procurement-followup.periodic-view-data'), { params });
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

const periodPillOn  = 'bg-indigo-600 border-indigo-600 text-white hover:bg-indigo-500';
const periodPillOff = 'bg-white border-gray-300 text-gray-800 hover:bg-indigo-50 hover:border-indigo-400 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-700/60 dark:hover:border-indigo-500';
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
    '#6366f1', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#f97316', '#84cc16',
    '#ec4899', '#14b8a6', '#a855f7', '#0ea5e9', '#eab308', '#22c55e', '#f43f5e', '#d946ef',
];

// Each inner array: [normal, light, very-light, dark] — one palette per yarn count
const COUNT_COLOR_PALETTES = [
    ['#6366f1', '#a5b4fc', '#c7d2fe', '#4338ca'], // indigo
    ['#10b981', '#6ee7b7', '#a7f3d0', '#059669'], // emerald
    ['#f59e0b', '#fcd34d', '#fde68a', '#d97706'], // amber
    ['#ef4444', '#fca5a5', '#fecaca', '#dc2626'], // red
    ['#8b5cf6', '#c4b5fd', '#ddd6fe', '#7c3aed'], // violet
    ['#06b6d4', '#67e8f9', '#a5f3fc', '#0891b2'], // cyan
    ['#f97316', '#fdba74', '#fed7aa', '#ea580c'], // orange
    ['#84cc16', '#bef264', '#d9f99d', '#65a30d'], // lime
    ['#ec4899', '#f9a8d4', '#fbcfe8', '#db2777'], // pink
    ['#14b8a6', '#5eead4', '#99f6e4', '#0d9488'], // teal
];

// For "Count × Type" mode: same yarn count → same palette, different type → different shade
function yarnTypeColorMap(series) {
    const yarnCounts = [...new Set(series.map(s => s.name.split(' / ')[0]))];
    const types      = [...new Set(series.map(s => s.name.split(' / ')[1] ?? '').filter(Boolean))];
    return series.map(s => {
        const [yc, type = ''] = s.name.split(' / ');
        const palette = COUNT_COLOR_PALETTES[yarnCounts.indexOf(yc) % COUNT_COLOR_PALETTES.length];
        return palette[types.indexOf(type) % palette.length];
    });
}

const baseChart = (extra = {}) => ({
    chart: { toolbar: { show: false }, fontFamily: 'inherit', animations: { enabled: true, speed: 600 }, foreColor: chartForeColor() },
    dataLabels: { enabled: false },
    tooltip: { shared: true, intersect: false, theme: chartTooltipTheme() },
    legend: { position: 'top', horizontalAlign: 'left', fontSize: '12px', labels: { colors: chartForeColor() } },
    grid: { borderColor: chartGridColor() },
    ...extra,
});

const monthlyBarOpts = computed(() => ({
    ...baseChart(),
    chart: { ...baseChart().chart, id: 'pv-monthly-bar', type: 'bar', height: 300 },
    plotOptions: { bar: { borderRadius: 3, columnWidth: '65%', grouped: true } },
    colors: ['#6366f1', '#10b981'],
    xaxis: {
        categories: charts.value.monthly_trend?.categories || [],
        tickAmount: Math.min(12, (charts.value.monthly_trend?.categories?.length || 0)),
        labels: { rotate: -45, style: { fontSize: '11px' } },
        axisBorder: { show: false },
    },
    yaxis: { labels: { formatter: (v) => fmtK(v) } },
    tooltip: { theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' KG' } },
    fill: { opacity: 0.9 },
    grid: { borderColor: chartGridColor(), strokeDashArray: 3 },
}));

const monthlyOpts = computed(() => {
    const cats   = yearlyTrendData.value.categories || [];
    const isYear = trendIsYearMode.value;
    return {
        ...baseChart(),
        chart: { ...baseChart().chart, id: 'pv-monthly-area', type: 'area', height: 320 },
        stroke: { curve: 'smooth', width: [2, 2] },
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05 } },
        colors: ['#6366f1', '#10b981'],
        xaxis: {
            categories: cats,
            tickAmount: Math.min(isYear ? cats.length : 12, cats.length),
            labels: {
                rotate: isYear ? -30 : -45,
                style: { fontSize: isYear ? '12px' : '11px', fontWeight: isYear ? 600 : 400 },
            },
        },
        yaxis: { labels: { formatter: (v) => fmtK(v) } },
        tooltip: { theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' KG' } },
    };
});

const yarnCountHorizontalOpts = computed(() => ({
    chart: {
        id: 'pv-yarn-type',
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
    colors: ['#6366f1', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#06b6d4'],
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

const yarnCountOpts = computed(() => ({
    ...baseChart(),
    chart: { ...baseChart().chart, id: 'pv-yarn-count', type: 'bar', height: 320 },
    plotOptions: { bar: { borderRadius: 4, columnWidth: '60%', grouped: true } },
    colors: ['#6366f1', '#10b981'],
    xaxis: { categories: charts.value.yarn_count_wise?.categories || [], labels: { rotate: -40, style: { fontSize: '11px' } } },
    yaxis: { labels: { formatter: (v) => fmtK(v) } },
    tooltip: { theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' KG' } },
}));

const merchantTeamQtyOpts = computed(() => ({
    chart: {
        id: 'pv-merchant-qty',
        type: 'bar',
        height: 460,
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
        title: { text: 'Order KG', style: { fontSize: '11px', color: chartAxisTitleColor() } },
    },
    legend: { position: 'right', offsetY: 40, fontSize: '11px', markers: { width: 9, height: 9, radius: 50 } },
    tooltip: { shared: true, intersect: false, theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' KG' } },
    fill: { opacity: 1 },
    grid: { borderColor: chartGridColor(), strokeDashArray: 4 },
}));

const merchantTeamOpts = computed(() => ({
    chart: {
        id: 'pv-merchant-pct',
        type: 'bar',
        height: 520,
        stacked: true,
        stackType: '100%',
        fontFamily: 'inherit',
        animations: { enabled: true, speed: 600 },
        toolbar: { show: false },
        foreColor: chartForeColor(),
    },
    plotOptions: { bar: { columnWidth: '55%' } },
    dataLabels: {
        enabled: false,
    },
    colors: COLORS,
    stroke: { width: 1, colors: ['#fff'] },
    xaxis: {
        categories: merchantYarnBreakdownSorted.value.categories,
        labels: { rotate: -35, style: { fontSize: '11px', fontWeight: 500 } },
        axisBorder: { show: false },
        axisTicks: { show: false },
    },
    yaxis: {
        labels: { formatter: (v) => v + '%', style: { fontSize: '11px' } },
        title: { text: 'Share %', style: { fontSize: '11px', color: chartAxisTitleColor(), fontWeight: 500 } },
    },
    legend: { position: 'right', offsetX: 0, offsetY: 50, fontSize: '11px', markers: { width: 9, height: 9, radius: 50 } },
    tooltip: { shared: true, intersect: false, theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' KG' } },
    fill: { opacity: 1 },
    grid: { borderColor: chartGridColor(), strokeDashArray: 4 },
}));

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

const merchantQtyStackBy = ref('yarn_count');
const selectedYarnCountFilter = ref(null);

watch(merchantQtyStackBy, (v) => { if (v !== 'yarn_count') selectedYarnCountFilter.value = null; });

const merchantQtyBreakdown = computed(() => {
    if (merchantQtyStackBy.value === 'type') return charts.value.merchant_type_order_received_breakdown || { categories: [], series: [] };
    if (merchantQtyStackBy.value === 'fiber') return charts.value.merchant_fiber_order_received_breakdown || { categories: [], series: [] };
    if (merchantQtyStackBy.value === 'yarn_type') return charts.value.merchant_yarn_type_order_received_breakdown || { categories: [], series: [] };
    return charts.value.merchant_yarn_order_received_breakdown || { categories: [], series: [] };
});

const sortByTeam = (raw) => {
    if (!raw?.categories?.length) return raw || { categories: [], series: [] };
    const indices = raw.categories
        .map((_, i) => i)
        .sort((a, b) => String(raw.categories[a]).localeCompare(String(raw.categories[b])));
    return {
        categories: indices.map(i => resolveTeamName(raw.categories[i])),
        series: (raw.series || []).map(s => ({ ...s, data: indices.map(i => s.data?.[i] ?? 0) })),
    };
};

const merchantQtyBreakdownSorted = computed(() => sortByTeam(merchantQtyBreakdown.value));

const merchantYarnTypeBreakdownSorted = computed(() =>
    sortByTeam(charts.value.merchant_yarn_type_order_received_breakdown)
);

const yarnCountFilterOptions = computed(() => {
    const series = charts.value.merchant_yarn_type_order_received_breakdown?.series || [];
    return [...new Set(series.map(s => s.name.split(' / ')[0]))].sort();
});

const merchantQtyStackLabel = computed(() => {
    if (merchantQtyStackBy.value === 'type') return 'Type';
    if (merchantQtyStackBy.value === 'fiber') return 'Fiber';
    if (merchantQtyStackBy.value === 'yarn_type') return 'Count × Type';
    if (selectedYarnCountFilter.value) return `Type — ${selectedYarnCountFilter.value}`;
    return 'Yarn Count';
});

const merchantTeamOrderReceivedChartHeight = computed(() => {
    const n = merchantQtyBreakdownSorted.value.categories?.length ?? 0;
    return Math.min(720, Math.max(520, (n * 34) + 220));
});

const merchantTeamOrderQtySeries = computed(() => {
    if (merchantQtyStackBy.value === 'yarn_count' && selectedYarnCountFilter.value) {
        const prefix = `${selectedYarnCountFilter.value} / `;
        return (merchantYarnTypeBreakdownSorted.value.series || [])
            .filter(x => String(x?.name || '').startsWith(prefix) && String(x?.name || '').endsWith(' Ordered'))
            .map(x => ({ ...x, name: String(x.name).replace(/ Ordered$/, '').slice(prefix.length) }));
    }
    return merchantQtyBreakdownSorted.value.series
        .filter(x => String(x?.name || '').endsWith(' Ordered'))
        .map(x => ({ ...x, name: String(x.name).replace(/ Ordered$/, '') }));
});

const merchantTeamReceivedQtySeries = computed(() => {
    if (merchantQtyStackBy.value === 'yarn_count' && selectedYarnCountFilter.value) {
        const prefix = `${selectedYarnCountFilter.value} / `;
        return (merchantYarnTypeBreakdownSorted.value.series || [])
            .filter(x => String(x?.name || '').startsWith(prefix) && String(x?.name || '').endsWith(' Received'))
            .map(x => ({ ...x, name: String(x.name).replace(/ Received$/, '').slice(prefix.length) }));
    }
    return merchantQtyBreakdownSorted.value.series
        .filter(x => String(x?.name || '').endsWith(' Received'))
        .map(x => ({ ...x, name: String(x.name).replace(/ Received$/, '') }));
});

const merchantTeamOrderQtyOpts = computed(() => {
    const series      = merchantTeamOrderQtySeries.value;
    const seriesCount = series.length;
    const colors      = merchantQtyStackBy.value === 'yarn_type'
        ? yarnTypeColorMap(series)
        : Array.from({ length: seriesCount }).map((_, i) => COLORS[i % COLORS.length]);

    return {
        chart: {
            id: 'pv-merchant-ordered-breakdown',
            type: 'bar',
            height: merchantTeamOrderReceivedChartHeight.value,
            stacked: true,
            fontFamily: 'inherit',
            animations: { enabled: true, speed: 600 },
            toolbar: { show: false },
            foreColor: chartForeColor(),
        },
        plotOptions: {
            bar: {
                horizontal: true,
                barHeight: '52%',
                dataLabels: {
                    total: {
                        enabled: true,
                        offsetX: 0,
                        style: { fontSize: '13px', fontWeight: 900 },
                        formatter: (v) => fmtK(v),
                    },
                },
            },
        },
        stroke: { width: 1, colors: ['#fff'] },
        dataLabels: { enabled: false },
        colors,
        xaxis: {
            categories: merchantQtyBreakdownSorted.value.categories,
            labels: { formatter: (v) => fmtK(v), style: { fontSize: '11px' } },
        },
        yaxis: { labels: { style: { fontSize: '11px' } } },
        tooltip: { shared: true, intersect: false, theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' KG' } },
        legend: {
            position: 'top',
            horizontalAlign: 'left',
            fontSize: '11px',
            clusterGroupedSeriesOrientation: 'horizontal',
            offsetX: 40,
            labels: { colors: chartForeColor() },
        },
        fill: { opacity: 1 },
        grid: { borderColor: chartGridColor(), strokeDashArray: 4 },
    };
});

const merchantTeamReceivedQtyOpts = computed(() => {
    const series      = merchantTeamReceivedQtySeries.value;
    const seriesCount = series.length;
    const colors      = merchantQtyStackBy.value === 'yarn_type'
        ? yarnTypeColorMap(series)
        : Array.from({ length: seriesCount }).map((_, i) => COLORS[i % COLORS.length]);

    return {
        chart: {
            id: 'pv-merchant-received-breakdown',
            type: 'bar',
            height: merchantTeamOrderReceivedChartHeight.value,
            stacked: true,
            fontFamily: 'inherit',
            animations: { enabled: true, speed: 600 },
            toolbar: { show: false },
            foreColor: chartForeColor(),
        },
        plotOptions: {
            bar: {
                horizontal: true,
                barHeight: '52%',
                dataLabels: {
                    total: {
                        enabled: true,
                        offsetX: 0,
                        style: { fontSize: '13px', fontWeight: 900 },
                        formatter: (v) => fmtK(v),
                    },
                },
            },
        },
        stroke: { width: 1, colors: ['#fff'] },
        dataLabels: { enabled: false },
        colors,
        xaxis: {
            categories: merchantQtyBreakdownSorted.value.categories,
            labels: { formatter: (v) => fmtK(v), style: { fontSize: '11px' } },
        },
        yaxis: { labels: { style: { fontSize: '11px' } } },
        tooltip: { shared: true, intersect: false, theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' KG' } },
        legend: {
            position: 'top',
            horizontalAlign: 'left',
            fontSize: '11px',
            clusterGroupedSeriesOrientation: 'horizontal',
            offsetX: 40,
            labels: { colors: chartForeColor() },
        },
        fill: { opacity: 1 },
        grid: { borderColor: chartGridColor(), strokeDashArray: 4 },
    };
});

const fiberOpts = computed(() => ({
    chart: { id: 'pv-fiber-donut', type: 'donut', height: 300, toolbar: { show: false }, fontFamily: 'inherit', animations: { speed: 600 }, foreColor: chartForeColor() },
    labels: charts.value.fiber_distribution?.labels || [],
    colors: COLORS,
    legend: { position: 'bottom', fontSize: '11px' },
    plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: 'Total', formatter: (w) => fmtMT(w.globals.seriesTotals.reduce((a, b) => a + b, 0)) } } } } },
    tooltip: { theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' KG' } },
}));

const bookingTypeOpts = computed(() => ({
    chart: { id: 'pv-booking-donut', type: 'donut', height: 300, toolbar: { show: false }, fontFamily: 'inherit', animations: { speed: 600 }, foreColor: chartForeColor() },
    labels: charts.value.booking_type_split?.labels || [],
    colors: ['#6366f1', '#f59e0b', '#10b981', '#ef4444'],
    legend: { position: 'bottom', fontSize: '12px' },
    plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: 'Total', formatter: (w) => fmtMT(w.globals.seriesTotals.reduce((a, b) => a + b, 0)) } } } } },
    tooltip: { theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' KG' } },
}));

const leadTimeOpts = computed(() => ({
    ...baseChart(),
    chart: { ...baseChart().chart, id: 'pv-leadtime', type: 'bar', height: 300 },
    plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
    colors: ['#f97316'],
    xaxis: { categories: charts.value.lead_time_by_count?.categories || [], labels: { rotate: -40, style: { fontSize: '11px' } } },
    yaxis: { title: { text: 'Days', style: { fontSize: '11px', color: chartAxisTitleColor() } } },
    tooltip: { theme: chartTooltipTheme(), y: { formatter: (v) => v + ' days' } },
}));

const completionRate = computed(() => {
    const ord = Number(metrics.value.total_order_kg ?? 0);
    const rcv = Number(metrics.value.total_received_kg ?? 0);
    if (!ord) return 0;
    return Math.min(100, Math.round((rcv / ord) * 100));
});

const trendIsYearMode = computed(() =>
    filterState.period === 'fiscal_year' && (!filterState.fiscal_year || filterState.fiscal_year === 'all')
);

const yearlyTrendData = computed(() => {
    const raw = charts.value.monthly_trend;
    if (!raw?.categories?.length) return { categories: [], series: [] };
    if (!trendIsYearMode.value) return { categories: raw.categories, series: raw.series || [] };

    // Per-year quarterly aggregation: each year split into Q1–Q4 with monthly avg
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

const hasTrendData    = computed(() => (yearlyTrendData.value.categories?.length ?? 0) > 0);
const hasYarnData     = computed(() => (charts.value.yarn_count_wise?.categories?.length   ?? 0) > 0);
const hasMerchantData = computed(() => (charts.value.merchant_team_wise?.categories?.length ?? 0) > 0);
const hasFiberData    = computed(() => (charts.value.fiber_distribution?.series?.length     ?? 0) > 0);
const hasBookingData  = computed(() => (charts.value.booking_type_split?.series?.length     ?? 0) > 0);
const hasLeadTimeData = computed(() => (charts.value.lead_time_by_count?.categories?.length ?? 0) > 0);
const hasMerchantOrderData = computed(() => (merchantTeamOrderQtySeries.value.length ?? 0) > 0);
const hasMerchantReceivedData = computed(() => (merchantTeamReceivedQtySeries.value.length ?? 0) > 0);
const yarnTimeAvgMode = ref(false);

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

function buildAvgData(raw, selectedMonths = ALL_MONTHS) {
    if (!raw?.categories?.length || !raw?.series?.length) return { categories: [], series: [] };
    const MONTH_NAMES = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
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
        categories: months.map(m => MONTH_NAMES[m - 1]),
        series: raw.series.map(s => ({
            name: s.name,
            data: months.map(m => {
                const d = acc[m]?.[s.name];
                return (!d || d.count === 0) ? 0 : Math.round((d.sum / d.count) * 1000) / 1000;
            }),
        })),
    };
}

const ALL_MONTHS = [1,2,3,4,5,6,7,8,9,10,11,12];
const MONTH_SHORT = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const yarnTimeSelectedMonths = ref([...ALL_MONTHS]);

function toggleMonth(m) {
    const idx = yarnTimeSelectedMonths.value.indexOf(m);
    if (idx === -1) {
        yarnTimeSelectedMonths.value = [...yarnTimeSelectedMonths.value, m].sort((a,b) => a-b);
    } else if (yarnTimeSelectedMonths.value.length > 1) {
        yarnTimeSelectedMonths.value = yarnTimeSelectedMonths.value.filter(x => x !== m);
    }
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

const hasYarnTimeData = computed(() => yarnChartDisplay.value.categories.length > 0);

const yarnCountTimeOpts = computed(() => {
    const cats = yarnChartDisplay.value.categories;
    return {
        ...baseChart(),
        chart: { ...baseChart().chart, id: 'pv-yarn-time', type: 'line', height: 380 },
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

const fiberYarnSelectedFiber = ref(null);

watch(
    () => charts.value.fiber_yarn_count_stacked?.fibers,
    (fibers) => {
        if (!fibers?.length) return;
        // Reset to first fiber if current selection is missing in new data (filter change)
        if (!fiberYarnSelectedFiber.value || !fibers.includes(fiberYarnSelectedFiber.value)) {
            fiberYarnSelectedFiber.value = fibers[0];
        }
    },
    { immediate: true },
);

const fiberYarnCurrentData = computed(() => {
    const raw = charts.value.fiber_yarn_count_stacked;
    if (!raw?.rows?.length) return { categories: [], series: [] };

    const fiber = fiberYarnSelectedFiber.value || raw.fibers?.[0];
    const rows  = raw.rows.filter(r => r.fiber === fiber);

    // Top yarn counts by total KG for this fiber
    const yarnTotals = {};
    rows.forEach(r => { yarnTotals[r.yarn_count] = (yarnTotals[r.yarn_count] ?? 0) + r.kg; });
    const topCounts = Object.entries(yarnTotals)
        .sort(([, a], [, b]) => b - a)
        .slice(0, 20)
        .map(([yc]) => yc);

    // Pivot: type -> yarn_count -> kg
    const pivot = {};
    rows.forEach(r => {
        if (!pivot[r.type]) pivot[r.type] = {};
        pivot[r.type][r.yarn_count] = r.kg;
    });

    const presentTypes = (raw.fiber_types || []).filter(t => rows.some(r => r.type === t));

    return {
        categories: topCounts,
        series: presentTypes.map(t => ({
            name: t,
            data: topCounts.map(yc => Math.round((pivot[t]?.[yc] ?? 0) * 1000) / 1000),
        })),
    };
});

const hasFiberYarnData = computed(() => fiberYarnCurrentData.value.categories.length > 0);

const fiberYarnChartHeight = computed(() => {
    const n = fiberYarnCurrentData.value.categories.length;
    return Math.max(320, n * 34 + 80);
});

// CF = indigo/blue, CC = amber/orange
const BOOKING_TYPE_COLORS = { CF: '#6366f1', CC: '#f59e0b' };

const fiberYarnOpts = computed(() => {
    const types = charts.value.fiber_yarn_count_stacked?.fiber_types || [];
    const colors = types.map(t => BOOKING_TYPE_COLORS[t] ?? COLORS[types.indexOf(t) % COLORS.length]);
    return {
        ...baseChart(),
        chart: { ...baseChart().chart, id: 'pv-fiber-yarn', type: 'bar', stacked: true, height: fiberYarnChartHeight.value },
        plotOptions: {
            bar: {
                horizontal: true,
                barHeight: '60%',
                dataLabels: { total: { enabled: true, formatter: (v) => v > 0 ? fmtK(v) : '', style: { fontSize: '11px', fontWeight: 700, color: chartForeColor() } } },
            },
        },
        colors,
        dataLabels: {
            enabled: true,
            formatter: (v) => v > 0 ? fmtK(v) : '',
            style: { fontSize: '9px', fontWeight: 600 },
        },
        xaxis: {
            categories: fiberYarnCurrentData.value.categories,
            labels: { formatter: (v) => fmtK(v), style: { fontSize: '11px' } },
            title: { text: 'Order KG', style: { fontSize: '11px', color: chartAxisTitleColor() } },
        },
        yaxis: { labels: { style: { fontSize: '12px', fontWeight: 600 } } },
        tooltip: { shared: true, intersect: false, theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' KG' } },
        legend: { position: 'top', horizontalAlign: 'left', fontSize: '13px', fontWeight: 600, labels: { colors: chartForeColor() } },
        grid: { borderColor: chartGridColor(), strokeDashArray: 3, xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
    };
});
</script>

<template>
    <Head title="PERIODIC VIEW" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2 leading-tight">
                        <BarChart3 class="text-indigo-600 dark:text-indigo-400 shrink-0" :size="22" />
                        Procurement Followup — Periodic View
                    </h2>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-700">
                            {{ periodLabel }}
                        </span>
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        v-if="canViewPeriodicViewRcvDate"
                        :href="route('procurement-followup.periodic-view-rcv-date')"
                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-600"
                    >
                        <BarChart3 :size="15" /> Periodic View (Rcv Date)
                    </Link>
                    <Link
                        v-if="canViewAnalysis"
                        :href="route('procurement-followup.pi-wise-analysis')"
                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-600"
                    >
                        <BarChart3 :size="15" /> PI Research & Analysis
                    </Link>
                    <Link
                        v-if="canViewDataTable"
                        :href="route('procurement-followup.index')"
                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-600"
                    >
                        <ClipboardList :size="15" /> Data Table
                    </Link>
                    <button
                        type="button"
                        :disabled="loading"
                        class="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60"
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
                    <div class="bg-gradient-to-r from-indigo-50 via-white to-white dark:from-indigo-900/20 dark:via-gray-800 dark:to-gray-800 px-5 py-4 flex items-center gap-3">
                        <div class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-sm flex-shrink-0">
                            <Filter :size="16" />
                        </div>
                        <span class="text-base font-bold text-gray-900 dark:text-gray-100 tracking-tight">Period</span>
                        <span v-if="loading" class="ml-1">
                            <RefreshCw :size="13" class="animate-spin text-indigo-400" />
                        </span>
                    </div>

                    <div class="border-t border-gray-100 dark:border-gray-700/50 px-5 py-5">
                        <div class="flex flex-wrap items-center gap-4">

                            <!-- Year dropdown (always visible) -->
                            <div
                                class="transition-opacity duration-200"
                                :class="filterState.period === 'custom' ? 'opacity-40 pointer-events-none' : ''"
                            >
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wider">Year</label>
                                <select
                                    v-if="availableFiscalYears.length"
                                    :value="filterState.fiscal_year"
                                    @change="selectFiscalYear($event.target.value)"
                                    class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-sm text-gray-700 dark:text-gray-100 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                >
                                    <option value="all">All Years</option>
                                    <option v-for="fy in availableFiscalYears" :key="fy" :value="String(fy)">FY {{ fy }}</option>
                                </select>
                                <p v-else class="text-xs text-gray-400 dark:text-gray-500 italic">No fiscal year data</p>
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
                                        class="rounded-lg border border-gray-300 text-sm px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                    />
                                    <span class="text-sm text-gray-400 font-medium">to</span>
                                    <input
                                        type="date"
                                        v-model="filterState.date_to"
                                        :min="filterState.date_from"
                                        class="rounded-lg border border-gray-300 text-sm px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                    />
                                    <button
                                        v-if="filterState.date_from && filterState.date_to"
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
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
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div class="relative overflow-hidden rounded-2xl border border-violet-200 bg-white shadow-sm dark:border-violet-900/50 dark:bg-gray-800">
                        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-violet-500 to-violet-700" />
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold text-gray-500 dark:text-gray-400">Order KG</div>
                                    <div class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100 tabular-nums">
                                        {{ fmtMT(metrics.total_order_kg) }}
                                    </div>
                                    <div class="mt-1 text-xs text-gray-400 dark:text-gray-500 truncate">by PI Rcvd Date</div>
                                    <div class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400 tabular-nums">
                                        {{ fmtNum(metrics.total_order_kg) }} KG
                                    </div>
                                </div>
                                <div class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-violet-50 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300 flex-shrink-0">
                                    <Package :size="18" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="relative overflow-hidden rounded-2xl border border-emerald-200 bg-white shadow-sm dark:border-emerald-900/50 dark:bg-gray-800">
                        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-emerald-500 to-emerald-700" />
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold text-gray-500 dark:text-gray-400">Received KG</div>
                                    <div class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100 tabular-nums">
                                        {{ fmtMT(metrics.total_received_kg) }}
                                    </div>
                                    <div class="mt-1 text-xs text-gray-400 dark:text-gray-500 truncate">by Rcvd Date</div>
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

                    <div class="relative overflow-hidden rounded-2xl border border-amber-200 bg-white shadow-sm dark:border-amber-900/50 dark:bg-gray-800">
                        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-amber-500 to-amber-700" />
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold text-gray-500 dark:text-gray-400">Pending KG</div>
                                    <div class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100 tabular-nums">
                                        {{ fmtMT(metrics.total_pending_kg) }}
                                    </div>
                                    <div class="mt-1 text-xs text-gray-400 dark:text-gray-500 truncate">Balance outstanding</div>
                                    <div class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400 tabular-nums">
                                        {{ fmtNum(metrics.total_pending_kg) }} KG
                                    </div>
                                </div>
                                <div class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300 flex-shrink-0">
                                    <AlertCircle :size="18" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="relative overflow-hidden rounded-2xl border border-sky-200 bg-white shadow-sm dark:border-sky-900/50 dark:bg-gray-800">
                        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-sky-500 to-sky-700" />
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold text-gray-500 dark:text-gray-400">Completion</div>
                                    <div class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100 tabular-nums">
                                        {{ completionRate }}%
                                    </div>
                                    <div class="mt-2 flex items-center justify-between text-[11px] font-semibold text-sky-700 dark:text-sky-300">
                                        <span>Received Progress</span>
                                        <span>{{ completionRate }}% done</span>
                                    </div>
                                    <div class="mt-2 h-4 w-full overflow-hidden rounded-full border border-sky-200 bg-sky-50 shadow-inner dark:border-sky-900/60 dark:bg-gray-700">
                                        <div
                                            class="flex h-full items-center justify-end rounded-full bg-gradient-to-r from-sky-500 via-sky-600 to-sky-700 pr-2 text-[10px] font-bold text-white transition-all duration-500"
                                            :style="{ width: Math.max(completionRate, 8) + '%' }"
                                        >
                                            <span v-if="completionRate > 12">{{ completionRate }}%</span>
                                        </div>
                                    </div>
                                    <div class="mt-1 flex items-center justify-between text-[11px] text-gray-500 dark:text-gray-400">
                                        <span>0%</span>
                                        <span>100%</span>
                                    </div>
                                </div>
                                <div class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-sky-50 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300 flex-shrink-0">
                                    <TrendingUp :size="18" />
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- ── MONTHLY TREND ── -->
                <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                    <div class="mb-1">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ trendIsYearMode ? 'Yearly' : 'Monthly' }} Order vs Received Trend</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Order KG grouped by PI Received Date · Received KG grouped by Received Date</p>
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
                        :key="'trend-' + filterState.period + filterState.fiscal_year + filterState.date_from + filterState.date_to"
                    />
                </div>

                <!-- ── YARN COUNT vs TIME ── -->
                <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                    <div class="flex items-start justify-between gap-3 mb-1">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Yarn Count vs Time</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                <template v-if="yarnTimeAvgMode">Month-wise average KG per yarn count (top 10) across selected period</template>
                                <template v-else>Quarterly order KG per yarn count (top 10) · Q1–Q4 per year</template>
                            </p>
                        </div>
                        <div class="tab-pill-group shrink-0">
                            <button
                                type="button"
                                @click="yarnTimeAvgMode = !yarnTimeAvgMode"
                                class="tab-pill focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-1"
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
                    <!-- Month filter pills — only visible in Average mode -->
                    <div v-if="yarnTimeAvgMode" class="flex flex-wrap gap-1 mt-2 mb-3">
                        <button
                            v-for="m in ALL_MONTHS"
                            :key="m"
                            type="button"
                            @click="toggleMonth(m)"
                            class="px-2 py-0.5 rounded text-xs font-semibold border transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400"
                            :class="yarnTimeSelectedMonths.includes(m)
                                ? 'bg-indigo-600 border-indigo-600 text-white'
                                : 'bg-white border-gray-300 text-gray-500 hover:border-indigo-400 hover:text-indigo-600 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-300'"
                        >{{ MONTH_SHORT[m - 1] }}</button>
                        <button
                            v-if="yarnTimeSelectedMonths.length < 12"
                            type="button"
                            @click="yarnTimeSelectedMonths = [...ALL_MONTHS]"
                            class="px-2 py-0.5 rounded text-xs font-semibold border border-dashed border-gray-300 text-gray-400 hover:border-indigo-400 hover:text-indigo-500 transition-colors ml-1"
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
                        :key="'yarn-time-' + yarnTimeAvgMode + '-' + (yarnChartDisplay.categories[0] ?? '') + '-' + yarnChartDisplay.categories.length"
                    />
                </div>

                <!-- ── FIBER × YARN COUNT × TYPE STACKED BAR ── -->
                <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 overflow-hidden">
                    <!-- Title row -->
                    <div class="px-5 pt-4 pb-3 border-b border-gray-100 dark:border-gray-700/50">
                        <div class="flex items-center justify-between gap-3 flex-wrap">
                            <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100 shrink-0">Order Volume by Yarn Count &amp; Fiber Type</h3>
                            <div class="flex items-center gap-3">
                                <!-- CF / CC color legend -->
                                <span class="flex items-center gap-1.5 text-xs font-semibold text-gray-700 dark:text-gray-300">
                                    <span class="inline-block w-3 h-3 rounded-sm shrink-0" style="background:#6366f1"></span>CF
                                </span>
                                <span class="flex items-center gap-1.5 text-xs font-semibold text-gray-700 dark:text-gray-300">
                                    <span class="inline-block w-3 h-3 rounded-sm shrink-0" style="background:#f59e0b"></span>CC
                                </span>
                                <span class="text-xs text-gray-400 dark:text-gray-500 hidden sm:inline truncate max-w-xs text-right">{{ fiberYarnSelectedFiber }}</span>
                            </div>
                        </div>
                        <!-- Scrollable fiber tabs -->
                        <div class="mt-3 overflow-x-auto">
                            <div class="flex flex-nowrap gap-1 pb-0.5 min-w-0">
                                <button
                                    v-for="f in charts.fiber_yarn_count_stacked?.fibers || []"
                                    :key="f"
                                    type="button"
                                    class="tab-pill whitespace-nowrap shrink-0 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-1"
                                    :class="fiberYarnSelectedFiber === f ? 'tab-pill-active' : 'tab-pill-idle'"
                                    :aria-pressed="fiberYarnSelectedFiber === f"
                                    @click="fiberYarnSelectedFiber = f"
                                >{{ f }}</button>
                            </div>
                        </div>
                    </div>
                    <!-- Chart -->
                    <div class="p-5">
                        <div v-if="loading" class="flex items-center justify-center h-72 text-gray-400">
                            <RefreshCw :size="24" class="animate-spin" />
                        </div>
                        <div v-else-if="!hasFiberYarnData" class="flex items-center justify-center h-72 text-gray-400 text-sm">No data for selected period</div>
                        <apexchart
                            v-else
                            type="bar"
                            width="100%"
                            :height="fiberYarnChartHeight"
                            :options="fiberYarnOpts"
                            :series="fiberYarnCurrentData.series"
                            :key="'fiber-yarn-' + fiberYarnSelectedFiber + filterState.period + filterState.fiscal_year + filterState.date_from + filterState.date_to"
                        />
                    </div>
                </div>

                <!-- ── MERCHANT TEAM ── -->
                <div class="space-y-4">

                    <!-- Quantity chart -->
                    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-0.5">Merchant Team — Order KG by Yarn Count</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Total order KG per merchandising team, stacked by yarn count</p>
                        <div v-if="loading" class="flex items-center justify-center h-56 text-gray-400"><RefreshCw :size="22" class="animate-spin" /></div>
                        <div v-else-if="!hasMerchantData" class="flex items-center justify-center h-56 text-gray-400 text-sm">No data</div>
                        <apexchart
                            v-else
                            type="bar"
                            width="100%"
                            height="420"
                            :options="merchantTeamQtyOpts"
                            :series="merchantYarnBreakdownSorted.series"
                            :key="'team-qty-' + filterState.period + filterState.fiscal_year"
                        />
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 overflow-hidden">
                        <div class="flex items-center justify-between gap-3 px-5 py-4 bg-gradient-to-r from-indigo-50 via-white to-white dark:from-indigo-900/20 dark:via-gray-800 dark:to-gray-800 border-b border-gray-100 dark:border-gray-700/50">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100">Merchant Team — quantity wise</h3>
                                    <span class="inline-flex items-center rounded-full bg-indigo-600 px-2.5 py-1 text-[11px] font-extrabold tracking-wide text-white">
                                        ORDERED
                                    </span>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Stacked by {{ merchantQtyStackLabel }} · Total labels enabled</p>
                            </div>
                            <div class="tab-pill-group flex-shrink-0">
                                <button
                                    type="button"
                                    class="tab-pill focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-1"
                                    :class="merchantQtyStackBy === 'yarn_count' ? 'tab-pill-active' : 'tab-pill-idle'"
                                    :aria-pressed="merchantQtyStackBy === 'yarn_count'"
                                    @click="merchantQtyStackBy = 'yarn_count'"
                                >Yarn Count</button>
                                <button
                                    type="button"
                                    class="tab-pill focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-1"
                                    :class="merchantQtyStackBy === 'yarn_type' ? 'tab-pill-active' : 'tab-pill-idle'"
                                    :aria-pressed="merchantQtyStackBy === 'yarn_type'"
                                    @click="merchantQtyStackBy = 'yarn_type'"
                                >Count × Type</button>
                                <button
                                    type="button"
                                    class="tab-pill focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-1"
                                    :class="merchantQtyStackBy === 'type' ? 'tab-pill-active' : 'tab-pill-idle'"
                                    :aria-pressed="merchantQtyStackBy === 'type'"
                                    @click="merchantQtyStackBy = 'type'"
                                >Type</button>
                                <button
                                    type="button"
                                    class="tab-pill focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-1"
                                    :class="merchantQtyStackBy === 'fiber' ? 'tab-pill-active' : 'tab-pill-idle'"
                                    :aria-pressed="merchantQtyStackBy === 'fiber'"
                                    @click="merchantQtyStackBy = 'fiber'"
                                >Fiber</button>
                            </div>
                        </div>
                        <!-- Yarn count filter strip — visible only in Yarn Count tab -->
                        <div
                            v-if="merchantQtyStackBy === 'yarn_count' && yarnCountFilterOptions.length > 0"
                            class="flex flex-wrap items-center gap-1.5 px-5 py-2.5 bg-gray-50 dark:bg-gray-800/60 border-b border-gray-100 dark:border-gray-700/50"
                        >
                            <span class="text-[11px] font-semibold text-gray-400 dark:text-gray-500 shrink-0">Filter:</span>
                            <button
                                type="button"
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold transition-colors"
                                :class="!selectedYarnCountFilter ? 'bg-indigo-600 text-white' : 'bg-white border border-gray-300 text-gray-500 hover:border-indigo-400 hover:text-indigo-600 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-300'"
                                @click="selectedYarnCountFilter = null"
                            >All</button>
                            <button
                                v-for="yc in yarnCountFilterOptions"
                                :key="yc"
                                type="button"
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold transition-colors"
                                :class="selectedYarnCountFilter === yc ? 'bg-indigo-600 text-white' : 'bg-white border border-gray-300 text-gray-500 hover:border-indigo-400 hover:text-indigo-600 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-300'"
                                @click="selectedYarnCountFilter = selectedYarnCountFilter === yc ? null : yc"
                            >{{ yc }}</button>
                        </div>
                        <div class="p-5">
                        <div v-if="loading" class="flex items-center justify-center h-56 text-gray-400"><RefreshCw :size="22" class="animate-spin" /></div>
                        <div v-else-if="!hasMerchantOrderData" class="flex items-center justify-center h-56 text-gray-400 text-sm">No data</div>
                        <apexchart
                            v-else
                            type="bar"
                            width="100%"
                            :height="merchantTeamOrderReceivedChartHeight"
                            :options="merchantTeamOrderQtyOpts"
                            :series="merchantTeamOrderQtySeries"
                            :key="'team-ord-' + merchantQtyStackBy + (selectedYarnCountFilter ?? '') + filterState.period + filterState.fiscal_year"
                        />
                        </div>
                    </div>

                    <div v-if="false" class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 overflow-hidden">
                        <div class="flex items-center justify-between gap-3 px-5 py-4 bg-gradient-to-r from-emerald-50 via-white to-white dark:from-emerald-900/20 dark:via-gray-800 dark:to-gray-800 border-b border-gray-100 dark:border-gray-700/50">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100">Merchant Team — quantity wise</h3>
                                    <span class="inline-flex items-center rounded-full bg-emerald-600 px-2.5 py-1 text-[11px] font-extrabold tracking-wide text-white">
                                        RECEIVED
                                    </span>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Stacked by {{ merchantQtyStackLabel }} · Total labels enabled</p>
                            </div>
                            <div class="tab-pill-group flex-shrink-0">
                                <button
                                    type="button"
                                    class="tab-pill focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-1"
                                    :class="merchantQtyStackBy === 'yarn_count' ? 'tab-pill-active' : 'tab-pill-idle'"
                                    :aria-pressed="merchantQtyStackBy === 'yarn_count'"
                                    @click="merchantQtyStackBy = 'yarn_count'"
                                >Yarn Count</button>
                                <button
                                    type="button"
                                    class="tab-pill focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-1"
                                    :class="merchantQtyStackBy === 'yarn_type' ? 'tab-pill-active' : 'tab-pill-idle'"
                                    :aria-pressed="merchantQtyStackBy === 'yarn_type'"
                                    @click="merchantQtyStackBy = 'yarn_type'"
                                >Count × Type</button>
                                <button
                                    type="button"
                                    class="tab-pill focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-1"
                                    :class="merchantQtyStackBy === 'type' ? 'tab-pill-active' : 'tab-pill-idle'"
                                    :aria-pressed="merchantQtyStackBy === 'type'"
                                    @click="merchantQtyStackBy = 'type'"
                                >Type</button>
                                <button
                                    type="button"
                                    class="tab-pill focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-1"
                                    :class="merchantQtyStackBy === 'fiber' ? 'tab-pill-active' : 'tab-pill-idle'"
                                    :aria-pressed="merchantQtyStackBy === 'fiber'"
                                    @click="merchantQtyStackBy = 'fiber'"
                                >Fiber</button>
                            </div>
                        </div>
                        <!-- Yarn count filter strip — visible only in Yarn Count tab -->
                        <div
                            v-if="merchantQtyStackBy === 'yarn_count' && yarnCountFilterOptions.length > 0"
                            class="flex flex-wrap items-center gap-1.5 px-5 py-2.5 bg-gray-50 dark:bg-gray-800/60 border-b border-gray-100 dark:border-gray-700/50"
                        >
                            <span class="text-[11px] font-semibold text-gray-400 dark:text-gray-500 shrink-0">Filter:</span>
                            <button
                                type="button"
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold transition-colors"
                                :class="!selectedYarnCountFilter ? 'bg-emerald-600 text-white' : 'bg-white border border-gray-300 text-gray-500 hover:border-emerald-400 hover:text-emerald-600 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-300'"
                                @click="selectedYarnCountFilter = null"
                            >All</button>
                            <button
                                v-for="yc in yarnCountFilterOptions"
                                :key="yc"
                                type="button"
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold transition-colors"
                                :class="selectedYarnCountFilter === yc ? 'bg-emerald-600 text-white' : 'bg-white border border-gray-300 text-gray-500 hover:border-emerald-400 hover:text-emerald-600 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-300'"
                                @click="selectedYarnCountFilter = selectedYarnCountFilter === yc ? null : yc"
                            >{{ yc }}</button>
                        </div>
                        <div class="p-5">
                        <div v-if="loading" class="flex items-center justify-center h-56 text-gray-400"><RefreshCw :size="22" class="animate-spin" /></div>
                        <div v-else-if="!hasMerchantReceivedData" class="flex items-center justify-center h-56 text-gray-400 text-sm">No data</div>
                        <apexchart
                            v-else
                            type="bar"
                            width="100%"
                            :height="merchantTeamOrderReceivedChartHeight"
                            :options="merchantTeamReceivedQtyOpts"
                            :series="merchantTeamReceivedQtySeries"
                            :key="'team-rcv-' + merchantQtyStackBy + (selectedYarnCountFilter ?? '') + filterState.period + filterState.fiscal_year"
                        />
                        </div>
                    </div>

                    <!-- Percentage chart -->
                    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-0.5">Merchant Team — Order percentage Shared</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Yarn count share per merchandising team (100% stacked)</p>
                        <div v-if="loading" class="flex items-center justify-center h-56 text-gray-400"><RefreshCw :size="22" class="animate-spin" /></div>
                        <div v-else-if="!hasMerchantData" class="flex items-center justify-center h-56 text-gray-400 text-sm">No data</div>
                        <apexchart
                            v-else
                            type="bar"
                            width="100%"
                            height="420"
                            :options="merchantTeamOpts"
                            :series="merchantYarnBreakdownSorted.series"
                            :key="'team-pct-' + filterState.period + filterState.fiscal_year"
                        />
                    </div>

                </div>

                <!-- ── FIBER + BOOKING TYPE + YARN COUNT HORIZONTAL ── -->
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">

                    <!-- Left: Fiber + Booking Type stacked -->
                    <div class="space-y-4">
                        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3">Fiber Type Distribution (Order KG)</h3>
                            <div v-if="loading" class="flex items-center justify-center h-56 text-gray-400"><RefreshCw :size="22" class="animate-spin" /></div>
                            <div v-else-if="!hasFiberData" class="flex items-center justify-center h-56 text-gray-400 text-sm">No data</div>
                            <apexchart
                                v-else
                                type="donut"
                                height="300"
                                :options="fiberOpts"
                                :series="charts.fiber_distribution?.series || []"
                                :key="'fiber-' + filterState.period + filterState.fiscal_year"
                            />
                        </div>

                        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3">Booking Type Split — CF vs CC (Order KG)</h3>
                            <div v-if="loading" class="flex items-center justify-center h-56 text-gray-400"><RefreshCw :size="22" class="animate-spin" /></div>
                            <div v-else-if="!hasBookingData" class="flex items-center justify-center h-56 text-gray-400 text-sm">No data</div>
                            <apexchart
                                v-else
                                type="donut"
                                height="300"
                                :options="bookingTypeOpts"
                                :series="charts.booking_type_split?.series || []"
                                :key="'btype-' + filterState.period + filterState.fiscal_year"
                            />
                        </div>
                    </div>

                    <!-- Right: Yarn Count × Type stacked horizontal bar -->
                    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-0.5">Yarn Count vs Type</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Order KG per yarn count — stacked by booking type (CF / CC)</p>
                        <div v-if="loading" class="flex items-center justify-center h-56 text-gray-400"><RefreshCw :size="22" class="animate-spin" /></div>
                        <div v-else-if="!(charts.yarn_count_type_breakdown?.series?.length)" class="flex items-center justify-center h-56 text-gray-400 text-sm">No data</div>
                        <apexchart
                            v-else
                            type="bar"
                            width="100%"
                            height="520"
                            :options="yarnCountHorizontalOpts"
                            :series="charts.yarn_count_type_breakdown?.series || []"
                            :key="'yarn-type-' + filterState.period + filterState.fiscal_year"
                        />
                    </div>

                </div>

                <!-- ── LEAD TIME BY YARN COUNT ── -->
                <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-0.5">Lead Time by Yarn Count</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Average days from PI Received Date to Received Date per count</p>
                    <div v-if="loading" class="flex items-center justify-center h-56 text-gray-400"><RefreshCw :size="22" class="animate-spin" /></div>
                    <div v-else-if="!hasLeadTimeData" class="flex items-center justify-center h-56 text-gray-400 text-sm">No data (requires both PI date and Received date)</div>
                    <apexchart
                        v-else
                        type="bar"
                        width="100%"
                        height="300"
                        :options="leadTimeOpts"
                        :series="charts.lead_time_by_count?.series || []"
                        :key="'lead-' + filterState.period + filterState.fiscal_year"
                    />
                </div>

                <!-- ── PENDING AGING ── -->
                <div v-if="metrics.pending_aging" class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-4">Pending Orders Aging (overdue vs ETA)</h3>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <div class="text-center p-4 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700">
                            <div class="text-2xl font-bold text-emerald-700 dark:text-emerald-300">{{ metrics.pending_aging['0_30'] ?? 0 }}</div>
                            <div class="text-xs text-emerald-600 dark:text-emerald-400 mt-1 font-medium">0–30 days overdue</div>
                        </div>
                        <div class="text-center p-4 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700">
                            <div class="text-2xl font-bold text-amber-700 dark:text-amber-300">{{ metrics.pending_aging['31_60'] ?? 0 }}</div>
                            <div class="text-xs text-amber-600 dark:text-amber-400 mt-1 font-medium">31–60 days overdue</div>
                        </div>
                        <div class="text-center p-4 rounded-xl bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-700">
                            <div class="text-2xl font-bold text-orange-700 dark:text-orange-300">{{ metrics.pending_aging['61_90'] ?? 0 }}</div>
                            <div class="text-xs text-orange-600 dark:text-orange-400 mt-1 font-medium">61–90 days overdue</div>
                        </div>
                        <div class="text-center p-4 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700">
                            <div class="text-2xl font-bold text-red-700 dark:text-red-300">{{ metrics.pending_aging['90p'] ?? 0 }}</div>
                            <div class="text-xs text-red-600 dark:text-red-400 mt-1 font-medium">90+ days overdue</div>
                        </div>
                    </div>
                </div>

                    </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
/* ── Lucrative Tab Pill Group ── */
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

/* Idle state */
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

/* Visible idle — for standalone toggle buttons that must be obviously clickable */
.tab-pill-visible-idle {
    color: #4f46e5;
    background: rgba(99, 102, 241, 0.07);
    border-color: rgba(99, 102, 241, 0.35);
}

.tab-pill-visible-idle:hover {
    color: #4338ca;
    background: rgba(99, 102, 241, 0.14);
    border-color: rgba(99, 102, 241, 0.55);
    box-shadow: 0 1px 4px rgba(99, 102, 241, 0.18);
}

:global(.dark .tab-pill-visible-idle) {
    color: #a5b4fc;
    background: rgba(99, 102, 241, 0.12);
    border-color: rgba(99, 102, 241, 0.3);
}

:global(.dark .tab-pill-visible-idle:hover) {
    color: #c7d2fe;
    background: rgba(99, 102, 241, 0.2);
    border-color: rgba(99, 102, 241, 0.5);
}

/* Active state — gradient pill with glow */
.tab-pill-active {
    color: #fff;
    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 60%, #7c3aed 100%);
    border-color: rgba(99, 102, 241, 0.4);
    box-shadow:
        0 2px 8px rgba(99, 102, 241, 0.4),
        0 1px 2px rgba(99, 102, 241, 0.3),
        inset 0 1px 0 rgba(255,255,255,0.2);
    transform: translateY(-0.5px);
}

/* shimmer on active */
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
        0 2px 10px rgba(99, 102, 241, 0.5),
        0 1px 3px rgba(99, 102, 241, 0.4),
        inset 0 1px 0 rgba(255,255,255,0.12);
}

</style>

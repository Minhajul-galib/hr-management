<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import {
    BarChart3, TrendingUp, Package, ClipboardList,
    RefreshCw, AlertCircle, Filter, X, Star, FileDown, Printer,
} from 'lucide-vue-next';
import { useApexChartTheme } from '@/composables/useApexChartTheme';

const { chartForeColor, chartGridColor, chartTooltipTheme, chartAxisTitleColor } = useApexChartTheme();

const props = defineProps({
    fiscalYears:          { type: Array,  default: () => [] },
    yarnCounts:           { type: Array,  default: () => [] },
    fiberTypes:           { type: Array,  default: () => [] },
    merchantTeams:        { type: Array,  default: () => [] },
    merchantTeamNameMap:  { type: Object, default: () => ({}) },
});

const page = usePage();
const permissions = computed(() => page.props.auth?.permissions || []);
const roles = computed(() => page.props.auth?.roles || []);
const isSuperAdmin = computed(() => roles.value.includes('Super System Admin'));

const canViewPeriodicView = computed(
    () =>
        isSuperAdmin.value ||
        permissions.value.includes('view periodic view') ||
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

// code → name lookup (merchantTeamNameMap: { code: name, ... })
const teamLabel = (code) => props.merchantTeamNameMap[code] ?? code;

const filterState = reactive({
    period:        'fiscal_year',
    fiscal_year:   'all',
    date_from:     '',
    date_to:       '',
    booking_type:  '',
    yarn_count:    [],
    fiber:         [],
    merchant_team: [],
});

const loading    = ref(false);
const data       = ref(null);
const errorMsg   = ref(null);
const showAnalysisModal = ref(false);
const pdfLoading = ref(false);
const modalContentRef = ref(null);
const contentBodyRef  = ref(null);
let jsPDFModule = null;
let html2canvasModule = null;

async function loadPdfDeps() {
    if (!jsPDFModule || !html2canvasModule) {
        const [{ default: jsPDF }, { default: html2canvas }] = await Promise.all([
            import('jspdf'),
            import('html2canvas'),
        ]);
        jsPDFModule = jsPDF;
        html2canvasModule = html2canvas;
    }

    return {
        jsPDF: jsPDFModule,
        html2canvas: html2canvasModule,
    };
}

// ── PDF Download — captures the full content body as one canvas, slices into A4 pages ──
async function downloadPDF() {
    const el = contentBodyRef.value;
    if (!el || pdfLoading.value) return;
    pdfLoading.value = true;

    const chartCanvases = el.querySelectorAll('.apexcharts-canvas');
    const savedCanvasOverflow = [];
    const svgEls = el.querySelectorAll('.apexcharts-canvas svg');
    const savedSvgWidths = [];

    try {
        const { jsPDF, html2canvas } = await loadPdfDeps();
        const bgEl = modalContentRef.value || el;
        const backgroundColor = getComputedStyle(bgEl).backgroundColor || '#ffffff';
        const targets = Array.from(el.children)
            .filter((n) => n?.nodeType === 1)
            .filter((n) => getComputedStyle(n).display !== 'none');

        chartCanvases.forEach(c => {
            savedCanvasOverflow.push(c.style.overflow);
            c.style.overflow = 'hidden';
        });

        svgEls.forEach(svg => {
            savedSvgWidths.push(svg.getAttribute('width'));
            svg.setAttribute('width', '100%');
        });

        await new Promise(r => setTimeout(r, 250));

        const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'legal' });
        const pageW = pdf.internal.pageSize.getWidth();
        const pageH = pdf.internal.pageSize.getHeight();
        const margin = 8;
        const usableW = pageW - margin * 2;
        const usableH = pageH - margin * 2;

        let cursorY = margin;
        let pageNum = 0;

        for (const target of targets) {
            const rect = target.getBoundingClientRect();
            const w = Math.ceil(rect.width) || target.offsetWidth || el.offsetWidth || 1200;

            const canvas = await html2canvas(target, {
                scale: 2,
                useCORS: true,
                logging: false,
                backgroundColor,
                width: w,
                windowWidth: w,
                scrollX: 0,
                scrollY: 0,
            });

            const ratio = usableW / canvas.width;
            const imgH = canvas.height * ratio;
            const spaceMm = 20 * ratio;

            const addSlicePages = (srcCanvas) => {
                const pxPerMm = srcCanvas.width / usableW;
                const slicePx = Math.max(1, Math.floor(usableH * pxPerMm));
                let yPx = 0;

                while (yPx < srcCanvas.height) {
                    if (pageNum > 0 || cursorY !== margin) {
                        pdf.addPage();
                        pageNum++;
                    } else {
                        pageNum++;
                    }
                    cursorY = margin;

                    const sliceH = Math.min(slicePx, srcCanvas.height - yPx);
                    const sliceCanvas = document.createElement('canvas');
                    sliceCanvas.width = srcCanvas.width;
                    sliceCanvas.height = sliceH;
                    sliceCanvas.getContext('2d').drawImage(
                        srcCanvas,
                        0, yPx, srcCanvas.width, sliceH,
                        0, 0,  srcCanvas.width, sliceH,
                    );

                    const sliceRatio = usableW / sliceCanvas.width;
                    pdf.addImage(
                        sliceCanvas.toDataURL('image/png'),
                        'PNG',
                        margin,
                        cursorY,
                        usableW,
                        sliceCanvas.height * sliceRatio,
                    );
                    yPx += sliceH;
                }
            };

            if (imgH > usableH) {
                addSlicePages(canvas);
                cursorY = margin;
                continue;
            }

            if (cursorY + imgH > margin + usableH) {
                pdf.addPage();
                pageNum++;
                cursorY = margin;
            } else if (pageNum === 0) {
                pageNum = 1;
            }

            pdf.addImage(
                canvas.toDataURL('image/png'),
                'PNG',
                margin,
                cursorY,
                usableW,
                imgH,
            );

            cursorY += imgH + spaceMm;
        }

        pdf.save('PI-Research-Analysis.pdf');
    } catch (e) {
        console.error('PDF generation failed', e);
    } finally {
        chartCanvases.forEach((c, i) => { c.style.overflow = savedCanvasOverflow[i] ?? ''; });
        svgEls.forEach((svg, i) => {
            const w = savedSvgWidths[i];
            if (w != null) svg.setAttribute('width', w);
            else svg.removeAttribute('width');
        });
        pdfLoading.value = false;
    }
}

// ── Print — prints only the content body div, legal portrait ─────────────────
function printModal() {
    const el = contentBodyRef.value;
    if (!el) return;

    const styleText = [...document.styleSheets].map(sheet => {
        try { return [...sheet.cssRules].map(r => r.cssText).join('\n'); }
        catch { return ''; }
    }).join('\n');

    const printWin = window.open('', '_blank', 'width=1200,height=900');
    printWin.document.write(`<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>PI Research &amp; Analysis</title>
<style>
${styleText}
*, *::before, *::after {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    box-sizing: border-box;
}
html, body {
    margin: 0; padding: 0;
    background: #fff;
    font-family: ui-sans-serif, system-ui, sans-serif;
}
.print-body {
    padding: 14px 18px;
    background: #fff;
}
[data-pdf-section] {
    page-break-inside: avoid;
    break-inside: avoid;
    margin-bottom: 16px;
}
[data-cards-grid] {
    page-break-inside: auto !important;
    break-inside: auto !important;
}
.yarn-count-grid {
    display: grid !important;
    grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
    gap: 14px !important;
}
.yarn-count-card {
    page-break-inside: avoid !important;
    break-inside: avoid !important;
}
.yarn-count-card:nth-child(9n) {
    page-break-after: always !important;
    break-after: page !important;
}
.xl\\:grid-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
.apexcharts-canvas svg { overflow: hidden !important; }
@page { size: 8.5in 14in portrait; margin: 12mm; }
@media print {
    body { padding: 0; }
}
</style>
</head>
<body><div class="print-body">${el.outerHTML}</div></body>
</html>`);
    printWin.document.close();
    setTimeout(() => { printWin.focus(); printWin.print(); }, 800);
}

const COLORS = [
    '#6366f1', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6',
    '#06b6d4', '#f97316', '#84cc16', '#ec4899', '#14b8a6',
    '#a855f7', '#0ea5e9', '#eab308', '#22c55e', '#f43f5e',
];

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
        if (filterState.booking_type)          params.booking_type  = filterState.booking_type;
        if (filterState.yarn_count.length)     params.yarn_count    = filterState.yarn_count;
        if (filterState.fiber?.length)         params.fiber         = filterState.fiber;
        if (filterState.merchant_team?.length) params.merchant_team = filterState.merchant_team;

        const res  = await axios.get(route('procurement-followup.pi-wise-analysis-data'), { params });
        data.value = res.data;
    } catch (e) {
        errorMsg.value = e?.response?.data?.message || e?.message || 'Failed to load data';
    } finally {
        loading.value = false;
    }
};

let fetchTimer = null;
watch(
    () => ({ p: filterState.period, fy: filterState.fiscal_year, df: filterState.date_from, dt: filterState.date_to, bt: filterState.booking_type, yc: [...filterState.yarn_count], fi: [...filterState.fiber], mt: [...filterState.merchant_team] }),
    () => { clearTimeout(fetchTimer); fetchTimer = setTimeout(fetchData, 280); },
    { deep: true },
);
onUnmounted(() => clearTimeout(fetchTimer));
onMounted(fetchData);

// ── Helpers ───────────────────────────────────────────────────────────────

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
const fmtMTValue = (n) => {
    const x = Number(n);
    if (Number.isNaN(x)) return '0';
    return (x / 1000).toLocaleString(undefined, { maximumFractionDigits: 2 });
};
const fmtMT = (n) => fmtMTValue(n) + ' MT';

const fmtMonth = (ym) => {
    if (!ym) return '';
    const [y, m] = ym.split('-');
    const d = new Date(+y, +m - 1, 1);
    return d.toLocaleDateString('en-US', { month: 'short', year: '2-digit' });
};

const pct = (val, total) => {
    if (!total || !val) return 0;
    return Math.round((Number(val) / Number(total)) * 100);
};

// ── Filter actions ────────────────────────────────────────────────────────

const toggleCount = (c) => {
    const idx = filterState.yarn_count.indexOf(c);
    if (idx === -1) filterState.yarn_count.push(c);
    else            filterState.yarn_count.splice(idx, 1);
};

const toggleFiber = (f) => {
    const idx = filterState.fiber.indexOf(f);
    if (idx === -1) filterState.fiber.push(f);
    else            filterState.fiber.splice(idx, 1);
};

const toggleMerchant = (t) => {
    const idx = filterState.merchant_team.indexOf(t);
    if (idx === -1) filterState.merchant_team.push(t);
    else            filterState.merchant_team.splice(idx, 1);
};

// ── Computed ──────────────────────────────────────────────────────────────

const months      = computed(() => data.value?.months       ?? []);
const series      = computed(() => data.value?.series       ?? []);
const totals      = computed(() => data.value?.totals       ?? {});
const piCounts    = computed(() => data.value?.pi_counts    ?? {});
const yarnList    = computed(() => data.value?.yarn_counts  ?? []);
const grandTotal  = computed(() => data.value?.grand_total  ?? 0);
const yarnExtras  = computed(() => data.value?.yarn_extras  ?? {});
const hasData     = computed(() => months.value.length > 0 && series.value.length > 0);
const yarnCounts  = computed(() => props.yarnCounts ?? []);

// Dynamic filter options cascaded from yarn_count selection
const availableFibers        = computed(() => data.value?.available_fibers         ?? props.fiberTypes    ?? []);
const availableBookingTypes  = computed(() => data.value?.available_booking_types  ?? ['CF', 'CC']);
const availableMerchantTeams = computed(() => data.value?.available_merchant_teams ?? props.merchantTeams ?? []);

// Auto-deselect values that are no longer available
watch(availableFibers, (newList) => {
    const valid = filterState.fiber.filter(f => newList.includes(f));
    if (valid.length !== filterState.fiber.length) filterState.fiber = valid;
});
watch(availableBookingTypes, (newList) => {
    if (filterState.booking_type && !newList.includes(filterState.booking_type.toUpperCase())) {
        filterState.booking_type = '';
    }
});
watch(availableMerchantTeams, (newList) => {
    const valid = filterState.merchant_team.filter(t => newList.includes(t));
    if (valid.length !== filterState.merchant_team.length) filterState.merchant_team = valid;
});

const periodLabel = computed(() => ({
    'fiscal_year': filterState.fiscal_year === 'all' ? 'All Years' : (filterState.fiscal_year ? `FY ${filterState.fiscal_year}` : 'Fiscal Year'),
    'custom':      filterState.date_from && filterState.date_to
                       ? `${filterState.date_from} → ${filterState.date_to}`
                       : 'Custom Range',
}[filterState.period] ?? 'Select Period'));

// ── Pill styles ───────────────────────────────────────────────────────────

const pill    = 'inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-xs font-semibold border shadow-sm transition-all duration-150 cursor-pointer select-none whitespace-nowrap active:scale-[0.98]';
const pillOn  = 'bg-indigo-600 border-indigo-600 text-white shadow-indigo-200/60 hover:bg-indigo-500 dark:shadow-indigo-900/40';
const pillOff = 'bg-white border-gray-200 text-gray-700 hover:bg-indigo-50 hover:border-indigo-300 hover:text-indigo-700 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-700/60 dark:hover:border-indigo-500';

const periodPillOn  = 'bg-indigo-600 border-indigo-600 text-white shadow-indigo-200/60 hover:bg-indigo-500 dark:shadow-indigo-900/40';
const periodPillOff = 'bg-white border-gray-300 text-gray-800 hover:bg-indigo-50 hover:border-indigo-400 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-700/60 dark:hover:border-indigo-500';
const periodBase    = 'inline-flex items-center justify-center px-4 py-2 rounded-xl text-sm border font-semibold shadow-sm transition-all duration-150 cursor-pointer select-none active:scale-[0.98]';

const activeFilterCount = computed(() => {
    let c = 0;
    if (filterState.booking_type)         c++;
    if (filterState.yarn_count.length)    c++;
    if (filterState.fiber.length)         c++;
    if (filterState.merchant_team.length) c++;
    return c;
});

const clearFilters = () => {
    filterState.period        = 'fiscal_year';
    filterState.fiscal_year   = 'all';
    filterState.date_from     = '';
    filterState.date_to       = '';
    filterState.booking_type  = '';
    filterState.yarn_count    = [];
    filterState.fiber         = [];
    filterState.merchant_team = [];
};

const selectFiscalYear = (fy) => {
    filterState.period      = 'fiscal_year';
    filterState.fiscal_year = fy;
    filterState.date_from   = '';
    filterState.date_to     = '';
};

const selectPeriod = (p) => {
    if (p === 'fiscal_year') {
        filterState.period = 'fiscal_year';
        if (!filterState.fiscal_year) {
            filterState.fiscal_year = 'all';
        }
        filterState.date_from = '';
        filterState.date_to = '';
        return;
    }
    if (p === 'custom') {
        filterState.period = 'custom';
        filterState.fiscal_year = '';
    }
};

const filterSubtitle = computed(() => {
    const parts = [periodLabel.value];
    if (filterState.booking_type)          parts.push(filterState.booking_type);
    if (filterState.yarn_count.length)     parts.push(filterState.yarn_count.join(', '));
    if (filterState.fiber.length)          parts.push(filterState.fiber.join(', '));
    if (filterState.merchant_team.length)  parts.push(filterState.merchant_team.join(', '));
    return parts;
});

const filterHeaderSummary = computed(() => {
    const parts = [];
    parts.push(periodLabel.value);
    if (filterState.booking_type) parts.push(`Type: ${filterState.booking_type}`);
    if (filterState.yarn_count.length) parts.push(`Count: ${filterState.yarn_count.length}`);
    if (filterState.fiber.length) parts.push(`Fiber: ${filterState.fiber.length}`);
    if (filterState.merchant_team.length) parts.push(`Team: ${filterState.merchant_team.length}`);
    return parts.join(' • ');
});

// ── Chart options ─────────────────────────────────────────────────────────

const baseChart = (extra = {}) => ({
    chart: {
        toolbar: { show: false },
        fontFamily: 'inherit',
        animations: { enabled: true, speed: 500 },
        foreColor: chartForeColor(),
    },
    dataLabels: { enabled: false },
    tooltip:    { shared: true, intersect: false, theme: chartTooltipTheme() },
    legend:     { position: 'top', horizontalAlign: 'left', fontSize: '11px', markers: { width: 10, height: 10, radius: 50 }, labels: { colors: chartForeColor() } },
    grid:       { borderColor: chartGridColor() },
    ...extra,
});

const xCategories = computed(() => months.value.map(fmtMonth));

const stackedBarOpts = computed(() => ({
    ...baseChart(),
    chart: { ...baseChart().chart, type: 'bar', height: 420, stacked: true },
    plotOptions: { bar: { columnWidth: '72%' } },
    colors: COLORS,
    xaxis: {
        categories: xCategories.value,
        labels: { rotate: -45, style: { fontSize: '11px' } },
        axisBorder: { show: false },
    },
    yaxis: {
        labels: { formatter: (v) => fmtK(v) },
        title:  { text: 'Order KG', style: { fontSize: '11px', color: chartAxisTitleColor() } },
    },
    tooltip: { theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' KG' } },
    fill:    { opacity: 0.92 },
    grid:    { borderColor: chartGridColor(), strokeDashArray: 3 },
}));

const lineOpts = computed(() => ({
    ...baseChart(),
    chart: { ...baseChart().chart, type: 'line', height: 380 },
    stroke:  { width: 2.5, curve: 'smooth' },
    markers: { size: 3.5, hover: { size: 6 } },
    colors:  COLORS,
    xaxis: {
        categories: xCategories.value,
        labels: { rotate: -45, style: { fontSize: '11px' } },
        axisBorder: { show: false },
    },
    yaxis: {
        labels: { formatter: (v) => fmtK(v) },
        title:  { text: 'Order KG', style: { fontSize: '11px', color: chartAxisTitleColor() } },
    },
    tooltip: { theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' KG' } },
    grid:    { borderColor: chartGridColor(), strokeDashArray: 3 },
}));

const getColor = (idx) => COLORS[idx % COLORS.length];

// ── Extra modal chart options ─────────────────────────────────────────────

const yarnShareOpts = computed(() => ({
    chart: { type: 'donut', height: 360, fontFamily: 'inherit', animations: { speed: 500 }, foreColor: chartForeColor() },
    labels: yarnList.value,
    colors: COLORS,
    legend: { position: 'right', fontSize: '11px' },
    plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: 'Total', formatter: (w) => fmtMT(w.globals.seriesTotals.reduce((a, b) => a + b, 0)) } } } } },
    tooltip: { theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' KG' } },
    dataLabels: { formatter: (val) => val.toFixed(3) + '%' },
}));

const yarnShareSeries = computed(() => yarnList.value.map(c => Number(totals.value[c] ?? 0)));

const yarnRankOpts = computed(() => ({
    ...baseChart(),
    chart: { ...baseChart().chart, type: 'bar', height: 380 },
    plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '60%',
        dataLabels: { position: 'center' } } },
    colors: COLORS,
    dataLabels: { enabled: true, formatter: (v) => fmtK(v), style: { fontSize: '10px', fontWeight: 600, colors: ['#fff'] } },
    xaxis: { labels: { formatter: (v) => fmtK(v), style: { fontSize: '11px' } } },
    yaxis: { categories: yarnList.value, labels: { style: { fontSize: '11px' } } },
    tooltip: { theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' KG' } },
    grid: { borderColor: chartGridColor(), strokeDashArray: 3 },
}));

const yarnRankSeries = computed(() => [{
    name: 'Order KG',
    data: yarnList.value.map((c, i) => ({ x: c, y: Number(totals.value[c] ?? 0), fillColor: COLORS[i % COLORS.length] })),
}]);

const piCountOpts = computed(() => ({
    ...baseChart(),
    chart: { ...baseChart().chart, type: 'bar', height: 320 },
    plotOptions: { bar: { borderRadius: 4, columnWidth: '60%',
        dataLabels: { position: 'top' } } },
    colors: ['#8b5cf6'],
    dataLabels: { enabled: true, formatter: (v) => fmtNum(v), offsetY: -6, style: { fontSize: '10px', fontWeight: 600, colors: [chartForeColor()] } },
    xaxis: { categories: yarnList.value, labels: { rotate: -40, style: { fontSize: '11px' } }, axisBorder: { show: false } },
    yaxis: { labels: { formatter: (v) => fmtNum(v), style: { fontSize: '11px' } }, title: { text: 'PI Records', style: { fontSize: '11px', color: chartAxisTitleColor() } } },
    tooltip: { theme: chartTooltipTheme(), y: { formatter: (v) => fmtNum(v) + ' PI records' } },
    grid: { borderColor: chartGridColor(), strokeDashArray: 3 },
}));

const piCountSeries = computed(() => [{
    name: 'PI Records',
    data: yarnList.value.map(c => Number(piCounts.value[c] ?? 0)),
}]);

const yarnPct = computed(() => {
    const gt = Number(grandTotal.value ?? 0);
    const out = {};
    for (const c of yarnList.value) {
        const kg = Number(totals.value[c] ?? 0);
        out[c] = gt > 0 ? parseFloat(((kg / gt) * 100).toFixed(3)) : 0;
    }
    return out;
});

const radialSingleOpts = (count, idx) => {
    const color = COLORS[idx % COLORS.length];
    return {
        chart: {
            type: 'radialBar',
            height: 240,
            fontFamily: 'inherit',
            animations: { speed: 600 },
            foreColor: chartForeColor(),
            dropShadow: {
                enabled: true,
                top: 3,
                left: 0,
                blur: 8,
                opacity: 0.18,
                color: color,
            },
        },
        plotOptions: {
            radialBar: {
                startAngle: -90,
                endAngle: 270,
                hollow: {
                    size: '60%',
                    background: 'transparent',
                    image: undefined,
                    dropShadow: {
                        enabled: true,
                        top: 2,
                        left: 0,
                        blur: 6,
                        opacity: 0.25,
                        color: '#000',
                    },
                },
                track: {
                    background: chartGridColor(),
                    strokeWidth: '100%',
                    dropShadow: {
                        enabled: true,
                        top: 2,
                        left: 0,
                        blur: 4,
                        opacity: 0.15,
                        color: '#000',
                    },
                },
                dataLabels: {
                    name: {
                        show: true,
                        offsetY: 20,
                        fontSize: '11px',
                        fontWeight: 700,
                        color: chartAxisTitleColor(),
                    },
                    value: {
                        show: true,
                        offsetY: -10,
                        fontSize: '22px',
                        fontWeight: 800,
                        color: color,
                        formatter: (v) => `${Number(v).toFixed(3)}%`,
                    },
                },
            },
        },
        stroke: {
            lineCap: 'round',
        },
        colors: [color],
        labels: [count],
        tooltip: {
            theme: chartTooltipTheme(),
            y: { formatter: (val) => `${fmtNum(totals.value[count] ?? 0)} KG (${Number(val).toFixed(3)}%)` },
        },
    };
};

const typeTop = (count, topN = 3) => {
    const map = yarnExtras.value?.[count]?.type_kg ?? {};
    return Object.entries(map)
        .map(([k, v]) => [String(k), Number(v)])
        .filter(([, v]) => v > 0)
        .sort((a, b) => b[1] - a[1])
        .slice(0, topN);
};
</script>

<template>
    <Head title="PI Research & Analysis" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2 leading-tight">
                        <TrendingUp class="text-indigo-600 dark:text-indigo-400 shrink-0" :size="22" />
                        PI Research & Analysis
                    </h2>
                    <div class="mt-1 flex items-center gap-1 flex-wrap">
                        <template v-if="filterSubtitle.length">
                            <span
                                v-for="(part, idx) in filterSubtitle"
                                :key="idx"
                                class="flex items-center gap-1"
                            >
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-700">{{ part }}</span>
                                <span v-if="idx < filterSubtitle.length - 1" class="text-gray-400 dark:text-gray-500 text-xs font-bold">›</span>
                            </span>
                        </template>
                        <span v-else class="text-xs text-gray-400 dark:text-gray-500 italic">All Data — no filters applied</span>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        v-if="canViewPeriodicView"
                        :href="route('procurement-followup.periodic-view')"
                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-gray-700"
                    >
                        <BarChart3 :size="15" /> Periodic View
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

                <div class="rounded-md border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 overflow-hidden">
                    <div class="bg-gradient-to-r from-indigo-50 via-white to-white dark:from-indigo-900/20 dark:via-gray-800 dark:to-gray-800">
                        <div
                            class="w-full flex items-center justify-between px-5 py-4 text-sm font-semibold text-gray-800 dark:text-gray-100"
                        >
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-sm flex-shrink-0">
                                    <Filter :size="18" />
                                </div>
                                <div class="min-w-0 flex flex-col sm:flex-row sm:items-center sm:gap-3">
                                    <div class="flex items-center gap-2 min-w-0 flex-shrink-0">
                                        <span class="tracking-tight text-lg font-bold text-gray-900 dark:text-gray-100">FILTERS</span>
                                        <span
                                            v-if="activeFilterCount > 0"
                                            class="inline-flex items-center justify-center rounded-full bg-indigo-600 text-white text-xs font-bold w-5 h-5"
                                        >{{ activeFilterCount }}</span>
                                        <span v-if="loading" class="ml-1">
                                            <RefreshCw :size="13" class="animate-spin text-indigo-400" />
                                        </span>
                                    </div>
                                    <div class="mt-1 sm:mt-0 text-xs font-medium text-gray-500 dark:text-gray-400 truncate">
                                        {{ filterHeaderSummary }}
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 flex-shrink-0">
                                <button
                                    type="button"
                                    class="btn-view-analysis inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-bold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 active:scale-[0.97] dark:focus-visible:ring-offset-gray-800"
                                    @click="showAnalysisModal = true"
                                >
                                    <BarChart3 :size="16" class="btn-icon" />
                                    View Analysis
                                </button>
                                <button
                                    v-if="activeFilterCount > 0"
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-white px-3 py-2 text-xs font-semibold text-red-600 hover:bg-red-50 dark:bg-gray-800 dark:border-red-800/40 dark:text-red-300 dark:hover:bg-red-900/10"
                                    @click.stop="clearFilters"
                                >
                                    <X :size="13" />
                                    Clear
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-gray-100 dark:border-gray-700/50 px-3 py-3">
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

                            <div class="lg:col-span-2 space-y-4">
                                <div class="rounded-md border border-gray-200 bg-gray-50/60 p-3 dark:border-gray-700 dark:bg-gray-900/20">
                                    <div class="flex items-center justify-between gap-3 mb-3">
                                        <div class="min-w-0">
                                            <div class="text-lg font-bold text-gray-800 dark:text-gray-200 uppercase tracking-wider">
                                                Yarn Count
                                            </div>
                                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                <span v-if="filterState.yarn_count.length" class="text-indigo-600 dark:text-indigo-400 font-semibold">{{ filterState.yarn_count.length }} selected</span>
                                                <span v-else>All showing</span>
                                            </div>
                                        </div>
                                        <button
                                            v-if="filterState.yarn_count.length"
                                            type="button"
                                            class="text-xs font-semibold text-gray-500 hover:text-red-500 dark:text-gray-400 dark:hover:text-red-300"
                                            @click="filterState.yarn_count = []"
                                        >
                                            Clear
                                        </button>
                                    </div>

                                    <div v-if="yarnCounts.length" class="flex flex-wrap gap-1.5">
                                        <button
                                            v-for="c in yarnCounts"
                                            :key="c"
                                            type="button"
                                            :class="[pill, filterState.yarn_count.includes(c) ? pillOn : pillOff]"
                                            @click="toggleCount(c)"
                                        >{{ c }}</button>
                                    </div>
                                    <p v-else class="text-xs text-gray-400 dark:text-gray-500 italic">No yarn counts available</p>
                                </div>

                                <div class="rounded-xl border border-gray-200 bg-gray-50/60 p-4 dark:border-gray-700 dark:bg-gray-900/20">
                                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                        <div class="text-lg font-bold text-gray-800 dark:text-gray-200 uppercase tracking-wider">
                                            Booking Type
                                        </div>
                                        <div class="flex flex-wrap gap-1.5">
                                            <button type="button" :class="[pill, !filterState.booking_type ? pillOn : pillOff]" @click="filterState.booking_type = ''">All</button>
                                            <button
                                                v-for="bt in availableBookingTypes"
                                                :key="bt"
                                                type="button"
                                                :class="[pill, filterState.booking_type === bt ? pillOn : pillOff]"
                                                @click="filterState.booking_type = bt"
                                            >{{ bt }}</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="rounded-md border border-gray-200 bg-gray-50/60 p-3 dark:border-gray-700 dark:bg-gray-900/20">
                                    <div class="flex items-center justify-between gap-3 mb-2">
                                        <div class="text-lg font-bold text-gray-800 dark:text-gray-200 uppercase tracking-wider">
                                            Fiber
                                        </div>
                                        <button
                                            v-if="filterState.fiber.length"
                                            type="button"
                                            class="text-xs font-semibold text-gray-500 hover:text-red-500 dark:text-gray-400 dark:hover:text-red-300"
                                            @click="filterState.fiber = []"
                                        >
                                            Clear
                                        </button>
                                    </div>
                                    <div v-if="availableFibers.length" class="flex flex-wrap gap-1.5">
                                        <button
                                            v-for="f in availableFibers"
                                            :key="f"
                                            type="button"
                                            class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-xs font-medium transition-colors duration-150 border"
                                            :class="filterState.fiber.includes(f)
                                                ? 'bg-indigo-600 border-indigo-600 text-white'
                                                : 'bg-white border-gray-200 text-gray-700 hover:bg-indigo-50 hover:border-indigo-300 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-700/60'"
                                            @click="toggleFiber(f)"
                                        >
                                            <span
                                                class="inline-flex h-3.5 w-3.5 flex-shrink-0 items-center justify-center rounded border"
                                                :class="filterState.fiber.includes(f) ? 'bg-white border-white' : 'border-gray-400 dark:border-gray-500'"
                                            >
                                                <svg v-if="filterState.fiber.includes(f)" class="h-2.5 w-2.5 text-indigo-600" viewBox="0 0 10 10" fill="currentColor">
                                                    <path d="M8.5 2.5L4 7.5 1.5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                                </svg>
                                            </span>
                                            <span class="whitespace-nowrap">{{ f }}</span>
                                        </button>
                                    </div>
                                    <p v-else class="text-xs text-gray-400 dark:text-gray-500 italic">
                                        {{ filterState.yarn_count.length ? 'No fibers for selected counts' : 'No fiber options' }}
                                    </p>
                                </div>
                            </div>

                            <div class="space-y-4">
                                <div class="rounded-md border border-gray-200 bg-gray-50/60 p-3 dark:border-gray-700 dark:bg-gray-900/20">
                                    <div class="flex items-center justify-between gap-3 mb-3">
                                        <div class="text-lg font-bold text-gray-800 dark:text-gray-200 uppercase tracking-wider">
                                            Period
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            :class="[periodBase, filterState.period === 'fiscal_year' ? periodPillOn : periodPillOff]"
                                            class="focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-800"
                                            @click="selectPeriod('fiscal_year')"
                                        >
                                            Fiscal Year
                                        </button>
                                        <button
                                            type="button"
                                            :class="[periodBase, filterState.period === 'custom' ? periodPillOn : periodPillOff]"
                                            class="focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-800"
                                            @click="selectPeriod('custom')"
                                        >
                                            Custom Range
                                        </button>
                                    </div>

                                    <div class="mt-3">
                                        <div v-if="filterState.period === 'fiscal_year'">
                                            <select
                                                v-if="props.fiscalYears.length"
                                                :value="filterState.fiscal_year"
                                                @change="selectFiscalYear($event.target.value)"
                                                class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-xs text-gray-700 dark:text-gray-100 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            >
                                                <option value="all">All Years</option>
                                                <option v-for="fy in props.fiscalYears" :key="fy" :value="String(fy)">FY {{ fy }}</option>
                                            </select>
                                            <p v-else class="text-xs text-gray-400 dark:text-gray-500 italic">No fiscal year data</p>
                                        </div>

                                        <div v-else class="space-y-2">
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                                <input
                                                    type="date"
                                                    v-model="filterState.date_from"
                                                    class="w-full rounded-lg border border-gray-300 text-xs px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                                />
                                                <input
                                                    type="date"
                                                    v-model="filterState.date_to"
                                                    :min="filterState.date_from"
                                                    class="w-full rounded-lg border border-gray-300 text-xs px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                                />
                                            </div>
                                            <button
                                                v-if="filterState.date_from && filterState.date_to"
                                                type="button"
                                                class="inline-flex items-center gap-1 rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-500"
                                                @click="fetchData"
                                            >
                                                <RefreshCw :size="12" :class="{ 'animate-spin': loading }" />
                                                Apply
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="rounded-md border border-gray-200 bg-gray-50/60 p-3 dark:border-gray-700 dark:bg-gray-900/20">
                                    <div class="flex items-center justify-between gap-3 mb-3">
                                        <div class="min-w-0">
                                            <div class="text-lg font-bold text-gray-800 dark:text-gray-200 uppercase tracking-wider">
                                                Merchant Team
                                            </div>
                                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                <span v-if="filterState.merchant_team.length" class="text-indigo-600 dark:text-indigo-400 font-semibold">{{ filterState.merchant_team.length }} selected</span>
                                                <span v-else>All showing</span>
                                            </div>
                                        </div>
                                        <button
                                            v-if="filterState.merchant_team.length"
                                            type="button"
                                            class="text-xs font-semibold text-gray-500 hover:text-red-500 dark:text-gray-400 dark:hover:text-red-300"
                                            @click="filterState.merchant_team = []"
                                        >
                                            Clear
                                        </button>
                                    </div>

                                    <div v-if="availableMerchantTeams.length" class="flex flex-wrap gap-1.5">
                                        <button
                                            v-for="t in availableMerchantTeams"
                                            :key="t"
                                            type="button"
                                            :class="[pill, filterState.merchant_team.includes(t) ? pillOn : pillOff]"
                                            @click="toggleMerchant(t)"
                                            :title="t"
                                        >{{ teamLabel(t) }}</button>
                                    </div>
                                    <p v-else class="text-xs text-gray-400 dark:text-gray-500 italic">
                                        {{ filterState.yarn_count.length ? 'No teams for selected counts' : 'No merchant teams' }}
                                    </p>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <div
                    v-if="errorMsg"
                    class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800/40 dark:bg-red-900/20 dark:text-red-200 flex items-center gap-2"
                >
                    <AlertCircle :size="16" class="shrink-0" />
                    {{ errorMsg }}
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div class="relative overflow-hidden rounded-md border border-indigo-200 bg-white shadow-sm dark:border-indigo-900/50 dark:bg-gray-800">
                        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-indigo-500 to-indigo-700" />
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold text-gray-500 dark:text-gray-400">Total Order KG</div>
                                    <div class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100 tabular-nums">
                                        {{ fmtMT(grandTotal) }}
                                    </div>
                                    <div class="mt-1 text-xs text-gray-400 dark:text-gray-500 truncate">
                                        {{ periodLabel }}
                                    </div>
                                </div>
                                <div class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300 flex-shrink-0">
                                    <Package :size="18" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="relative overflow-hidden rounded-md border border-violet-200 bg-white shadow-sm dark:border-violet-900/50 dark:bg-gray-800">
                        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-violet-500 to-violet-700" />
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold text-gray-500 dark:text-gray-400">PI Records</div>
                                    <div class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100 tabular-nums">
                                        {{ fmtNum(data?.total_pi_records) }}
                                    </div>
                                    <div class="mt-1 text-xs text-gray-400 dark:text-gray-500 truncate">Orders in period</div>
                                </div>
                                <div class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-violet-50 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300 flex-shrink-0">
                                    <ClipboardList :size="18" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="relative overflow-hidden rounded-md border border-amber-200 bg-white shadow-sm dark:border-amber-900/50 dark:bg-gray-800">
                        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-amber-500 to-amber-700" />
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold text-gray-500 dark:text-gray-400">Peak Month</div>
                                    <div class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100 tabular-nums">
                                        {{ data?.peak_month ? fmtMonth(data.peak_month) : '-' }}
                                    </div>
                                    <div class="mt-1 text-xs text-gray-400 dark:text-gray-500 truncate">
                                        {{ fmtMT(data?.peak_month_kg ?? 0) }} ordered
                                    </div>
                                </div>
                                <div class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300 flex-shrink-0">
                                    <TrendingUp :size="18" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="relative overflow-hidden rounded-md border border-emerald-200 bg-white shadow-sm dark:border-emerald-900/50 dark:bg-gray-800">
                        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-emerald-500 to-emerald-700" />
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold text-gray-500 dark:text-gray-400">Top Count</div>
                                    <div class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100 truncate">
                                        {{ data?.top_count ?? '-' }}
                                    </div>
                                    <div class="mt-1 text-xs text-gray-400 dark:text-gray-500 truncate">
                                        {{ fmtMT(data?.top_count_kg ?? 0) }} total
                                    </div>
                                </div>
                                <div class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300 flex-shrink-0">
                                    <Star :size="18" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <Modal :show="showAnalysisModal" maxWidth="7xl" @close="showAnalysisModal = false">
            <div ref="modalContentRef" class="bg-white dark:bg-gray-900">
                <div data-pdf-header class="flex items-center justify-between gap-3 border-b border-gray-200 dark:border-gray-700 px-6 py-4">
                    <div class="min-w-0">
                        <div class="text-lg font-semibold text-gray-900 dark:text-gray-100 truncate">
                            PI Research & Analysis
                        </div>
                        <div class="mt-1 flex items-center gap-1 flex-wrap">
                            <template v-if="filterSubtitle.length">
                                <span
                                    v-for="(part, idx) in filterSubtitle"
                                    :key="idx"
                                    class="flex items-center gap-1"
                                >
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-700">{{ part }}</span>
                                    <span v-if="idx < filterSubtitle.length - 1" class="text-gray-400 dark:text-gray-500 text-xs font-bold">›</span>
                                </span>
                            </template>
                            <span v-else class="text-xs text-gray-400 dark:text-gray-500 italic">All Data — no filters applied</span>
                        </div>
                    </div>
                    <div data-pdf-hide class="action-bar">
                        <!-- Left group: Refresh + PDF + Print -->
                        <div class="action-bar-group">
                            <button
                                type="button"
                                :disabled="loading"
                                class="action-btn action-btn--indigo"
                                @click="fetchData"
                            >
                                <RefreshCw :size="14" :class="{ 'animate-spin': loading }" />
                                <span>{{ loading ? 'Loading…' : 'Refresh' }}</span>
                            </button>

                            <button
                                type="button"
                                :disabled="pdfLoading || loading"
                                class="action-btn action-btn--rose"
                                @click="downloadPDF"
                            >
                                <FileDown :size="14" :class="{ 'animate-bounce': pdfLoading }" />
                                <span>{{ pdfLoading ? 'Generating…' : 'PDF' }}</span>
                            </button>

                            <button
                                type="button"
                                :disabled="pdfLoading || loading"
                                class="action-btn action-btn--slate"
                                @click="printModal"
                            >
                                <Printer :size="14" />
                                <span>Print</span>
                            </button>
                        </div>

                        <!-- Divider -->
                        <div class="action-bar-divider" />

                        <!-- Close -->
                        <button
                            type="button"
                            class="action-btn-close"
                            title="Close"
                            @click="showAnalysisModal = false"
                        >
                            <X :size="15" />
                        </button>
                    </div>
                </div>

                <div ref="contentBodyRef" class="px-6 py-5 space-y-5">
                    <div
                        v-if="errorMsg"
                        class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800/40 dark:bg-red-900/20 dark:text-red-200 flex items-center gap-2"
                    >
                        <AlertCircle :size="16" class="shrink-0" />
                        {{ errorMsg }}
                    </div>

                    <div data-pdf-section class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                        <div class="mb-3">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Monthly Order KG — All Yarn Counts (Stacked)</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                How many KG orders were made in a month and what is the contribution of each count?
                            </p>
                        </div>
                        <div v-if="loading" class="flex items-center justify-center h-[420px] text-gray-400 dark:text-gray-500">
                            <RefreshCw :size="26" class="animate-spin" />
                        </div>
                        <div v-else-if="!hasData" class="flex items-center justify-center h-[420px] text-gray-400 dark:text-gray-500 text-sm">
                            No data for selected period
                        </div>
                        <apexchart
                            v-else
                            type="bar"
                            height="420"
                            :options="stackedBarOpts"
                            :series="series"
                            :key="'stacked-' + filterState.period + filterState.fiscal_year + filterState.date_from + filterState.date_to + filterState.booking_type + filterState.yarn_count.join()"
                        />
                    </div>

                    <div data-pdf-section class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                        <div class="mb-3">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Yarn Count Trend — Individual Lines</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                View each count separately — which count is getting more orders at which time
                            </p>
                        </div>
                        <div v-if="loading" class="flex items-center justify-center h-[380px] text-gray-400 dark:text-gray-500">
                            <RefreshCw :size="26" class="animate-spin" />
                        </div>
                        <div v-else-if="!hasData" class="flex items-center justify-center h-[380px] text-gray-400 dark:text-gray-500 text-sm">
                            No data for selected period
                        </div>
                        <apexchart
                            v-else
                            type="line"
                            height="380"
                            :options="lineOpts"
                            :series="series"
                            :key="'line-' + filterState.period + filterState.fiscal_year + filterState.date_from + filterState.date_to + filterState.booking_type + filterState.yarn_count.join()"
                        />
                    </div>

                    <!-- Donut + Horizontal Rank -->
                    <div v-if="hasData && !loading" data-pdf-section class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3">Yarn Count — KG Share (Donut)</h3>
                            <apexchart type="donut" height="360" :options="yarnShareOpts" :series="yarnShareSeries" :key="'donut-' + filterState.period + filterState.fiscal_year + filterState.date_from + filterState.date_to + filterState.yarn_count.join()" />
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-0.5">Yarn Count Ranking — Order KG</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Highest to lowest total order quantity</p>
                            <apexchart type="bar" height="380" :options="yarnRankOpts" :series="yarnRankSeries" :key="'rank-' + filterState.period + filterState.fiscal_year + filterState.date_from + filterState.date_to + filterState.yarn_count.join()" />
                        </div>
                    </div>

                    <!-- PI Records per Count -->
                    <div v-if="hasData && !loading" data-pdf-section class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-0.5">PI Records per Yarn Count</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Number of PI orders placed for each yarn count</p>
                        <apexchart type="bar" height="320" :options="piCountOpts" :series="piCountSeries" :key="'pi-' + filterState.period + filterState.fiscal_year + filterState.date_from + filterState.date_to + filterState.yarn_count.join()" />
                    </div>

                    <!-- Progressive Pie (Radial Bar) -->
                    <div v-if="hasData && !loading" data-pdf-section data-cards-grid class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-5">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-0.5">
                            Yarn Count Summary — {{ periodLabel }}
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Each yarn count shown separately (share of total order KG)</p>

                        <div class="yarn-count-grid grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5">
                            <div
                                v-for="(c, i) in yarnList"
                                :key="c"
                                class="yarn-count-card rounded-2xl border overflow-hidden"
                                :style="{ '--accent': COLORS[i % COLORS.length] }"
                            >
                                <!-- Colour accent bar -->
                                <div class="h-1 w-full" :style="{ background: `linear-gradient(90deg, ${COLORS[i % COLORS.length]}, ${COLORS[(i+1) % COLORS.length]})` }" />

                                <!-- Header: name + rank badge -->
                                <div class="px-4 pt-3 pb-2 flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="text-base font-extrabold text-gray-900 dark:text-gray-50 tracking-tight truncate">{{ c }}</span>
                                            <span
                                                class="flex-shrink-0 inline-flex items-center justify-center rounded-full text-[10px] font-black text-white w-5 h-5"
                                                :style="{ backgroundColor: COLORS[i % COLORS.length] }"
                                            >#{{ i + 1 }}</span>
                                        </div>
                                        <div class="mt-0.5 flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                            <span class="font-semibold text-gray-700 dark:text-gray-200">{{ fmtMT(totals?.[c] ?? 0) }}</span>
                                            <span class="text-gray-300 dark:text-gray-600">·</span>
                                            <span
                                                class="font-bold"
                                                :style="{ color: COLORS[i % COLORS.length] }"
                                            >{{ (yarnPct?.[c] ?? 0).toFixed(3) }}% share</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Radial chart -->
                                <div class="yarn-chart-area px-2">
                                    <apexchart
                                        type="radialBar"
                                        height="200"
                                        :options="radialSingleOpts(c, i)"
                                        :series="[yarnPct?.[c] ?? 0]"
                                        :key="'radial-one-' + c + '-' + filterState.period + filterState.fiscal_year + filterState.date_from + filterState.date_to + filterState.booking_type + filterState.yarn_count.join()"
                                    />
                                </div>

                                <!-- Stats grid -->
                                <div class="px-4 py-3 grid grid-cols-2 gap-2">
                                    <!-- Received -->
                                    <div class="stat-chip stat-chip--emerald">
                                        <div class="stat-chip-icon">
                                            <svg viewBox="0 0 16 16" fill="none" class="w-3.5 h-3.5"><path d="M3 8l3.5 3.5L13 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </div>
                                        <div class="stat-chip-body">
                                            <span class="stat-label">Received</span>
                                            <span class="stat-value">{{ fmtMT(yarnExtras?.[c]?.received_kg ?? 0) }}</span>
                                        </div>
                                    </div>
                                    <!-- Balance -->
                                    <div class="stat-chip" :class="(yarnExtras?.[c]?.balance_kg ?? 0) > 0 ? 'stat-chip--amber' : 'stat-chip--gray'">
                                        <div class="stat-chip-icon">
                                            <svg viewBox="0 0 16 16" fill="none" class="w-3.5 h-3.5"><path d="M8 3v5l3 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5"/></svg>
                                        </div>
                                        <div class="stat-chip-body">
                                            <span class="stat-label">Balance</span>
                                            <span class="stat-value">{{ fmtMT(yarnExtras?.[c]?.balance_kg ?? 0) }}</span>
                                        </div>
                                    </div>
                                    <!-- PI Records -->
                                    <div class="stat-chip stat-chip--indigo">
                                        <div class="stat-chip-icon">
                                            <svg viewBox="0 0 16 16" fill="none" class="w-3.5 h-3.5"><rect x="2" y="2" width="12" height="12" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M5 6h6M5 9h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                        </div>
                                        <div class="stat-chip-body">
                                            <span class="stat-label">PI Records</span>
                                            <span class="stat-value">{{ fmtNum(yarnExtras?.[c]?.pi_records ?? piCounts?.[c] ?? 0) }}</span>
                                        </div>
                                    </div>
                                    <!-- Avg Lead Time -->
                                    <div class="stat-chip stat-chip--violet">
                                        <div class="stat-chip-icon">
                                            <svg viewBox="0 0 16 16" fill="none" class="w-3.5 h-3.5"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5"/><path d="M8 5v3.5l2 1.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </div>
                                        <div class="stat-chip-body">
                                            <span class="stat-label">Avg Lead Time</span>
                                            <span class="stat-value">{{ yarnExtras?.[c]?.avg_lead_days == null ? '—' : yarnExtras[c].avg_lead_days + 'd' }}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Booking type tags -->
                                <div v-if="typeTop(c).length" class="px-4 pb-4">
                                    <div class="text-[9px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-600 mb-1.5">By Type</div>
                                    <div class="flex flex-wrap gap-1.5">
                                        <div
                                            v-for="([t, kg]) in typeTop(c)"
                                            :key="t"
                                            class="type-tag"
                                            :class="t === 'CF' ? 'type-tag--cf' : t === 'CC' ? 'type-tag--cc' : 'type-tag--other'"
                                        >
                                            <span class="type-tag-badge">{{ t }}</span>
                                            <span class="type-tag-value">{{ fmtMTValue(kg) }}<span class="type-tag-unit">MT</span></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        v-else-if="!loading && !hasData"
                        class="rounded-xl border border-dashed border-gray-300 dark:border-gray-600 p-10 text-center text-gray-400 dark:text-gray-500 text-sm"
                    >
                        No order data found for the selected period.
                    </div>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

<style scoped>
/* ── Action Bar ── */
.action-bar {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 4px 6px;
    border-radius: 12px;
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    border: 1px solid #e2e8f0;
    box-shadow:
        0 1px 3px rgba(0,0,0,0.06),
        inset 0 1px 0 rgba(255,255,255,0.8);
}

:global(.dark .action-bar) {
    background: linear-gradient(135deg, #1e293b, #0f172a);
    border-color: #334155;
    box-shadow: 0 1px 3px rgba(0,0,0,0.3), inset 0 1px 0 rgba(255,255,255,0.04);
}

.action-bar-group {
    display: flex;
    align-items: center;
    gap: 3px;
}

.action-bar-divider {
    width: 1px;
    height: 22px;
    background: #e2e8f0;
    border-radius: 1px;
    margin: 0 2px;
}

:global(.dark .action-bar-divider) {
    background: #334155;
}

/* Base action button */
.action-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 11px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.01em;
    border: 1px solid transparent;
    cursor: pointer;
    transition: all 0.15s ease;
    white-space: nowrap;
    color: #fff;
    position: relative;
    overflow: hidden;
}

.action-btn::after {
    content: '';
    position: absolute;
    inset: 0;
    background: rgba(255,255,255,0);
    transition: background 0.15s ease;
    border-radius: inherit;
}

.action-btn:hover::after  { background: rgba(255,255,255,0.12); }
.action-btn:active { transform: scale(0.96); }
.action-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

/* Variants */
.action-btn--indigo {
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    border-color: rgba(99,102,241,0.4);
    box-shadow: 0 1px 4px rgba(99,102,241,0.35), inset 0 1px 0 rgba(255,255,255,0.15);
}
.action-btn--indigo:hover:not(:disabled) {
    box-shadow: 0 3px 10px rgba(99,102,241,0.45), inset 0 1px 0 rgba(255,255,255,0.18);
    transform: translateY(-1px);
}

.action-btn--rose {
    background: linear-gradient(135deg, #f43f5e, #e11d48);
    border-color: rgba(244,63,94,0.4);
    box-shadow: 0 1px 4px rgba(244,63,94,0.35), inset 0 1px 0 rgba(255,255,255,0.15);
}
.action-btn--rose:hover:not(:disabled) {
    box-shadow: 0 3px 10px rgba(244,63,94,0.45), inset 0 1px 0 rgba(255,255,255,0.18);
    transform: translateY(-1px);
}

.action-btn--slate {
    background: linear-gradient(135deg, #475569, #334155);
    border-color: rgba(71,85,105,0.4);
    box-shadow: 0 1px 4px rgba(51,65,85,0.35), inset 0 1px 0 rgba(255,255,255,0.12);
}
.action-btn--slate:hover:not(:disabled) {
    box-shadow: 0 3px 10px rgba(51,65,85,0.45), inset 0 1px 0 rgba(255,255,255,0.15);
    transform: translateY(-1px);
}

/* Close button */
.action-btn-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #64748b;
    cursor: pointer;
    transition: all 0.15s ease;
    flex-shrink: 0;
}

.action-btn-close:hover {
    background: #fef2f2;
    border-color: #fecaca;
    color: #ef4444;
    transform: scale(1.05);
}

:global(.dark .action-btn-close) {
    background: #1e293b;
    border-color: #334155;
    color: #94a3b8;
}

:global(.dark .action-btn-close:hover) {
    background: rgba(239,68,68,0.1);
    border-color: rgba(239,68,68,0.3);
    color: #f87171;
}
@keyframes gradient-shift {
    0%   { background-position: 0% 50%; }
    50%  { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

@keyframes border-glow {
    0%, 100% { box-shadow: 0 0 6px 1px rgba(99, 102, 241, 0.35), 0 2px 8px rgba(99, 102, 241, 0.2); }
    50%       { box-shadow: 0 0 14px 3px rgba(139, 92, 246, 0.5), 0 4px 16px rgba(99, 102, 241, 0.3); }
}

@keyframes icon-bounce {
    0%, 100% { transform: translateY(0);    }
    50%       { transform: translateY(-2px); }
}

.btn-view-analysis {
    background: linear-gradient(135deg, #6366f1, #8b5cf6, #4f46e5, #7c3aed);
    background-size: 300% 300%;
    animation:
        gradient-shift 3s ease infinite,
        border-glow    2.5s ease-in-out infinite;
    border: none;
    position: relative;
    overflow: hidden;
}

/* shimmer sweep — always running */
.btn-view-analysis::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(
        105deg,
        transparent 30%,
        rgba(255, 255, 255, 0.25) 50%,
        transparent 70%
    );
    background-size: 200% 100%;
    animation: shimmer-sweep 2.2s linear infinite;
}

@keyframes shimmer-sweep {
    0%   { background-position: -200% 0; }
    100% { background-position:  200% 0; }
}

.btn-view-analysis:hover {
    filter: brightness(1.1);
    transform: translateY(-1px);
    transition: filter 0.2s, transform 0.2s;
}

.btn-view-analysis:active {
    transform: scale(0.97);
}

.btn-icon {
    animation: icon-bounce 2s ease-in-out infinite;
    position: relative;
}

/* ── Yarn Count Cards ── */
.yarn-count-card {
    background: #ffffff;
    border-color: #e5e7eb;
    box-shadow:
        /* outer lift */
        0 1px 3px rgba(0,0,0,0.07),
        0 4px 12px rgba(0,0,0,0.05),
        /* inner top highlight */
        inset 0 1px 0 rgba(255,255,255,0.9),
        /* inner bottom depth */
        inset 0 -20px 32px rgba(0,0,0,0.035);
    transition: box-shadow 0.2s ease, transform 0.2s ease;
}

.yarn-count-card:hover {
    box-shadow:
        0 4px 16px rgba(0,0,0,0.1),
        0 8px 24px rgba(0,0,0,0.06),
        inset 0 1px 0 rgba(255,255,255,0.95),
        inset 0 -24px 36px rgba(0,0,0,0.04);
    transform: translateY(-2px);
}

:global(.dark .yarn-count-card) {
    background: #111827;
    border-color: #374151;
    box-shadow:
        0 1px 3px rgba(0,0,0,0.3),
        0 4px 12px rgba(0,0,0,0.2),
        inset 0 1px 0 rgba(255,255,255,0.05),
        inset 0 -24px 36px rgba(0,0,0,0.45);
}

:global(.dark .yarn-count-card:hover) {
    box-shadow:
        0 4px 20px rgba(0,0,0,0.4),
        inset 0 1px 0 rgba(255,255,255,0.07),
        inset 0 -28px 40px rgba(0,0,0,0.55);
    transform: translateY(-2px);
}

/* chart area — clean well for the circle to shine */
.yarn-chart-area {
    background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
    border-top: 1px solid rgba(0,0,0,0.05);
    border-bottom: 1px solid rgba(0,0,0,0.05);
}

:global(.dark .yarn-chart-area) {
    background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%);
    border-top-color: rgba(255,255,255,0.04);
    border-bottom-color: rgba(255,255,255,0.04);
}

/* Stat chips */
.stat-chip {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    border-left-width: 3px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    box-shadow:
        0 1px 2px rgba(0,0,0,0.04),
        inset 0 1px 0 rgba(255,255,255,0.85);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.stat-chip:hover {
    transform: translateY(-1px);
    box-shadow:
        0 3px 8px rgba(0,0,0,0.08),
        inset 0 1px 0 rgba(255,255,255,0.9);
}

:global(.dark .stat-chip) {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    border-color: #334155;
    box-shadow:
        0 1px 2px rgba(0,0,0,0.3),
        inset 0 1px 0 rgba(255,255,255,0.04);
}

/* Color variants — left border + icon color */
.stat-chip--emerald { border-left-color: #10b981; }
.stat-chip--amber   { border-left-color: #f59e0b; }
.stat-chip--gray    { border-left-color: #94a3b8; }
.stat-chip--indigo  { border-left-color: #6366f1; }
.stat-chip--violet  { border-left-color: #8b5cf6; }

.stat-chip--emerald .stat-chip-icon { color: #10b981; background: #d1fae5; }
.stat-chip--amber   .stat-chip-icon { color: #d97706; background: #fef3c7; }
.stat-chip--gray    .stat-chip-icon { color: #94a3b8; background: #f1f5f9; }
.stat-chip--indigo  .stat-chip-icon { color: #6366f1; background: #e0e7ff; }
.stat-chip--violet  .stat-chip-icon { color: #8b5cf6; background: #ede9fe; }

:global(.dark .stat-chip--emerald .stat-chip-icon) { color: #34d399; background: rgba(16,185,129,0.15); }
:global(.dark .stat-chip--amber   .stat-chip-icon) { color: #fbbf24; background: rgba(245,158,11,0.15); }
:global(.dark .stat-chip--gray    .stat-chip-icon) { color: #94a3b8; background: rgba(148,163,184,0.1); }
:global(.dark .stat-chip--indigo  .stat-chip-icon) { color: #818cf8; background: rgba(99,102,241,0.15); }
:global(.dark .stat-chip--violet  .stat-chip-icon) { color: #a78bfa; background: rgba(139,92,246,0.15); }

.stat-chip-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 26px;
    height: 26px;
    border-radius: 7px;
}

.stat-chip-body {
    display: flex;
    flex-direction: column;
    gap: 1px;
    min-width: 0;
}

.stat-label {
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #94a3b8;
    line-height: 1;
}

:global(.dark .stat-label) {
    color: #64748b;
}

.stat-value {
    font-size: 12px;
    font-weight: 800;
    color: #1e293b;
    line-height: 1.2;
    white-space: nowrap;
}

:global(.dark .stat-value) {
    color: #e2e8f0;
}

/* Booking type tags */
.type-tag {
    display: inline-flex;
    align-items: center;
    gap: 0;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid transparent;
    box-shadow:
        0 1px 3px rgba(0,0,0,0.08),
        inset 0 1px 0 rgba(255,255,255,0.5);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.type-tag:hover {
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(0,0,0,0.13), inset 0 1px 0 rgba(255,255,255,0.6);
}

/* CF — blue */
.type-tag--cf {
    border-color: #bfdbfe;
    background: #eff6ff;
}
.type-tag--cf .type-tag-badge {
    background: #2563eb;
    color: #fff;
}
.type-tag--cf .type-tag-value {
    color: #1d4ed8;
}

/* CC — violet */
.type-tag--cc {
    border-color: #ddd6fe;
    background: #f5f3ff;
}
.type-tag--cc .type-tag-badge {
    background: #7c3aed;
    color: #fff;
}
.type-tag--cc .type-tag-value {
    color: #6d28d9;
}

/* other */
.type-tag--other {
    border-color: #e2e8f0;
    background: #f8fafc;
}
.type-tag--other .type-tag-badge {
    background: #64748b;
    color: #fff;
}
.type-tag--other .type-tag-value {
    color: #475569;
}

/* dark mode */
:global(.dark .type-tag--cf) { border-color: rgba(37,99,235,0.3);  background: rgba(37,99,235,0.08); }
:global(.dark .type-tag--cc) { border-color: rgba(124,58,237,0.3); background: rgba(124,58,237,0.08); }
:global(.dark .type-tag--other) { border-color: #334155;              background: #1e293b; }
:global(.dark .type-tag--cf .type-tag-value) { color: #93c5fd; }
:global(.dark .type-tag--cc .type-tag-value) { color: #c4b5fd; }
:global(.dark .type-tag--other .type-tag-value) { color: #94a3b8; }

.type-tag-badge {
    padding: 3px 7px;
    font-size: 10px;
    font-weight: 900;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    line-height: 1;
    white-space: nowrap;
}

.type-tag-value {
    padding: 3px 8px 3px 6px;
    font-size: 11px;
    font-weight: 700;
    tabular-nums: true;
    white-space: nowrap;
}

.type-tag-unit {
    font-size: 9px;
    font-weight: 600;
    opacity: 0.6;
    margin-left: 2px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
</style>

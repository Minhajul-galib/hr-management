<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MetricCard from '@/Components/MetricCard.vue';
import ChartCard from '@/Components/ChartCard.vue';
import DepartmentProgress from '@/Components/DepartmentProgress.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { 
    LayoutDashboard, 
    Users, 
    DollarSign,
    BarChart3,
    TrendingUp,
    UserCheck,
    Target,
    PieChart,
    Activity,
    Building2,
    FileText
} from 'lucide-vue-next';

// Props from controller
const props = defineProps({
    can: Object,
    stats: Object,
    debitCreditData: Object,
    supplierDistribution: Object,
    departmentData: Array,
    recentActivities: Array,
    taskProgressData: Array,
    unitOutstanding: Array,
    topSuppliers: Array,
    unitCreditDebit: Object,
    supplierCreditGradeDistribution: Object,
    supplierRatingGradeDistribution: Object,
    supplierGradeByTypeDistribution: Object,
    thresholdCreditData: Object,
    suppliers: Array,
});

const debitCreditFromMonth = ref('');
const debitCreditToMonth = ref('');
const debitCreditPreset = ref('last6'); // last6|last12|ytd|custom

const debitCreditTitle = computed(() => {
    const meta = props.debitCreditData?.meta;
    const gran = meta?.granularity || 'month';
    if (gran === 'day') {
        return `Debit (পেমেন্ট) vs Credit (বকেয়া) (Daily: ${meta?.from || ''} → ${meta?.to || ''})`;
    }
    const range = meta?.rangeMonths || (debitCreditPreset.value === 'last12' ? 12 : 6);
    return `Monthly Debit (পেমেন্ট) vs Credit (বকেয়া) (Last ${range} Months)`;
});

function applyDebitCreditRange(params) {
    router.get('/dashboard', params, {
        preserveScroll: true,
        replace: true,
    });
}

function applyDebitCreditPreset() {
    if (debitCreditPreset.value === 'last6') {
        debitCreditFromMonth.value = '';
        debitCreditToMonth.value = '';
        applyDebitCreditRange({ granularity: 'month', rangeMonths: 6 });
        return;
    }
    if (debitCreditPreset.value === 'last12') {
        debitCreditFromMonth.value = '';
        debitCreditToMonth.value = '';
        applyDebitCreditRange({ granularity: 'month', rangeMonths: 12 });
        return;
    }
    if (debitCreditPreset.value === 'ytd') {
        const now = new Date();
        const y = now.getFullYear();
        const from = `${y}-01`;
        const to = `${y}-${String(now.getMonth() + 1).padStart(2, '0')}`;
        debitCreditFromMonth.value = from;
        debitCreditToMonth.value = to;
        applyDebitCreditRange({ granularity: 'month', from, to });
        return;
    }
}

function applyDebitCreditCustom() {
    if (!debitCreditFromMonth.value || !debitCreditToMonth.value) return;
    applyDebitCreditRange({
        granularity: 'month',
        from: debitCreditFromMonth.value,
        to: debitCreditToMonth.value,
    });
}

function resetDebitCreditToMonthly() {
    // keep current month range if available
    if (debitCreditFromMonth.value && debitCreditToMonth.value) {
        applyDebitCreditCustom();
        return;
    }
    applyDebitCreditPreset();
}

// Chart options for Debit vs Credit
const debitCreditOptions = ref({
    chart: { 
        id: 'debit-credit-chart',
        type: 'area',
        toolbar: { 
            show: true,
            tools: {
                download: true,
                selection: true,
                zoom: true,
                zoomin: true,
                zoomout: true,
                pan: true,
                reset: true,
            },
        },
        zoom: {
            enabled: true,
            type: 'x',
            autoScaleYaxis: true,
        },
        events: {
            selection: function (_chart, e) {
                const min = e?.xaxis?.min;
                const max = e?.xaxis?.max;
                if (!min || !max) return;

                const spanDays = Math.ceil((max - min) / (1000 * 60 * 60 * 24));
                // If user zooms into a smaller window, reload as day-wise
                if (spanDays > 0 && spanDays <= 180) {
                    const from = new Date(min).toISOString().slice(0, 10);
                    const to = new Date(max).toISOString().slice(0, 10);
                    applyDebitCreditRange({ granularity: 'day', from, to });
                }
            },
            beforeResetZoom: function () {
                // When hitting "Reset" in toolbar, go back to monthly view
                resetDebitCreditToMonthly();
                return false;
            },
        },
        sparkline: { enabled: false }
    },
    colors: ['#10B981', '#EF4444'],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2 },
    fill: {
        type: 'gradient',
        gradient: {
            shadeIntensity: 1,
            opacityFrom: 0.7,
            opacityTo: 0.3,
        }
    },
    xaxis: { 
        type: 'datetime',
        labels: { 
            style: { fontSize: '11px' },
            rotate: -30,
        }
    },
    yaxis: {
        labels: {
            formatter: function (val) {
                if (val >= 100000) {
                    return '৳' + (val / 100000).toFixed(1) + 'L';
                } else if (val >= 1000) {
                    return '৳' + (val / 1000).toFixed(0) + 'K';
                }
                return '৳' + val.toFixed(0);
            }
        }
    },
    tooltip: {
        y: {
            formatter: function (val) {
                return '৳' + val.toLocaleString()
            }
        }
    },
    legend: { position: 'top' }
});

// Chart options for Supplier Distribution
const supplierDistributionOptions = ref({
    chart: { type: 'donut', toolbar: { show: false } },
    colors: ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#06B6D4', '#EC4899', '#14B8A6'],
    labels: props.supplierDistribution?.labels || [],
    legend: { position: 'bottom' },
    plotOptions: {
        pie: {
            donut: {
                size: '70%',
                labels: {
                    show: true,
                    total: {
                        show: true,
                        label: 'Total Suppliers',
                        formatter: () => props.stats.totalSuppliers?.toString() || '0'
                    }
                }
            }
        }
    },
    tooltip: {
        y: {
            formatter: function (val) {
                return val + ' suppliers'
            }
        }
    }
});

// Unit-wise Credit and Expenses chart
const unitChartOptions = ref({
    chart: { type: 'bar', toolbar: { show: false }, stacked: false },
    colors: ['#10B981', '#EF4444'], // Green for Debit, Red for Credit
    plotOptions: {
        bar: {
            horizontal: false,
            borderRadius: 4,
            columnWidth: '60%'
        }
    },
    dataLabels: { enabled: false },
    xaxis: {
        categories: props.unitCreditDebit?.labels || []
    },
    yaxis: {
        labels: {
            formatter: function (val) {
                if (val >= 100000) {
                    return '৳' + (val / 100000).toFixed(1) + 'L';
                } else if (val >= 1000) {
                    return '৳' + (val / 1000).toFixed(0) + 'K';
                }
                return '৳' + val.toFixed(0);
            }
        }
    },
    legend: { position: 'bottom' },
    tooltip: {
        y: {
            formatter: function (val) {
                return '৳' + (val || 0).toLocaleString();
            }
        }
    }
});

const supplierCreditGradeOptions = ref({
    chart: { type: 'donut', toolbar: { show: false } },
    colors: ['#16A34A', '#3B82F6', '#F59E0B', '#EF4444', '#9CA3AF'],
    labels: props.supplierRatingGradeDistribution?.labels || [],
    legend: { position: 'bottom' },
    dataLabels: {
        enabled: true,
        formatter: function (val, opts) {
            const total = opts.w.globals.seriesTotals.reduce((a, b) => a + b, 0);
            const value = opts.w.globals.series[opts.seriesIndex];
            const pct = total ? Math.round((value / total) * 100) : 0;
            return pct + '%';
        }
    },
    plotOptions: {
        pie: {
            donut: {
                size: '70%',
                labels: {
                    show: true,
                    name: { show: true },
                    value: {
                        show: true,
                        formatter: function (val, opts) {
                            const total = opts.w.globals.seriesTotals.reduce((a, b) => a + b, 0);
                            const value = opts.w.globals.series[opts.seriesIndex];
                            const pct = total ? Math.round((value / total) * 100) : 0;
                            return pct + '%';
                        }
                    },
                    total: {
                        show: true,
                        label: 'Suppliers',
                        formatter: () => (props.stats.totalSuppliers || 0).toString()
                    }
                }
            }
        }
    }
});

// Threshold Chart Options and Series
const thresholdChartOptions = ref({
    chart: { type: 'donut', toolbar: { show: false } },
    colors: ['#16A34A', '#F59E0B', '#EF4444'],
    labels: ['Within Threshold', 'Near Threshold', 'Above Threshold'],
    legend: { position: 'bottom' },
    dataLabels: {
        enabled: true,
        formatter: function (val, opts) {
            const total = opts.w.globals.seriesTotals.reduce((a, b) => a + b, 0);
            const value = opts.w.globals.series[opts.seriesIndex];
            const pct = total ? Math.round((value / total) * 100) : 0;
            return pct + '%';
        }
    },
    plotOptions: {
        pie: {
            donut: {
                size: '70%',
                labels: {
                    show: true,
                    name: { show: true },
                    value: {
                        show: true,
                        formatter: function (val, opts) {
                            const total = opts.w.globals.seriesTotals.reduce((a, b) => a + b, 0);
                            const value = opts.w.globals.series[opts.seriesIndex];
                            const pct = total ? Math.round((value / total) * 100) : 0;
                            return pct + '%';
                        }
                    },
                    total: {
                        show: true,
                        label: 'Total Suppliers',
                        formatter: () => {
                            const data = props.thresholdCreditData || {};
                            const total = (data.withinThreshold || 0) + (data.nearThreshold || 0) + (data.aboveThreshold || 0);
                            return total.toString();
                        }
                    }
                }
            }
        }
    }
});

const thresholdChartSeries = computed(() => {
    const data = props.thresholdCreditData || {};
    return [
        data.withinThreshold || 0,
        data.nearThreshold || 0,
        data.aboveThreshold || 0
    ];
});

// ── Motivational Quote ───────────────────────────────────────────────────────
const quotes = [
    {
        text: "Excellence is not a destination; it is a continuous journey. Every task you complete with dedication adds a brick to Clifton's legacy.",
        author: "Clifton Group",
    },
    {
        text: "Your work today is the foundation of Clifton's tomorrow. Show up, give your best, and let your effort speak louder than words.",
        author: "Clifton Group",
    },
    {
        text: "A great organisation is built by great people. You are not just an employee — you are a part of something bigger than yourself.",
        author: "Clifton Group",
    },
    {
        text: "Integrity, discipline, and hard work are the three pillars of Clifton. Carry them with pride in everything you do.",
        author: "Clifton Group",
    },
    {
        text: "Small consistent efforts create extraordinary results. Trust the process, stay committed, and Clifton will grow with you.",
        author: "Clifton Group",
    },
    {
        text: "Your dedication to your role is what keeps Clifton moving forward. Never underestimate the impact of doing your job well.",
        author: "Clifton Group",
    },
    {
        text: "We rise by lifting each other. Collaboration, respect, and shared purpose — that is the Clifton way.",
        author: "Clifton Group",
    },
];

// Pick a quote that changes daily (consistent for the whole day)
const todayQuote = computed(() => {
    const dayOfYear = Math.floor((Date.now() - new Date(new Date().getFullYear(), 0, 0)) / 86400000);
    return quotes[dayOfYear % quotes.length];
});

const gradePercents = computed(() => {
    const data = props.supplierGradeByTypeDistribution?.data || [];
    const total = data.reduce((a, b) => a + b, 0);
    return data.map(d => (total ? Math.round((d / total) * 100) : 0));
});
const gradeLabels = computed(() => props.supplierGradeByTypeDistribution?.labels || []);

const taskData = ref([78, 65, 89, 45]);
const taskOptions = ref({
    chart: { type: 'bar', toolbar: { show: false } },
    colors: ['#3B82F6'],
    plotOptions: {
        bar: {
            horizontal: true,
            borderRadius: 4,
        }
    },
    dataLabels: { enabled: false },
    xaxis: {
        categories: ['Development', 'Testing', 'Design', 'Documentation'],
        max: 100
    }
});

// Dynamic task progress data
const dynamicTaskData = computed(() => props.taskProgressData?.map(item => item.percentage) || []);
const dynamicTaskCategories = computed(() => props.taskProgressData?.map(item => item.status) || []);

const dynamicTaskOptions = computed(() => ({
    chart: { type: 'bar', toolbar: { show: false } },
    colors: ['#3B82F6'],
    plotOptions: {
        bar: {
            horizontal: true,
            borderRadius: 4,
        }
    },
    dataLabels: { enabled: false },
    xaxis: {
        categories: dynamicTaskCategories.value,
        max: 100
    },
    tooltip: {
        y: {
            formatter: function (val) {
                return val + '%';
            }
        }
    }
}));

// Computed values
const attendanceRate = computed(() => {
    if (!props.stats.totalEmployees || props.stats.totalEmployees === 0) return '0';
    return ((props.stats.activeEmployees / props.stats.totalEmployees) * 100).toFixed(1);
});
const taskCompletionRate = computed(() => {
    const total = props.stats.completedTasks + props.stats.pendingTasks;
    if (total === 0) return '0';
    return ((props.stats.completedTasks / total) * 100).toFixed(1);
});
const formatCurrency = (amount) => {
    return new Intl.NumberFormat('en-BD').format(amount);
};

// Group suppliers by threshold_limit value and compute summary
const thresholdGroups = computed(() => {
    // Debug logs
    console.log('🔍 DASHBOARD DEBUG: can?.thresholdCreditSummary:', props.can?.thresholdCreditSummary);
    console.log('🔍 DASHBOARD DEBUG: suppliers:', props.suppliers);
    console.log('🔍 DASHBOARD DEBUG: suppliers length:', (props.suppliers || []).length);
    
    const groups = new Map();
    for (const s of props.suppliers || []) {
        const key = Number(s.threshold_limit) || 0;
        if (!groups.has(key)) {
            groups.set(key, {
                threshold: key,
                supplier_count: 0,
                total_due: 0,
                total_limit: 0,
                over_limit_count: 0
            });
        }
        const g = groups.get(key);
        g.supplier_count++;
        g.total_due += Number(s.current_due || 0);
        g.total_limit += key;
        if (s.is_over_limit) g.over_limit_count++;
    }
    const result = Array.from(groups.values()).map(g => ({
        ...g,
        avg_utilization: g.total_limit > 0 ? Math.round((g.total_due / g.total_limit) * 100) : 0
    }));
    console.log('🔍 DASHBOARD DEBUG: thresholdGroups:', result);
    return result;
});

const formattedDebit = computed(() => {
    const value = props.stats.totalDebit || 0;
    if (value >= 100000) {
        return (value / 100000).toFixed(1) + 'L'; // Lakhs
    } else if (value >= 1000) {
        return (value / 1000).toFixed(0) + 'K'; // Thousands
    }
    return value.toFixed(0); // Less than 1000, show as is
});

const formattedCredit = computed(() => {
    const value = props.stats.totalCredit || 0;
    if (value >= 100000) {
        return (value / 100000).toFixed(1) + 'L'; // Lakhs
    } else if (value >= 1000) {
        return (value / 1000).toFixed(0) + 'K'; // Thousands
    }
    return value.toFixed(0); // Less than 1000, show as is
});
</script>

<template>
    <Head title="Analytics Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 flex items-center gap-2">
                    <LayoutDashboard :size="24" class="text-blue-600" />
                    Analytics Dashboard
                </h2>
                <div class="flex items-center gap-2 px-3 py-1 rounded-full bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-700">
                    <span class="w-1.5 h-1.5 rounded-full bg-blue-500 flex-shrink-0"></span>
                    <span class="text-xs font-semibold text-blue-700 dark:text-blue-300 tracking-wide">

                        Last updated: {{ new Date().toLocaleString() }}
                    </span>
                </div>

            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-4 lg:px-4 xl:max-w-[1440px] 2xl:max-w-[1600px] space-y-6">

                <!-- Motivational Banner — visible to all roles -->
                <div class="relative overflow-hidden rounded-xl bg-gradient-to-r from-blue-700 via-indigo-700 to-blue-800 px-6 py-5 shadow-md">
                    <!-- Decorative circles -->
                    <div class="pointer-events-none absolute -top-6 -right-6 h-32 w-32 rounded-full bg-white/5"></div>
                    <div class="pointer-events-none absolute -bottom-8 -left-4 h-24 w-24 rounded-full bg-white/5"></div>

                    <div class="relative flex flex-col sm:flex-row sm:items-center gap-4">
                        <!-- Icon -->
                        <div class="flex-shrink-0 flex items-center justify-center w-12 h-12 rounded-full bg-white/15 text-2xl select-none">
                            🌟
                        </div>
                        <!-- Quote -->
                        <div class="flex-1 min-w-0">
                            <p class="text-white font-medium text-sm sm:text-base leading-relaxed italic">
                                "{{ todayQuote.text }}"
                            </p>
                            <p class="mt-1.5 text-blue-200 text-xs font-semibold tracking-wide">
                                — {{ todayQuote.author }}
                            </p>
                        </div>
                        <!-- Clifton badge -->
                        <div class="flex-shrink-0 hidden sm:flex items-center gap-1.5 bg-white/10 border border-white/20 rounded-lg px-3 py-2">
                            <span class="text-lg select-none">🏢</span>
                            <div class="text-left">
                                <p class="text-white text-[11px] font-bold leading-tight">Clifton Group</p>
                                <p class="text-blue-200 text-[10px] leading-tight">ERP System</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Key Metrics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <!-- Total Users -->
                    <MetricCard
                        v-if="can?.totalUsers"
                        title="Total Users"
                        :value="stats.totalEmployees"
                        subtitle="System users"
                        :icon="Users"
                        icon-color="text-blue-500"
                        border-color="border-blue-500"
                        subtitle-color="text-gray-600"
                    >
                        <template #subtitle-icon>
                            <Users :size="16" />
                        </template>
                    </MetricCard>

                    <!-- Total Debit -->
                    <MetricCard
                        v-if="can?.financialSummary"
                        title="Total Debit (পেমেন্ট)"
                        :value="`৳${formattedDebit}`"
                        :icon="DollarSign"
                        icon-color="text-green-500"
                        border-color="border-green-500"
                        :subtitle="`Total: ৳${(stats.totalDebit || 0).toLocaleString()}`"
                        subtitle-color="text-green-600"
                    >
                        <template #subtitle-icon>
                            <DollarSign :size="16" />
                        </template>
                    </MetricCard>

                    <!-- Total Credit -->
                    <MetricCard
                        v-if="can?.financialSummary"
                        title="Total Credit (বকেয়া)"
                        :value="`৳${formattedCredit}`"
                        :icon="DollarSign"
                        icon-color="text-red-500"
                        border-color="border-red-500"
                        :subtitle="`Total: ৳${(stats.totalCredit || 0).toLocaleString()}`"
                        subtitle-color="text-red-600"
                    >
                        <template #subtitle-icon>
                            <DollarSign :size="16" />
                        </template>
                    </MetricCard>

                    <!-- Total Documents -->
                    <MetricCard
                        v-if="can?.totalDocuments"
                        title="Total Documents"
                        :value="stats.totalDocuments"
                        subtitle="All documents"
                        :icon="FileText"
                        icon-color="text-purple-500"
                        border-color="border-purple-500"
                        subtitle-color="text-gray-600"
                    >
                        <template #subtitle-icon>
                            <FileText :size="16" />
                        </template>
                    </MetricCard>
                </div>

                <!-- Charts Row -->
                <div v-if="can?.debitCreditChart || can?.unitChart" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Debit vs Credit Chart -->
                    <ChartCard v-if="can?.debitCreditChart" :title="debitCreditTitle" :icon="TrendingUp" icon-color="text-blue-600">
                        <div class="flex flex-wrap items-end gap-3 mb-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">View</label>
                                <select v-model="debitCreditPreset" @change="applyDebitCreditPreset" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1 text-sm">
                                    <option value="last6">Last 6 Months</option>
                                    <option value="last12">Last 12 Months</option>
                                    <option value="ytd">YTD (Jan → Now)</option>
                                    <option value="custom">Custom (month range)</option>
                                </select>
                            </div>

                            <div v-if="debitCreditPreset === 'custom'" class="flex flex-wrap items-end gap-2">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">From (Month)</label>
                                    <input v-model="debitCreditFromMonth" type="month" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1 text-sm" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">To (Month)</label>
                                    <input v-model="debitCreditToMonth" type="month" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1 text-sm" />
                                </div>
                                <button
                                    type="button"
                                    @click="applyDebitCreditCustom"
                                    class="inline-flex items-center px-3 py-1.5 rounded-md bg-blue-600 text-white text-sm hover:bg-blue-700"
                                >
                                    Apply
                                </button>
                            </div>

                            <div class="ml-auto text-xs text-gray-500">
                                Zoom to check day-wise details (up to 180 days)
                            </div>
                        </div>
                        <apexchart type="area" height="400" :options="debitCreditOptions" :series="[
                            { name: 'Debit (পেমেন্ট)', data: props.debitCreditData?.series[0]?.data || [] },
                            { name: 'Credit (বকেয়া)', data: props.debitCreditData?.series[1]?.data || [] }
                        ]"></apexchart>
                    </ChartCard>

                    <ChartCard v-if="can?.unitChart" title="Unit-wise Debit (পেমেন্ট) vs Credit (বকেয়া)" :icon="BarChart3" icon-color="text-indigo-600">
                        <apexchart
                            type="bar"
                            height="400"
                            :options="unitChartOptions"
                            :series="[
                                { name: 'Debit (পেমেন্ট)', data: props.unitCreditDebit?.debit || [] },
                                { name: 'Credit (বকেয়া)', data: props.unitCreditDebit?.credit || [] }
                            ]"
                        ></apexchart>
                    </ChartCard>
                </div>

                <!-- Supplier Performance & Task Progress -->
                <div v-if="can?.topSuppliers || can?.supplierGrades" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Top Suppliers by Limit Usage -->
                    <div v-if="can?.topSuppliers" class="lg:col-span-2">
                        <ChartCard title="Top Suppliers by Limit Usage" :icon="Building2" icon-color="text-indigo-600">
                            <div class="max-h-96 overflow-y-auto">
                                <DepartmentProgress :suppliers="topSuppliers" />
                            </div>
                        </ChartCard>
                    </div>

                    <ChartCard v-if="can?.supplierGrades" title="Grade-wise Suppliers" :icon="Activity" icon-color="text-purple-600">
                        <apexchart type="donut" height="280" :options="supplierCreditGradeOptions" :series="supplierGradeByTypeDistribution.data"></apexchart>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span v-for="(label, idx) in gradeLabels" :key="label" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border"
                                  :class="[
                                      label === 'A' ? 'bg-green-100 text-green-800 border-green-300' : '',
                                      label === 'B' ? 'bg-blue-100 text-blue-800 border-blue-300' : '',
                                      label === 'C' ? 'bg-yellow-100 text-yellow-800 border-yellow-300' : '',
                        label === 'D' ? 'bg-red-100 text-red-800 border-red-300' : '',
                        label === 'Unrated' ? 'bg-gray-100 text-gray-800 border-gray-300' : ''
                                  ]">
                                {{ label }}: {{ gradePercents[idx] }}%
                            </span>
                        </div>
                    </ChartCard>
                </div>

                <!-- Threshold-wise Supplier Credit -->
                <ChartCard v-if="can?.thresholdCreditSummary" title="Threshold-wise Supplier Credit" :icon="Target" icon-color="text-indigo-600">
                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div class="grid grid-cols-1 gap-4">
                            <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm font-medium text-green-800">Within Threshold</p>
                                        <p class="text-2xl font-bold text-green-900">{{ props.thresholdCreditData?.withinThreshold || 0 }}</p>
                                    </div>
                                    <Target class="h-8 w-8 text-green-600" />
                                </div>
                            </div>
                            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm font-medium text-yellow-800">Near Threshold</p>
                                        <p class="text-2xl font-bold text-yellow-900">{{ props.thresholdCreditData?.nearThreshold || 0 }}</p>
                                    </div>
                                    <Target class="h-8 w-8 text-yellow-600" />
                                </div>
                            </div>
                            <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm font-medium text-red-800">Above Threshold</p>
                                        <p class="text-2xl font-bold text-red-900">{{ props.thresholdCreditData?.aboveThreshold || 0 }}</p>
                                    </div>
                                    <Target class="h-8 w-8 text-red-600" />
                                </div>
                            </div>
                        </div>
                        
                        <!-- Threshold Distribution Chart -->
                        <div class="flex-1">
                            <apexchart
                                type="donut"
                                height="300"
                                :options="thresholdChartOptions"
                                :series="thresholdChartSeries"
                            ></apexchart>
                        </div>
                    </div>
                </ChartCard>

                   <!-- Summary Cards -->
                <div v-if="can?.thresholdCreditSummary && (suppliers || []).length > 0" class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6">
                    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Suppliers</div>
                        <div class="mt-2 text-3xl font-bold text-gray-900 dark:text-gray-100">{{ (suppliers || []).length }}</div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Over Limit</div>
                        <div class="mt-2 text-3xl font-bold text-red-600 dark:text-red-400">
                            {{ (suppliers || []).filter(s => s.is_over_limit).length }}
                        </div>
                    </div>
                    <!-- <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500">মোট বকেয়া</div>
                        <div class="mt-2 text-3xl font-bold text-gray-900">
                            ৳ {{ formatCurrency((suppliers || []).reduce((sum, s) => sum + (s.current_due || 0), 0)) }}
                        </div>
                    </div> -->
                </div>

                <!-- Supplier type wise track -->
                <div v-if="can?.thresholdCreditSummary && (suppliers || []).length > 0" class="mt-6">
                    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Threshold-wise Supplier Credit</h3>
                                <span class="text-xs text-gray-500 dark:text-gray-400">Grouped by threshold_limit</span>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                                <div v-for="g in (thresholdGroups || [])" :key="g.threshold" class="p-4 bg-gray-50 dark:bg-gray-700 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="font-medium text-gray-900 dark:text-gray-100">Limit ৳{{ formatCurrency(g.threshold) }}</span>
                                        <span class="text-xs text-gray-600 dark:text-gray-300">{{ g.supplier_count }} suppliers</span>
                                    </div>
                                    <div class="w-full bg-gray-200 dark:bg-gray-600 rounded-full h-2 mb-2">
                                        <div
                                            class="h-2 rounded-full transition-all duration-500 ease-out"
                                            :class="g.avg_utilization >= 90 ? 'bg-red-600' : g.avg_utilization >= 70 ? 'bg-orange-500' : g.avg_utilization >= 50 ? 'bg-yellow-500' : 'bg-green-500'"
                                            :style="{ width: Math.min(g.avg_utilization, 100) + '%' }"
                                        ></div>
                                    </div>
                                    <div class="flex justify-between text-xs text-gray-600 dark:text-gray-300">
                                        <span class="flex items-center gap-1">
                                            <div
                                                class="w-2 h-2 rounded-full"
                                                :class="g.avg_utilization >= 90 ? 'bg-red-600' : g.avg_utilization >= 70 ? 'bg-orange-500' : g.avg_utilization >= 50 ? 'bg-yellow-500' : 'bg-green-500'"
                                            ></div>
                                            Avg usage {{ g.avg_utilization }}%
                                        </span>
                                        <span>৳{{ formatCurrency(g.total_due) }} total due</span>
                                    </div>
                                    <div class="mt-2 text-xs text-gray-600 dark:text-gray-300">
                                        <span class="font-medium" :class="g.over_limit_count ? 'text-red-600 dark:text-red-400' : 'text-gray-600 dark:text-gray-300'">
                                            {{ g.over_limit_count }} over limit
                                        </span>
                                    </div>
                                </div>
                                <div v-if="(thresholdGroups || []).length === 0" class="text-sm text-gray-500 dark:text-gray-400 p-4">
                                    No data
                                </div>
                            </div>
                        </div>
                    </div>
                </div>


                <!-- Recent Activities -->
                <ChartCard v-if="can?.recentActivities" title="Recent Activities" :icon="FileText" icon-color="text-gray-600">
                    <div class="divide-y divide-gray-200 -m-6">
                        <div v-for="activity in recentActivities" :key="activity.user + activity.time" class="p-6 hover:bg-gray-50 transition-colors">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="w-2 h-2 rounded-full" :class="{
                                        'bg-blue-500': activity.type === 'project',
                                        'bg-green-500': activity.type === 'attendance',
                                        'bg-yellow-500': activity.type === 'leave',
                                        'bg-purple-500': activity.type === 'expense',
                                        'bg-indigo-500': activity.type === 'department',
                                        'bg-gray-500': activity.type === 'profile',
                                        'bg-orange-500': activity.type === 'timesheet'
                                    }"></div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">
                                            <span class="font-semibold">{{ activity.user }}</span> {{ activity.action }}

                                        </p>
                                    </div>
                                </div>
                                <span class="text-xs text-gray-500">{{ activity.time }}</span>
                            </div>
                        </div>
                    </div>
                </ChartCard>

                <!-- No access message when nothing is visible -->
                <div
                    v-if="!can?.totalUsers && !can?.financialSummary && !can?.totalDocuments && !can?.debitCreditChart && !can?.unitChart && !can?.topSuppliers && !can?.supplierGrades && !can?.thresholdCreditSummary && !can?.recentActivities"
                    class="flex flex-col items-center justify-center py-24 text-gray-400"
                >
                    <LayoutDashboard :size="48" class="mb-4 opacity-30" />
                    <p class="text-lg font-medium">No dashboard widgets available</p>
                    <p class="text-sm mt-1">Contact your administrator to grant dashboard access.</p>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>

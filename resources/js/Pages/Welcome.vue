<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    FileText,
    BarChart3,
    CheckCircle,
    Package,
    Tags,
    Bell,
    ArrowRight,
} from 'lucide-vue-next';

defineProps({
    canLogin:      { type: Boolean },
    canRegister:   { type: Boolean },
    laravelVersion:{ type: String, required: true },
    phpVersion:    { type: String, required: true },
});

const page = usePage();
const siteName  = computed(() => page.props.appSettings?.site_name  || 'Yarn Management');

const features = [
    {
        icon: Package,
        color: 'bg-indigo-600',
        bg: 'from-indigo-50 to-indigo-100',
        title: 'Yarn Suppliers',
        desc: 'Maintain supplier master data used in booking and follow-up.',
    },
    {
        icon: FileText,
        color: 'bg-blue-600',
        bg: 'from-blue-50 to-blue-100',
        title: 'Bookings',
        desc: 'Create and manage yarn bookings with count, type (CF/CC), qty, rate, and dates.',
    },
    {
        icon: Tags,
        color: 'bg-green-600',
        bg: 'from-green-50 to-green-100',
        title: 'Counts & Fiber',
        desc: 'Keep yarn counts and fiber types consistent across all bookings.',
    },
    {
        icon: Bell,
        color: 'bg-rose-600',
        bg: 'from-rose-50 to-rose-100',
        title: 'Follow-up',
        desc: 'Track order vs received, balance, and follow-up status to avoid delays.',
    },
    {
        icon: BarChart3,
        color: 'bg-amber-600',
        bg: 'from-amber-50 to-amber-100',
        title: 'Analytics',
        desc: 'Count-wise and supplier-wise insights for decisions and planning.',
    },
];
</script>

<template>
    <Head :title="siteName + ' — Yarn Management'" />

    <div class="min-h-screen font-sans bg-gradient-to-br from-slate-50 via-white to-indigo-50 dark:from-gray-900 dark:via-gray-900 dark:to-gray-900">
        <nav class="bg-white/80 dark:bg-gray-800/80 backdrop-blur border-b border-gray-200 dark:border-gray-700 sticky top-0 z-50">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-0 flex justify-between items-center h-16">
                <div class="flex items-center gap-3">
                    <ApplicationLogo class="h-9 w-auto" />
                </div>

                <div v-if="canLogin" class="flex items-center gap-3">
                    <Link
                        :href="route('login')"
                        class="text-sm font-medium text-gray-600 dark:text-gray-300 hover:text-blue-700 dark:hover:text-blue-400 px-4 py-2 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/20 transition"
                    >
                        Log in
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="route('register')"
                        class="text-sm font-medium bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded-lg transition"
                    >
                        Register
                    </Link>
                </div>
            </div>
        </nav>

        <section class="relative overflow-hidden pt-14 pb-10 md:pt-20 md:pb-16 px-4">
            <div class="pointer-events-none absolute -top-28 -right-24 w-96 h-96 bg-indigo-100 rounded-full opacity-60 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-24 -left-24 w-80 h-80 bg-blue-100 rounded-full opacity-60 blur-3xl"></div>

            <div class="relative max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-2 gap-10 items-center">
                <div>
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 text-xs font-semibold mb-5">
                        <CheckCircle :size="13" /> {{ siteName }} · Yarn
                    </div>

                    <h1 class="text-4xl md:text-6xl font-extrabold text-gray-900 dark:text-gray-100 leading-tight">
                        Yarn Booking<br />
                        <span class="text-indigo-700 dark:text-indigo-400">Tracking</span>
                    </h1>

                    <p class="mt-5 text-base md:text-lg text-gray-600 dark:text-gray-400 max-w-xl">
                        Supplier → Booking → Follow-up → Receiving → Analytics — everything in one place.
                    </p>

                    <div class="mt-8 flex flex-col sm:flex-row gap-3">
                        <Link
                            v-if="canLogin"
                            :href="route('login')"
                            class="inline-flex items-center justify-center gap-2 bg-indigo-700 hover:bg-indigo-800 text-white px-7 py-3 rounded-xl text-sm font-semibold transition shadow-lg shadow-indigo-200 dark:shadow-indigo-900/40"
                        >
                            Go to Dashboard <ArrowRight :size="18" />
                        </Link>
                        <a
                            href="#core-modules"
                            class="inline-flex items-center justify-center gap-2 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-800 dark:text-gray-200 px-7 py-3 rounded-xl text-sm font-semibold transition border border-gray-200 dark:border-gray-700"
                        >
                            Core Modules
                        </a>
                    </div>
                </div>

                <div class="bg-white/90 dark:bg-gray-800/90 backdrop-blur rounded-3xl border border-gray-200 dark:border-gray-700 shadow-sm p-6">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-2xl bg-indigo-700 flex items-center justify-center">
                            <Package :size="22" class="text-white" />
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-gray-100 leading-tight">Quick Overview</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 leading-tight">Booking types · counts · follow-up</p>
                        </div>
                    </div>

                    <div class="mt-6 grid grid-cols-3 gap-3">
                        <div class="rounded-2xl border border-gray-200 dark:border-gray-700 p-3">
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">CF/CC</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">Booking Types</p>
                        </div>
                        <div class="rounded-2xl border border-gray-200 dark:border-gray-700 p-3">
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Count</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">Wise Insights</p>
                        </div>
                        <div class="rounded-2xl border border-gray-200 dark:border-gray-700 p-3">
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">24/7</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">Follow-up</p>
                        </div>
                    </div>

                    <div class="mt-6 space-y-2">
                        <div class="flex items-center justify-between rounded-2xl bg-indigo-50 dark:bg-indigo-900/20 px-4 py-3">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Booking Entry</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Step 01</p>
                        </div>
                        <div class="flex items-center justify-between rounded-2xl bg-blue-50 dark:bg-blue-900/20 px-4 py-3">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Follow-up</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Step 02</p>
                        </div>
                        <div class="flex items-center justify-between rounded-2xl bg-emerald-50 dark:bg-emerald-900/20 px-4 py-3">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Receiving</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Step 03</p>
                        </div>
                        <div class="flex items-center justify-between rounded-2xl bg-amber-50 dark:bg-amber-900/20 px-4 py-3">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Analytics</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Step 04</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="core-modules" class="py-14 md:py-16 px-4">
            <div class="max-w-7xl mx-auto">
                <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3 mb-8">
                    <div>
                        <h2 class="text-2xl md:text-3xl font-bold text-gray-900 dark:text-gray-100">Core Modules</h2>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-2 max-w-2xl">
                            The 5 core modules for yarn management — minimal sections, clean design.
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                    <div
                        v-for="f in features"
                        :key="f.title"
                        class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-md transition p-6"
                    >
                        <div :class="`w-11 h-11 ${f.color} rounded-xl flex items-center justify-center mb-4`">
                            <component :is="f.icon" :size="22" class="text-white" />
                        </div>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ f.title }}</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-2 leading-relaxed">{{ f.desc }}</p>
                    </div>
                </div>
            </div>
        </section>

        <footer class="bg-gray-900 text-white py-10 px-4">
            <div class="max-w-7xl mx-auto flex flex-col md:flex-row justify-between items-center gap-4">
                <div class="flex items-center gap-3">
                    <template v-if="siteLogo">
                        <img :src="`/storage/${siteLogo}`" :alt="siteName" class="h-8 w-auto object-contain brightness-0 invert" />
                    </template>
                    <template v-else>
                        <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center">
                            <Package :size="16" class="text-white" />
                        </div>
                    </template>
                    <div>
                        <p class="font-bold text-sm">{{ siteName }}</p>
                        <p class="text-[10px] text-gray-400 uppercase tracking-wider">Yarn Management ERP</p>
                    </div>
                </div>
                <p class="text-xs text-gray-500">Developed by ERP Team · {{ siteName }} · {{ new Date().getFullYear() }}</p>
            </div>
        </footer>

    </div>
</template>

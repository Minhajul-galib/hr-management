<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    Users,
    Building2,
    ShieldCheck,
    CalendarClock,
    BellRing,
    ClipboardCheck,
    ArrowRight,
    CheckCircle2,
    Lock,
    Workflow,
    Globe2,
} from 'lucide-vue-next';

defineProps({
    canLogin:      { type: Boolean },
    canRegister:   { type: Boolean },
    laravelVersion:{ type: String, required: true },
    phpVersion:    { type: String, required: true },
});

const page = usePage();
const siteName = computed(() => page.props.appSettings?.site_name || 'HRIS');

// Core modules — reflects the real, HR/admin-flavored capabilities of the system today.
const modules = [
    {
        icon: Users,
        color: 'bg-indigo-600',
        title: 'Employee & User Management',
        desc: 'A single directory for every user — profiles, contact details, and department assignment kept up to date.',
    },
    {
        icon: Building2,
        color: 'bg-blue-600',
        title: 'Departments & Org Structure',
        desc: 'Model your company’s departments and reporting lines so every employee sits in the right place.',
    },
    {
        icon: ShieldCheck,
        color: 'bg-emerald-600',
        title: 'Roles & Permissions',
        desc: 'Fine-grained, role-based access control so people see exactly the modules their job requires.',
    },
    {
        icon: CalendarClock,
        color: 'bg-amber-600',
        title: 'Calendar & Task Management',
        desc: 'Shared calendars, holidays, and to-dos keep teams aligned on what’s due and when.',
    },
    {
        icon: BellRing,
        color: 'bg-rose-600',
        title: 'Real-Time Notifications',
        desc: 'Instant alerts for approvals, assignments, and reminders — nothing important gets missed.',
    },
    {
        icon: ClipboardCheck,
        color: 'bg-purple-600',
        title: 'Activity & Audit Trail',
        desc: 'Every change is logged, giving admins full visibility and accountability across the system.',
    },
];

const highlights = [
    { icon: Lock, title: 'Secure by design', desc: 'Role-based permissions protect every module and action.' },
    { icon: Workflow, title: 'Built for your workflow', desc: 'Departments, approvals, and tasks — organized the way your company works.' },
    { icon: Globe2, title: 'One place for everything', desc: 'A single, centralized system your whole organization can rely on.' },
];
</script>

<template>
    <Head :title="siteName + ' — Human Resource Information System'" />

    <div class="min-h-screen font-sans bg-gradient-to-br from-slate-50 via-white to-indigo-50 dark:from-gray-900 dark:via-gray-900 dark:to-gray-900">
        <nav class="bg-white/80 dark:bg-gray-800/80 backdrop-blur border-b border-gray-200 dark:border-gray-700 sticky top-0 z-50">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-0 flex justify-between items-center h-16">
                <div class="flex items-center gap-3">
                    <ApplicationLogo class="h-9 w-auto" />
                    <span class="hidden sm:block font-semibold text-gray-800 dark:text-gray-100">{{ siteName }}</span>
                </div>

                <div v-if="canLogin" class="flex items-center gap-3">
                    <Link
                        :href="route('login')"
                        class="text-sm font-medium text-gray-600 dark:text-gray-300 hover:text-indigo-700 dark:hover:text-indigo-400 px-4 py-2 rounded-lg hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition"
                    >
                        Log in
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="route('register')"
                        class="text-sm font-medium bg-indigo-700 hover:bg-indigo-800 text-white px-4 py-2 rounded-lg transition"
                    >
                        Register
                    </Link>
                </div>
            </div>
        </nav>

        <section class="relative overflow-hidden pt-14 pb-10 md:pt-20 md:pb-16 px-4">
            <div class="pointer-events-none absolute -top-28 -right-24 w-96 h-96 bg-indigo-100 dark:bg-indigo-900/20 rounded-full opacity-60 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-24 -left-24 w-80 h-80 bg-blue-100 dark:bg-blue-900/20 rounded-full opacity-60 blur-3xl"></div>

            <div class="relative max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-2 gap-10 items-center">
                <div>
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 text-xs font-semibold mb-5">
                        <CheckCircle2 :size="13" /> {{ siteName }} · HR & Workforce Platform
                    </div>

                    <h1 class="text-4xl md:text-6xl font-extrabold text-gray-900 dark:text-gray-100 leading-tight">
                        People &amp; Workforce<br />
                        <span class="text-indigo-700 dark:text-indigo-400">Management, Simplified</span>
                    </h1>

                    <p class="mt-5 text-base md:text-lg text-gray-600 dark:text-gray-400 max-w-xl">
                        One secure home for your employees, departments, roles, and daily tasks — built for how your company actually runs.
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
                            Explore Modules
                        </a>
                    </div>
                </div>

                <div class="bg-white/90 dark:bg-gray-800/90 backdrop-blur rounded-3xl border border-gray-200 dark:border-gray-700 shadow-sm p-6">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-2xl bg-indigo-700 flex items-center justify-center">
                            <Users :size="22" class="text-white" />
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-gray-100 leading-tight">Your Organization, Organized</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 leading-tight">Employees · departments · roles · tasks</p>
                        </div>
                    </div>

                    <div class="mt-6 grid grid-cols-3 gap-3">
                        <div class="rounded-2xl border border-gray-200 dark:border-gray-700 p-3">
                            <Users :size="16" class="text-indigo-600 dark:text-indigo-400" />
                            <p class="text-xs font-semibold text-gray-900 dark:text-gray-100 mt-2">Employees</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">Directory</p>
                        </div>
                        <div class="rounded-2xl border border-gray-200 dark:border-gray-700 p-3">
                            <Building2 :size="16" class="text-blue-600 dark:text-blue-400" />
                            <p class="text-xs font-semibold text-gray-900 dark:text-gray-100 mt-2">Departments</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">Structure</p>
                        </div>
                        <div class="rounded-2xl border border-gray-200 dark:border-gray-700 p-3">
                            <ShieldCheck :size="16" class="text-emerald-600 dark:text-emerald-400" />
                            <p class="text-xs font-semibold text-gray-900 dark:text-gray-100 mt-2">Roles</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">Permissions</p>
                        </div>
                    </div>

                    <div class="mt-6 space-y-2">
                        <div class="flex items-center justify-between rounded-2xl bg-indigo-50 dark:bg-indigo-900/20 px-4 py-3">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Onboard employees</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Step 01</p>
                        </div>
                        <div class="flex items-center justify-between rounded-2xl bg-blue-50 dark:bg-blue-900/20 px-4 py-3">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Assign roles & departments</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Step 02</p>
                        </div>
                        <div class="flex items-center justify-between rounded-2xl bg-emerald-50 dark:bg-emerald-900/20 px-4 py-3">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Plan with calendar & tasks</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Step 03</p>
                        </div>
                        <div class="flex items-center justify-between rounded-2xl bg-amber-50 dark:bg-amber-900/20 px-4 py-3">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Stay informed with alerts</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Step 04</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="py-10 px-4 border-y border-gray-200 dark:border-gray-700 bg-white/60 dark:bg-gray-800/40">
            <div class="max-w-7xl mx-auto grid grid-cols-1 sm:grid-cols-3 gap-6">
                <div v-for="h in highlights" :key="h.title" class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-900/30 flex items-center justify-center flex-shrink-0">
                        <component :is="h.icon" :size="19" class="text-indigo-700 dark:text-indigo-400" />
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ h.title }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">{{ h.desc }}</p>
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
                            Everything a growing team needs to manage people, structure, and daily work — in one HR platform.
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                    <div
                        v-for="m in modules"
                        :key="m.title"
                        class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-md transition p-6"
                    >
                        <div :class="`w-11 h-11 ${m.color} rounded-xl flex items-center justify-center mb-4`">
                            <component :is="m.icon" :size="22" class="text-white" />
                        </div>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ m.title }}</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-2 leading-relaxed">{{ m.desc }}</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="px-4 pb-16 md:pb-20">
            <div class="max-w-7xl mx-auto rounded-3xl bg-gradient-to-r from-indigo-700 to-blue-700 px-6 py-10 md:px-12 md:py-14 text-center shadow-lg shadow-indigo-200 dark:shadow-indigo-900/40">
                <h2 class="text-2xl md:text-3xl font-bold text-white">Ready to bring your team into one system?</h2>
                <p class="mt-3 text-sm md:text-base text-indigo-100 max-w-xl mx-auto">
                    Sign in to manage employees, departments, roles, and tasks from a single dashboard.
                </p>
                <div class="mt-7">
                    <Link
                        v-if="canLogin"
                        :href="route('login')"
                        class="inline-flex items-center justify-center gap-2 bg-white hover:bg-gray-50 text-indigo-700 px-7 py-3 rounded-xl text-sm font-semibold transition"
                    >
                        Log in to {{ siteName }} <ArrowRight :size="18" />
                    </Link>
                </div>
            </div>
        </section>

        <footer class="bg-gray-900 text-white py-10 px-4">
            <div class="max-w-7xl mx-auto flex flex-col md:flex-row justify-between items-center gap-4">
                <div class="flex items-center gap-3">
                    <ApplicationLogo class="h-8 w-auto brightness-0 invert" />
                    <div>
                        <p class="font-bold text-sm">{{ siteName }}</p>
                        <p class="text-[10px] text-gray-400 uppercase tracking-wider">Human Resource Information System</p>
                    </div>
                </div>
                <p class="text-xs text-gray-500">Developed by Clifton Group ERP Team · {{ siteName }} · {{ new Date().getFullYear() }}</p>
            </div>
        </footer>
    </div>
</template>

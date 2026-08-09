<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    BookOpen,
    Rocket,
    ShieldCheck,
    ClipboardList,
    BarChart3,
    TrendingUp,
    Users,
    Factory,
    Calendar,
    CheckSquare,
    Package,
    UsersRound,
    Hash,
    Flame,
    Tags,
    FileDown,
    Upload,
    Bell,
    Settings,
} from 'lucide-vue-next';

const toc = [
    { id: 'quick-start', label: 'Quick Start', icon: Rocket },
    { id: 'permissions', label: 'Roles & Permissions', icon: ShieldCheck },
    { id: 'modules', label: 'Modules', icon: BookOpen },
    { id: 'import-export', label: 'Import / Export', icon: Upload },
    { id: 'tips', label: 'Tips', icon: Settings },
];

const modules = [
    {
        title: 'Procurement Follow Up',
        icon: ClipboardList,
        what: 'Track procurement follow-up records and keep status updated.',
        how: [
            'Open “Procurement Follow Up” from the sidebar.',
            'Use filters/search to find records quickly.',
            'Create or update rows based on your latest follow-up.',
        ],
        routeName: 'procurement-followup.index',
    },
    {
        title: 'Periodic View',
        icon: BarChart3,
        what: 'View periodic procurement metrics and charts for quick monitoring.',
        how: [
            'Open “Periodic View” from the sidebar.',
            'Select fiscal year or custom date range.',
            'Review charts and merchant-team breakdowns.',
        ],
        routeName: 'procurement-followup.periodic-view',
    },
    {
        title: 'PI Research & Analysis',
        icon: TrendingUp,
        what: 'Analyze PI and procurement performance using filters and charts.',
        how: [
            'Open “PI Research & Analysis” from the sidebar.',
            'Set filters (count, type, fiber, period) and review KPI + analysis.',
        ],
        routeName: 'procurement-followup.pi-wise-analysis',
    },
    {
        title: 'Supplier Order Breakdown',
        icon: Users,
        what: 'See supplier-wise order breakdown for quick comparison.',
        how: [
            'Open “Supplier Order Breakdown”.',
            'Review grouped data by supplier.',
        ],
        routeName: 'procurement-followup.supplier-breakdown',
    },
    {
        title: 'Masters: Yarn Suppliers',
        icon: Factory,
        what: 'Manage the yarn supplier master list used by the booking module.',
        how: [
            'Open “Suppliers” from “Masters”.',
            'Create / edit / archive suppliers as needed.',
        ],
        routeName: 'yarn-suppliers.index',
    },
    {
        title: 'Masters: Merchant Teams',
        icon: UsersRound,
        what: 'Maintain merchant teams for consistent data entry.',
        how: [
            'Open “Merchant Teams” from “Masters”.',
            'Add teams and keep naming consistent.',
        ],
        routeName: 'merchant-teams.index',
    },
    {
        title: 'Masters: Yarn Counts',
        icon: Hash,
        what: 'Maintain yarn count master data.',
        how: [
            'Open “Yarn Counts” from “Masters”.',
            'Add missing counts before creating bookings.',
        ],
        routeName: 'yarn-counts.index',
    },
    {
        title: 'Masters: Fiber Types',
        icon: Flame,
        what: 'Maintain fiber types used across booking records.',
        how: [
            'Open “Fiber Types” from “Masters”.',
            'Keep fiber names standardized.',
        ],
        routeName: 'fiber-types.index',
    },
    {
        title: 'Masters: Booking Types',
        icon: Tags,
        what: 'Maintain booking types for classification and reporting.',
        how: [
            'Open “Booking Types” from “Masters”.',
            'Add types and reuse them for consistency.',
        ],
        routeName: 'booking-types.index',
    },
    {
        title: 'Calendar & Holidays',
        icon: Calendar,
        what: 'Plan events and maintain holiday calendars.',
        how: [
            'Open “Calendar & Todo”.',
            'Add/update events and configure holidays if needed.',
        ],
        routeName: 'calendar.index',
    },
    {
        title: 'Todos',
        icon: CheckSquare,
        what: 'Track tasks and reminders (permission-based).',
        how: [
            'Open “Todos”.',
            'Create tasks, set priority/due date, and mark done.',
        ],
        routeName: 'todos.index',
    },
    {
        title: 'Units',
        icon: Package,
        what: 'Manage units used for master data and operational entries.',
        how: [
            'Open “Units”.',
            'Create or import units, then use them in relevant forms.',
        ],
        routeName: 'units.index',
    },
];

const importExport = [
    {
        title: 'Procurement Follow Up Import',
        icon: Upload,
        points: [
            'Go to “Procurement Follow Up” → Import.',
            'Download the template, fill it, preview, then confirm import.',
        ],
        routeName: 'procurement-followup.import.page',
    },
    {
        title: 'Procurement Follow Up Export',
        icon: FileDown,
        points: [
            'From “Procurement Follow Up”, use Export to download the latest dataset.',
        ],
        routeName: 'procurement-followup.export',
    },
    {
        title: 'Units Import',
        icon: Upload,
        points: [
            'Open “Units” and use Import.',
            'Download the template if you need the correct column format.',
        ],
        routeName: 'units.index',
    },
];
</script>

<template>
    <Head title="Documentation" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <BookOpen class="text-indigo-600 dark:text-indigo-400" :size="22" />
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Documentation
                </h2>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 space-y-8">
                <div class="rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-7 shadow-sm">
                    <div class="flex flex-col gap-3">
                        <div class="flex items-center gap-2">
                            <Rocket class="text-indigo-600 dark:text-indigo-400" :size="22" />
                            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">How to use this ERP system</h1>
                        </div>
                        <p class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed max-w-3xl">
                            This page explains what the software can do and how to use the main modules. If you do not see a menu item, your role may not have permission for it.
                        </p>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <a
                        v-for="item in toc"
                        :key="item.id"
                        :href="`#${item.id}`"
                        class="flex items-center gap-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 hover:shadow-md hover:border-indigo-300 dark:hover:border-indigo-600 transition-all duration-200"
                    >
                        <component :is="item.icon" :size="18" class="text-indigo-600 dark:text-indigo-400" />
                        <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                            {{ item.label }}
                        </span>
                    </a>
                </div>

                <section id="quick-start" class="scroll-mt-24">
                    <div class="flex items-center gap-2 mb-3">
                        <Rocket :size="18" class="text-indigo-600 dark:text-indigo-400" />
                        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Quick Start</h3>
                    </div>
                    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5">
                        <ol class="space-y-2 text-sm text-gray-700 dark:text-gray-300 list-decimal list-inside">
                            <li>Log in and open the sidebar menu on the left.</li>
                            <li>Start with “Procurement Follow Up” or “Bookings” depending on your work.</li>
                            <li>Keep master data (Suppliers / Teams / Counts / Fiber Types / Booking Types) updated before entering records.</li>
                            <li>Use “Calendar & Todo” and “Todos” for reminders and follow-ups.</li>
                        </ol>
                    </div>
                </section>

                <section id="permissions" class="scroll-mt-24">
                    <div class="flex items-center gap-2 mb-3">
                        <ShieldCheck :size="18" class="text-indigo-600 dark:text-indigo-400" />
                        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Roles & Permissions</h3>
                    </div>
                    <div class="grid gap-3 lg:grid-cols-3">
                        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5">
                            <div class="flex items-center gap-2 mb-2">
                                <ShieldCheck :size="16" class="text-emerald-600 dark:text-emerald-400" />
                                <div class="font-semibold text-gray-900 dark:text-gray-100">Access control</div>
                            </div>
                            <p class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed">
                                Menus and actions are permission-based. If you cannot see a module, request access from the system administrator.
                            </p>
                        </div>
                        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5">
                            <div class="flex items-center gap-2 mb-2">
                                <Users :size="16" class="text-blue-600 dark:text-blue-400" />
                                <div class="font-semibold text-gray-900 dark:text-gray-100">User management</div>
                            </div>
                            <p class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed">
                                Super System Admin can manage users and roles. Other roles will only see what they are allowed to use.
                            </p>
                        </div>
                        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5">
                            <div class="flex items-center gap-2 mb-2">
                                <Bell :size="16" class="text-amber-600 dark:text-amber-400" />
                                <div class="font-semibold text-gray-900 dark:text-gray-100">Notifications</div>
                            </div>
                            <p class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed">
                                Notifications show inside the app. Ensure your permissions and notification settings are correct.
                            </p>
                        </div>
                    </div>
                </section>

                <section id="modules" class="scroll-mt-24">
                    <div class="flex items-center gap-2 mb-3">
                        <BookOpen :size="18" class="text-indigo-600 dark:text-indigo-400" />
                        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Modules</h3>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="m in modules"
                            :key="m.title"
                            class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5 hover:shadow-md transition-shadow duration-200"
                        >
                            <div class="flex items-start gap-3">
                                <div class="mt-0.5">
                                    <component :is="m.icon" :size="18" class="text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <div class="font-semibold text-gray-900 dark:text-gray-100">
                                            {{ m.title }}
                                        </div>
                                        <Link
                                            v-if="m.routeName"
                                            :href="route(m.routeName)"
                                            class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline"
                                        >
                                            Open
                                        </Link>
                                    </div>
                                    <p class="text-sm text-gray-600 dark:text-gray-300 mt-1 leading-relaxed">
                                        {{ m.what }}
                                    </p>
                                    <ul class="mt-3 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                                        <li v-for="(step, i) in m.how" :key="i" class="flex gap-2">
                                            <span class="text-indigo-400 flex-shrink-0">▸</span>
                                            <span class="min-w-0">{{ step }}</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section id="import-export" class="scroll-mt-24">
                    <div class="flex items-center gap-2 mb-3">
                        <Upload :size="18" class="text-indigo-600 dark:text-indigo-400" />
                        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Import / Export</h3>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="card in importExport"
                            :key="card.title"
                            class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5"
                        >
                            <div class="flex items-center gap-2 mb-2">
                                <component :is="card.icon" :size="16" class="text-indigo-600 dark:text-indigo-400" />
                                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ card.title }}</div>
                            </div>
                            <ul class="space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                                <li v-for="(p, i) in card.points" :key="i" class="flex gap-2">
                                    <span class="text-indigo-400 flex-shrink-0">▸</span>
                                    <span class="min-w-0">{{ p }}</span>
                                </li>
                            </ul>
                            <div class="mt-3" v-if="card.routeName">
                                <Link :href="route(card.routeName)" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
                                    Go to page
                                </Link>
                            </div>
                        </div>
                    </div>
                </section>

                <section id="tips" class="scroll-mt-24">
                    <div class="flex items-center gap-2 mb-3">
                        <Settings :size="18" class="text-indigo-600 dark:text-indigo-400" />
                        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Tips</h3>
                    </div>
                    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5">
                        <ul class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
                            <li class="flex gap-2">
                                <span class="text-indigo-400 flex-shrink-0">▸</span>
                                <span>Keep master data updated first; it reduces errors during entry.</span>
                            </li>
                            <li class="flex gap-2">
                                <span class="text-indigo-400 flex-shrink-0">▸</span>
                                <span>If a menu is missing, your role does not have permission for it.</span>
                            </li>
                            <li class="flex gap-2">
                                <span class="text-indigo-400 flex-shrink-0">▸</span>
                                <span>Use exports before major updates to keep a backup snapshot of your current dataset.</span>
                            </li>
                        </ul>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

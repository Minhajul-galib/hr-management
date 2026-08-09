<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { Heart, Code2, Star, Globe, Cpu, Lightbulb, Database, Users, Wrench } from 'lucide-vue-next';

const people = [
    {
        name: 'Md. Joynul Abedin Chowdhury',
        role: 'Project Initiator & Decision Maker',
        department: 'Director',
        avatar: '🧠',
        highlight: false,
        contributions: [
            'Conceived the idea and took the main initiative to build this ERP system',
            'Defined the overall vision and scope of the project',
            'Provided key instructions and direction throughout development',
            'Made critical decisions on features, priorities, and timelines',
            'Drove the project forward with consistent leadership and decisive action',
        ],
    },
    {
        name: 'Minhajul Islam Galib',
        role: 'Full Developer',
        department: 'Engineering',
        avatar: '👨‍💻',
        highlight: true,
        contributions: [
            'Complete frontend development — all Vue 3 components, pages, layouts, dark-mode, responsive design',
            'Complete backend development — Laravel controllers, models, migrations, policies, observers',
            'RESTful API design and Inertia.js integration',
            'Role & permission system architecture',
            'Supplier management module — CRUD, ledger, tracking, limit alerts',
            'Document workflow engine with configurable status transitions',
            'Calendar & Todo system with reminders',
            'Real-time notifications via Laravel Echo & Pusher',
            'Excel import/export for suppliers, particulars, and units',
            'Dashboard analytics with metric cards and charts',
            'Authentication, impersonation, and profile management',
            'Load pressure handling — query optimisation, eager loading, pagination',
            'Deployment configuration and environment setup',
        ],
    },
    {
        name: 'Mr. Mansur',
        role: 'Given All Data',
        department: '',
        avatar: '📋',
        highlight: false,
        contributions: [
            'Provided all required data for the system',
            'Supported data collection and validation during development',
        ],
    },
    {
        name: 'Murad Wahed',
        role: 'Corporate Advisor',
        department: 'ERP / Operations',
        avatar: '🏛️',
        highlight: false,
        contributions: [
            'Shared valuable experience from previous ERP implementations',
            'Help to identified some potential difficulties and edge cases in a corporate environment',
            'Advised on best practices for workflow design suited to this organisation',
            'Provided concept on role distribution and access control strategy',
        ],
    },
];

const tools = [
    { name: 'Laravel 11', role: 'Backend Framework', avatar: '🔴', link: 'https://laravel.com', desc: 'PHP framework powering all server-side logic, routing, and ORM.' },
    { name: 'Vue 3', role: 'Frontend Framework', avatar: '💚', link: 'https://vuejs.org', desc: 'Reactive component-based UI framework.' },
    { name: 'Inertia.js', role: 'SPA Bridge', avatar: '⚡', link: 'https://inertiajs.com', desc: 'Connects Laravel and Vue without a separate API layer.' },
    { name: 'Tailwind CSS', role: 'UI Styling', avatar: '🎨', link: 'https://tailwindcss.com', desc: 'Utility-first CSS for rapid, consistent styling.' },
    { name: 'Lucide Icons', role: 'Icon Library', avatar: '✨', link: 'https://lucide.dev', desc: 'Clean, consistent SVG icons used throughout the UI.' },
    { name: 'Spatie Permission', role: 'Roles & Permissions', avatar: '🛡️', link: 'https://spatie.be/docs/laravel-permission', desc: 'Robust role and permission management for Laravel.' },
    { name: 'PostgreSQL', role: 'Database', avatar: '🐘', link: 'https://www.postgresql.org', desc: 'Primary relational database for transactional ERP data with strong integrity and performance.' },
    { name: 'Maatwebsite Excel', role: 'Excel Import / Export', avatar: '📊', link: 'https://laravel-excel.com', desc: 'Handles all Excel import and export operations.' },
    { name: 'SweetAlert2', role: 'Alert & Toast UI', avatar: '🔔', link: 'https://sweetalert2.github.io', desc: 'Beautiful, accessible alert dialogs and toast notifications.' },
    { name: 'Laravel Echo & Pusher', role: 'Real-time Events', avatar: '📡', link: 'https://laravel.com/docs/broadcasting', desc: 'Powers live notifications and real-time broadcasting.' },
    { name: 'Laravel Telescope', role: 'Debugging & Monitoring', avatar: '🔭', link: 'https://laravel.com/docs/telescope', desc: 'Elegant debug assistant for the Laravel framework.' },
    { name: 'ApexCharts', role: 'Charting Library', avatar: '📉', link: 'https://apexcharts.com', desc: 'Modern interactive charts used for analytics dashboards — bar, area, line, donut and more via vue3-apexcharts.' },
    { name: 'Vite', role: 'Frontend Build Tool', avatar: '⚙️', link: 'https://vitejs.dev', desc: 'Lightning-fast frontend bundler and dev server.' },
    { name: 'Ziggy', role: 'Laravel Routes in JS', avatar: '🗺️', link: 'https://github.com/tighten/ziggy', desc: 'Exposes Laravel named routes to JavaScript.' },
];

const futurePlans = [
    {
        title: 'Vendor Selection & Evaluation',
        icon: '🧾',
        desc: 'Add a structured vendor selection workflow with scoring, comparative analysis, and approval gates to ensure transparent procurement decisions.'
    },
    {
        title: 'AI Chatbot (Internal Assistant)',
        icon: '🤖',
        desc: 'An internal chatbot that can answer operational questions (documents, suppliers, ledgers, workflow status) using trusted system data and permissions.'
    },
    {
        title: 'AI Data Analysis & Forecasting',
        icon: '📈',
        desc: 'Trend analysis and forecasting for supplier due, spending patterns, risk/utilization, and cashflow signals—helping management plan proactively.'
    },
    
    {
        title: 'Recommendations Engine',
        icon: '💡',
        desc: 'Recommendations such as preferred vendors, early risk alerts, threshold tuning suggestions, and workflow bottleneck detection.'
    },
];

const year = new Date().getFullYear();
</script>

<template>
    <Head title="Credits & Acknowledgements" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Heart class="text-red-500 fill-red-400" :size="22" />
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Credits & Acknowledgements
                </h2>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 space-y-10">

                <!-- Hero -->
                <div class="rounded-2xl bg-gradient-to-br from-blue-600 via-indigo-600 to-purple-700 p-8 text-white shadow-xl text-center">
                    <div class="text-5xl mb-3">🙏</div>
                    <h1 class="text-2xl font-bold mb-2">Thank You to Everyone Involved</h1>
                    <p class="text-blue-100 max-w-2xl mx-auto text-sm leading-relaxed">
                        This ERP system is the result of hard work, clear vision, and great collaboration.
                        Below is a full account of every person's contribution and every tool that made it possible.
                    </p>
                </div>

                <!-- People Section -->
                <div>
                    <h2 class="text-base font-semibold text-gray-700 dark:text-gray-300 mb-4 flex items-center gap-2">
                        <Users :size="18" class="text-blue-500 dark:text-blue-400" />
                        People Behind This Project
                    </h2>

                    <div class="space-y-5">
                        <div
                            v-for="(person, i) in people"
                            :key="i"
                            :class="[
                                'rounded-xl border p-5 transition-shadow duration-200 hover:shadow-md',
                                person.highlight
                                    ? 'border-blue-300 bg-gradient-to-r from-blue-50 to-indigo-50 dark:border-blue-700 dark:from-blue-900/30 dark:to-indigo-900/20'
                                    : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'
                            ]"
                        >
                            <div class="flex items-start gap-4">
                                <div class="text-4xl select-none flex-shrink-0 mt-0.5">{{ person.avatar }}</div>

                                <div class="flex-1 min-w-0">
                                    <div class="flex flex-wrap items-center gap-2 mb-0.5">
                                        <h3 class="font-bold text-gray-900 dark:text-gray-100 text-base">
                                            {{ person.name }}
                                        </h3>
                                        <span
                                            v-if="person.highlight"
                                            class="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded-full bg-blue-600 text-white"
                                        >
                                            <Star :size="10" class="fill-white" /> Lead Developer
                                        </span>
                                    </div>

                                    <div class="flex flex-wrap gap-2 mb-3">
                                        <span class="text-xs font-medium text-blue-600 dark:text-blue-400">{{ person.role }}</span>
                                        <span class="text-xs text-gray-400 dark:text-gray-500">·</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ person.department }}</span>
                                    </div>

                                    <ul class="space-y-1.5">
                                        <li
                                            v-for="(item, j) in person.contributions"
                                            :key="j"
                                            class="flex items-start gap-2 text-xs text-gray-600 dark:text-gray-300 leading-relaxed"
                                        >
                                            <span class="mt-0.5 text-blue-400 flex-shrink-0">▸</span>
                                            {{ item }}
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tools Section -->
                <div>
                    <h2 class="text-base font-semibold text-gray-700 dark:text-gray-300 mb-4 flex items-center gap-2">
                        <Wrench :size="18" class="text-indigo-500 dark:text-indigo-400" />
                        Tools & Technologies Used
                    </h2>

                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-4 leading-relaxed">
                        PostgreSQL is listed here because it is the system’s reliable source of truth for all ERP transactions. This strong, structured foundation keeps the platform
                        future-ready for AI use cases—such as vector-based tracing/search across documents and suppliers—and can later power an internal chatbot to answer operational
                        questions directly from trusted system data.
                    </p>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <a
                            v-for="(tool, i) in tools"
                            :key="i"
                            :href="tool.link"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="flex items-start gap-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 hover:shadow-md hover:border-blue-300 dark:hover:border-blue-600 transition-all duration-200 group"
                        >
                            <div class="text-2xl select-none flex-shrink-0">{{ tool.avatar }}</div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-1.5">
                                    <span class="text-sm font-semibold text-gray-900 dark:text-gray-100 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">
                                        {{ tool.name }}
                                    </span>
                                    <Globe :size="11" class="text-gray-400 dark:text-gray-500 group-hover:text-blue-400 transition-colors flex-shrink-0" />
                                </div>
                                <p class="text-[11px] font-medium text-blue-500 dark:text-blue-400 mt-0.5">{{ tool.role }}</p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">{{ tool.desc }}</p>
                            </div>
                        </a>
                    </div>

                    <!-- Future Plan Section -->
                    <div class="mt-8">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 flex items-center gap-2">
                            <Lightbulb :size="16" class="text-amber-500 dark:text-amber-400" />
                            Future Plan (Recommendations)
                        </h3>

                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <div
                                v-for="(item, i) in futurePlans"
                                :key="i"
                                class="flex items-start gap-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 hover:shadow-md hover:border-amber-300 dark:hover:border-amber-600 transition-all duration-200"
                            >
                                <div class="text-2xl select-none flex-shrink-0">{{ item.icon }}</div>
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                        {{ item.title }}
                                    </div>
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">
                                        {{ item.desc }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="text-center text-sm text-gray-500 dark:text-gray-400 pb-4">
                    <div class="flex items-center justify-center gap-1.5">
                        <Code2 :size="15" />
                        <span>Built with</span>
                        <Heart :size="14" class="text-red-500 fill-red-400" />
                        <span>by Clifton Group ERP &copy; {{ year }}</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                        All third-party libraries and tools are the property of their respective owners.
                    </p>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>

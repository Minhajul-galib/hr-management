<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { AlertTriangle, ArrowLeft, Home, LogIn, RefreshCw, ShieldAlert } from 'lucide-vue-next';

const props = defineProps({
    status: {
        type: Number,
        required: true,
    },
});

const page = usePage();
const isLoggedIn = computed(() => Boolean(page.props.auth?.user));

const details = computed(() => {
    const map = {
        401: {
            badge: '401',
            title: 'User is not logged in',
            description: 'Your session is missing or you need to sign in first to access this page.',
            tone: 'amber',
            primaryLabel: 'Go To Login',
            primaryHref: route('login'),
            primaryIcon: LogIn,
        },
        403: {
            badge: '403',
            title: 'Access Forbidden',
            description: 'You do not have permission to view this page or perform this action.',
            tone: 'rose',
            primaryLabel: 'Back To Dashboard',
            primaryHref: isLoggedIn.value ? route('dashboard') : route('login'),
            primaryIcon: ShieldAlert,
        },
        404: {
            badge: '404',
            title: 'Page Not Found',
            description: 'The page you are looking for does not exist or may have been moved.',
            tone: 'sky',
            primaryLabel: 'Go Home',
            primaryHref: isLoggedIn.value ? route('dashboard') : '/',
            primaryIcon: Home,
        },
        419: {
            badge: '419',
            title: 'Page Expired',
            description: 'Your page/session expired. Please refresh the page and try again.',
            tone: 'amber',
            primaryLabel: 'Reload Page',
            primaryHref: null,
            primaryIcon: RefreshCw,
        },
        429: {
            badge: '429',
            title: 'Too Many Requests',
            description: 'Too many requests were sent in a short time. Please wait a bit and try again.',
            tone: 'violet',
            primaryLabel: 'Back To Safety',
            primaryHref: isLoggedIn.value ? route('dashboard') : '/',
            primaryIcon: Home,
        },
        500: {
            badge: '500',
            title: 'Server Error',
            description: 'Something went wrong on the server side. Please try again in a moment.',
            tone: 'rose',
            primaryLabel: 'Reload Page',
            primaryHref: null,
            primaryIcon: RefreshCw,
        },
        503: {
            badge: '503',
            title: 'Service Unavailable',
            description: 'The system is temporarily unavailable, possibly due to maintenance.',
            tone: 'slate',
            primaryLabel: 'Reload Page',
            primaryHref: null,
            primaryIcon: RefreshCw,
        },
    };

    return map[props.status] ?? {
        badge: String(props.status),
        title: 'Something Went Wrong',
        description: 'An unexpected problem occurred while loading this page.',
        tone: 'slate',
        primaryLabel: 'Go Home',
        primaryHref: isLoggedIn.value ? route('dashboard') : '/',
        primaryIcon: Home,
    };
});

const toneClasses = computed(() => ({
    rose: {
        shell: 'from-rose-50 via-white to-white',
        icon: 'bg-rose-100 text-rose-600 border-rose-200 dark:bg-rose-900/40 dark:text-rose-300',
        badge: 'bg-rose-100 text-rose-700 border-rose-200 dark:bg-rose-900/40 dark:text-rose-300',
        button: 'bg-rose-600 hover:bg-rose-500 focus:ring-rose-500',
    },
    amber: {
        shell: 'from-amber-50 via-white to-white',
        icon: 'bg-amber-100 text-amber-600 border-amber-200 dark:bg-amber-900/40 dark:text-amber-300',
        badge: 'bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-900/40 dark:text-amber-300',
        button: 'bg-amber-600 hover:bg-amber-500 focus:ring-amber-500',
    },
    sky: {
        shell: 'from-sky-50 via-white to-white',
        icon: 'bg-sky-100 text-sky-600 border-sky-200 dark:bg-sky-900/40 dark:text-sky-300',
        badge: 'bg-sky-100 text-sky-700 border-sky-200 dark:bg-sky-900/40 dark:text-sky-300',
        button: 'bg-sky-600 hover:bg-sky-500 focus:ring-sky-500',
    },
    violet: {
        shell: 'from-violet-50 via-white to-white',
        icon: 'bg-violet-100 text-violet-600 border-violet-200 dark:bg-violet-900/40 dark:text-violet-300',
        badge: 'bg-violet-100 text-violet-700 border-violet-200 dark:bg-violet-900/40 dark:text-violet-300',
        button: 'bg-violet-600 hover:bg-violet-500 focus:ring-violet-500',
    },
    slate: {
        shell: 'from-slate-50 via-white to-white',
        icon: 'bg-slate-100 text-slate-600 border-slate-200 dark:bg-slate-700/60 dark:text-slate-300',
        badge: 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-700/60 dark:text-slate-300',
        button: 'bg-slate-700 hover:bg-slate-600 focus:ring-slate-500',
    },
}[details.value.tone]));

function handlePrimaryAction() {
    if (details.value.primaryHref) return;
    window.location.reload();
}
</script>

<template>
    <Head :title="`${details.title} (${status})`" />

    <div class="min-h-screen bg-gray-100 dark:bg-gray-950 px-4 py-8 sm:px-6 lg:px-8">
        <div class="mx-auto flex min-h-[calc(100vh-4rem)] max-w-3xl items-center justify-center">
            <div class="w-full overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-900">
                <div class="bg-gradient-to-br px-6 py-8 sm:px-8 sm:py-10 dark:from-gray-900 dark:via-gray-900 dark:to-gray-900" :class="toneClasses.shell">
                    <div class="flex flex-col gap-6 sm:flex-row sm:items-start">
                        <div class="inline-flex h-16 w-16 items-center justify-center rounded-2xl border shadow-sm dark:border-gray-700" :class="toneClasses.icon">
                            <AlertTriangle :size="28" />
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="mb-3 flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-bold tracking-[0.18em]" :class="toneClasses.badge">
                                    {{ details.badge }}
                                </span>
                                <span class="inline-flex items-center rounded-full border border-gray-200 bg-white/80 px-3 py-1 text-xs font-medium text-gray-500 dark:border-gray-700 dark:bg-gray-800/70 dark:text-gray-300">
                                    System Notice
                                </span>
                            </div>

                            <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-gray-100 sm:text-3xl">
                                {{ details.title }}
                            </h1>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-600 dark:text-gray-300 sm:text-base">
                                {{ details.description }}
                            </p>

                            <div class="mt-6 flex flex-wrap items-center gap-3">
                                <Link
                                    v-if="details.primaryHref"
                                    :href="details.primaryHref"
                                    class="inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-gray-900"
                                    :class="toneClasses.button"
                                >
                                    <component :is="details.primaryIcon" :size="16" />
                                    {{ details.primaryLabel }}
                                </Link>
                                <button
                                    v-else
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-gray-900"
                                    :class="toneClasses.button"
                                    @click="handlePrimaryAction"
                                >
                                    <component :is="details.primaryIcon" :size="16" />
                                    {{ details.primaryLabel }}
                                </button>

                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                    @click="window.history.back()"
                                >
                                    <ArrowLeft :size="16" />
                                    Go Back
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="border-t border-gray-200 px-6 py-5 dark:border-gray-800 sm:px-8">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-800 dark:bg-gray-800/70">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-gray-400 dark:text-gray-500">Status</div>
                            <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ status }}</div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-800 dark:bg-gray-800/70">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-gray-400 dark:text-gray-500">Session</div>
                            <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ isLoggedIn ? 'Logged In' : 'Guest User' }}</div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-800 dark:bg-gray-800/70">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-gray-400 dark:text-gray-500">Action</div>
                            <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">Please retry safely</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

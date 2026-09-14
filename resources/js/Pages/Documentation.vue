<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { BookOpen } from 'lucide-vue-next';
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
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Printer } from 'lucide-vue-next';
import AuditForm from './Partials/AuditForm.vue';

defineProps({
    audit: { type: Object, required: true },
    units: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    template: { type: Object, required: true },
});
</script>

<template>
    <Head :title="`Edit ${audit.reference_no}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="flex items-center gap-2">
                        {{ audit.reference_no }}
                        <span class="rounded-full border border-gray-300 bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200">Draft</span>
                    </h2>
                    <p>{{ audit.facility_name }}</p>
                </div>
                <div class="flex items-center gap-4 self-start sm:self-auto">
                    <a
                        :href="route('audits.print', audit.id)"
                        target="_blank"
                        class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-100"
                    >
                        <Printer :size="16" /> Print preview
                    </a>
                    <Link
                        :href="route('audits.show', audit.id)"
                        class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-100"
                    >
                        <ArrowLeft :size="16" /> Back to report
                    </Link>
                </div>
            </div>
        </template>

        <AuditForm :audit="audit" :units="units" :users="users" :template="template" />
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Info } from 'lucide-vue-next';
import AuditForm from './Partials/AuditForm.vue';

defineProps({
    audit: { type: Object, required: true },
    followUpOf: { type: Object, default: null },
    units: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    template: { type: Object, required: true },
});
</script>

<template>
    <Head title="New Internal Audit" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2>{{ followUpOf ? 'New Follow-up Audit' : 'New Internal Audit' }}</h2>
                    <p>Internal Audit Report &amp; Corrective Action Plan</p>
                </div>
                <Link
                    :href="route('audits.index')"
                    class="inline-flex items-center gap-1.5 self-start text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-100 sm:self-auto"
                >
                    <ArrowLeft :size="16" /> Back to audits
                </Link>
            </div>
        </template>

        <div v-if="followUpOf" class="mx-auto mt-6 max-w-6xl px-4">
            <div class="flex items-start gap-2 rounded-md border border-sky-200 bg-sky-50 p-3 text-sm text-sky-800 dark:border-sky-800 dark:bg-sky-900/30 dark:text-sky-300">
                <Info :size="18" class="mt-0.5 shrink-0" />
                <span>
                    Pre-filled from <strong>{{ followUpOf.reference_no }}</strong> ({{ followUpOf.facility_name }}), including its findings that are still open.
                    Close the ones the factory has fixed and add anything new.
                </span>
            </div>
        </div>

        <AuditForm :audit="audit" :units="units" :users="users" :template="template" />
    </AuthenticatedLayout>
</template>

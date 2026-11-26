<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { CheckCircle2, Mail, Briefcase } from 'lucide-vue-next';

// Shown to a newly registered user (role: Applicant) in place of the dashboard.
defineProps({
    applicant: {
        type: Object,
        required: true, // { name, email, designation|null, designation_category|null }
    },
});
</script>

<template>
    <Head title="Welcome" />

    <AuthenticatedLayout>
        <div class="flex justify-center px-4 py-10 sm:px-6 lg:px-8">
            <div class="w-full max-w-xl">
                <div class="rounded-2xl border border-gray-200 bg-white p-8 text-center shadow-lg dark:border-gray-700 dark:bg-gray-800">
                    <div class="mx-auto mb-5 inline-flex h-16 w-16 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
                        <CheckCircle2 class="h-9 w-9 text-green-600 dark:text-green-400" />
                    </div>

                    <h1 class="mb-2 text-2xl font-bold text-gray-900 dark:text-gray-100">
                        Thank you for registering, {{ applicant.name }}!
                    </h1>
                    <p class="text-lg font-medium text-blue-600 dark:text-blue-400">
                        Welcome to Clifton Group HRIS
                    </p>

                    <p class="mt-5 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                        Please be aware that your registration has been recorded with the
                        Development Authorities under the email and designation below.
                    </p>

                    <dl class="mt-6 space-y-3 rounded-xl bg-gray-50 p-4 text-left dark:bg-gray-900/60">
                        <div class="flex items-start gap-3">
                            <Mail class="mt-0.5 h-5 w-5 shrink-0 text-gray-400" />
                            <div class="min-w-0">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Email</dt>
                                <dd class="break-all text-sm font-medium text-gray-900 dark:text-gray-100">{{ applicant.email }}</dd>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <Briefcase class="mt-0.5 h-5 w-5 shrink-0 text-gray-400" />
                            <div class="min-w-0">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Designation</dt>
                                <dd class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                    {{ applicant.designation || 'Not specified' }}
                                    <span v-if="applicant.designation && applicant.designation_category" class="font-normal text-gray-500 dark:text-gray-400">
                                        · {{ applicant.designation_category }}
                                    </span>
                                </dd>
                            </div>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

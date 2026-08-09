<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { computed } from 'vue';

const props = defineProps({
    unit: Object,
});

const page = usePage();

const canView = computed(() => {
    const permissions = page.props.auth?.permissions || [];
    return permissions.includes('manage units') || permissions.includes('view units');
});

const canEdit = computed(() => {
    const permissions = page.props.auth?.permissions || [];
    return permissions.includes('manage units') || permissions.includes('edit units');
});

const getStatusBadgeClass = (isActive) => {
    return isActive 
        ? 'bg-green-100 text-green-800' 
        : 'bg-red-100 text-red-800';
};
</script>

<template>
    <Head :title="`Unit - ${unit.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">Unit Details</h2>
                <div class="flex gap-2">
                    <Link :href="route('units.index')">
                        <PrimaryButton>Back to List</PrimaryButton>
                    </Link>
                    <Link v-if="canEdit" :href="route('units.edit', unit.id)">
                        <PrimaryButton>Edit Unit</PrimaryButton>
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-4 lg:px-4 xl:max-w-[1440px] 2xl:max-w-[1600px]">
                <div v-if="!canView" class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 text-center">
                    <div class="text-red-500 mb-4">
                        <svg class="mx-auto h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Access Denied</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">You do not have permission to view units.</p>
                    <div class="mt-6">
                        <Link :href="route('units.index')">
                            <SecondaryButton>Back to Units</SecondaryButton>
                        </Link>
                    </div>
                </div>

                <div v-else class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Basic Information</h3>
                                
                                <div class="space-y-3">
                                    <div>
                                        <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Unit Code</label>
                                        <p class="mt-1 text-gray-900 dark:text-gray-100">{{ unit.unit_code }}</p>
                                    </div>
                                    
                                    <div>
                                        <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Unit Name</label>
                                        <p class="mt-1 text-gray-900 dark:text-gray-100">{{ unit.name }}</p>
                                    </div>
                                    
                                    <div>
                                        <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Location</label>
                                        <p class="mt-1 text-gray-900 dark:text-gray-100">{{ unit.location || '-' }}</p>
                                    </div>
                                    
                                    <div>
                                        <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Status</label>
                                        <p class="mt-1">
                                            <span :class="getStatusBadgeClass(unit.is_active)" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium">
                                                {{ unit.is_active ? 'Active' : 'Inactive' }}
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Contact Information</h3>
                                
                                <div class="space-y-3">
                                    <div>
                                        <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Contact Person</label>
                                        <p class="mt-1 text-gray-900 dark:text-gray-100">{{ unit.contact_person || '-' }}</p>
                                    </div>
                                    
                                    <div>
                                        <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Phone Number</label>
                                        <p class="mt-1 text-gray-900 dark:text-gray-100">{{ unit.phone_number || '-' }}</p>
                                    </div>
                                    
                                    <div>
                                        <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Email</label>
                                        <p class="mt-1 text-gray-900 dark:text-gray-100">{{ unit.email || '-' }}</p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">System Information</h3>
                                
                                <div class="space-y-3">
                                    <div>
                                        <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Created By</label>
                                        <p class="mt-1 text-gray-900 dark:text-gray-100">{{ unit.created_by?.name || '-' }}</p>
                                    </div>
                                    
                                    <div>
                                        <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Created At</label>
                                        <p class="mt-1 text-gray-900 dark:text-gray-100">{{ new Date(unit.created_at).toLocaleString() }}</p>
                                    </div>
                                    
                                    <div>
                                        <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Last Updated</label>
                                        <p class="mt-1 text-gray-900 dark:text-gray-100">{{ new Date(unit.updated_at).toLocaleString() }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

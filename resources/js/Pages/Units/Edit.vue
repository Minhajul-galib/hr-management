<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    unit: Object,
});

const page = usePage();

const canEdit = computed(() => {
    const permissions = page.props.auth?.permissions || [];
    return permissions.includes('manage units') || permissions.includes('edit units');
});

const form = useForm({
    unit_code: props.unit.unit_code,
    name: props.unit.name,
    location: props.unit.location || '',
    contact_person: props.unit.contact_person || '',
    phone_number: props.unit.phone_number || '',
    email: props.unit.email || '',
    is_active: props.unit.is_active,
});

const submit = () => {
    form.put(route('units.update', props.unit.id));
};
</script>

<template>
    <Head title="Edit Unit" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">Edit Unit</h2>
                <Link :href="route('units.index')">
                    <SecondaryButton>Back to List</SecondaryButton>
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-4 lg:px-4">
                <div v-if="!canEdit" class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 text-center">
                    <div class="text-red-500 mb-4">
                        <svg class="mx-auto h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Access Denied</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">You do not have permission to edit units.</p>
                    <div class="mt-6">
                        <Link :href="route('units.index')">
                            <SecondaryButton>Back to Units</SecondaryButton>
                        </Link>
                    </div>
                </div>

                <div v-else class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <form @submit.prevent="submit" class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <InputLabel for="unit_code" value="Unit Code *" />
                                <TextInput id="unit_code" v-model="form.unit_code" type="text" class="mt-1 block w-full" placeholder="e.g. F02-PDD" required />
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Unique. Letters, numbers and hyphens, with at least one number (e.g. F02-PDD).</p>
                                <InputError :message="form.errors.unit_code" class="mt-2" />
                            </div>

                            <div>
                                <InputLabel for="name" value="Unit Name *" />
                                <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" required />
                                <InputError :message="form.errors.name" class="mt-2" />
                            </div>

                            <div>
                                <InputLabel for="location" value="Location" />
                                <TextInput id="location" v-model="form.location" type="text" class="mt-1 block w-full" />
                                <InputError :message="form.errors.location" class="mt-2" />
                            </div>

                            <div>
                                <InputLabel for="contact_person" value="Contact Person" />
                                <TextInput id="contact_person" v-model="form.contact_person" type="text" class="mt-1 block w-full" />
                                <InputError :message="form.errors.contact_person" class="mt-2" />
                            </div>

                            <div>
                                <InputLabel for="phone_number" value="Phone Number" />
                                <TextInput id="phone_number" v-model="form.phone_number" type="text" class="mt-1 block w-full" />
                                <InputError :message="form.errors.phone_number" class="mt-2" />
                            </div>

                            <div>
                                <InputLabel for="email" value="Email" />
                                <TextInput id="email" v-model="form.email" type="email" class="mt-1 block w-full" />
                                <InputError :message="form.errors.email" class="mt-2" />
                            </div>

                            <div>
                                <label class="flex items-center">
                                    <input type="checkbox" v-model="form.is_active" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                                    <span class="ml-2 text-sm text-gray-600 dark:text-gray-300">Active</span>
                                </label>
                            </div>
                        </div>

                        <div class="mt-6 flex justify-end gap-3">
                            <Link :href="route('units.index')">
                                <SecondaryButton type="button">Cancel</SecondaryButton>
                            </Link>
                            <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                                Update Unit
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

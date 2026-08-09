<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { User, Mail, Shield, Calendar } from 'lucide-vue-next';
import { computed } from 'vue';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
});

// Get user roles
const userRoles = computed(() => {
    if (user.roles && user.roles.length > 0) {
        return user.roles.map(role => role.name).join(', ');
    }
    return 'No Role Assigned';
});

// Format date
const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
};
</script>

<template>
    <section>
        <!-- Header with gradient -->
        <header class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-t-lg p-6 -m-6 mb-6">
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 rounded-full bg-white/20 backdrop-blur-sm flex items-center justify-center border-2 border-white/30">
                    <User :size="32" class="text-white" />
                </div>
                <div>
                    <h2 class="text-2xl font-bold text-white">
                        Profile Information
                    </h2>
                    <p class="mt-1 text-sm text-white/90">
                        Manage your account details and preferences
                    </p>
                </div>
            </div>
        </header>

        <!-- User Info Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <!-- Role Card -->
            <div class="bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-950/40 dark:to-indigo-950/30 rounded-lg p-4 border border-blue-200 dark:border-blue-900/60">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-blue-500 flex items-center justify-center">
                        <Shield :size="20" class="text-white" />
                    </div>
                    <div>
                        <p class="text-xs text-gray-600 dark:text-gray-400 font-medium">Role</p>
                        <p class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ userRoles }}</p>
                    </div>
                </div>
            </div>

            <!-- Member Since Card -->
            <div class="bg-gradient-to-br from-purple-50 to-pink-50 dark:from-purple-950/40 dark:to-pink-950/30 rounded-lg p-4 border border-purple-200 dark:border-purple-900/60">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-purple-500 flex items-center justify-center">
                        <Calendar :size="20" class="text-white" />
                    </div>
                    <div>
                        <p class="text-xs text-gray-600 dark:text-gray-400 font-medium">Member Since</p>
                        <p class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ formatDate(user.created_at) }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form -->
        <form
            @submit.prevent="form.patch(route('profile.update'))"
            class="space-y-6"
        >
            <!-- Name Field -->
            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4 hover:border-indigo-300 dark:hover:border-indigo-600 transition-colors">
                <div class="flex items-center gap-2 mb-2">
                    <User :size="18" class="text-indigo-600 dark:text-indigo-400" />
                    <InputLabel for="name" value="Full Name" class="font-semibold" />
                </div>

                <TextInput
                    id="name"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                />

                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <!-- Email Field -->
            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4 hover:border-indigo-300 dark:hover:border-indigo-600 transition-colors">
                <div class="flex items-center gap-2 mb-2">
                    <Mail :size="18" class="text-indigo-600 dark:text-indigo-400" />
                    <InputLabel for="email" value="Email Address" class="font-semibold" />
                </div>

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <!-- Email Verification Notice -->
            <div v-if="mustVerifyEmail && user.email_verified_at === null" class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4">
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-full bg-yellow-500 flex items-center justify-center flex-shrink-0">
                        <Mail :size="16" class="text-white" />
                    </div>
                    <div class="flex-1">
                        <p class="text-sm text-gray-800 dark:text-gray-200 font-medium mb-1">
                            Email Verification Required
                        </p>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                            Your email address is unverified.
                        </p>
                        <Link
                            :href="route('verification.send')"
                            method="post"
                            as="button"
                            class="inline-flex items-center px-3 py-1.5 bg-yellow-500 text-white text-sm font-medium rounded-md hover:bg-yellow-600 transition-colors"
                        >
                            Resend Verification Email
                        </Link>

                        <div
                            v-show="status === 'verification-link-sent'"
                            class="mt-2 text-sm font-medium text-green-600 dark:text-green-400 flex items-center gap-2"
                        >
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            Verification link sent successfully!
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex items-center gap-4 pt-4">
                <PrimaryButton 
                    :disabled="form.processing"
                    class="px-6 py-2.5"
                >
                    <span v-if="!form.processing">Save Changes</span>
                    <span v-else class="flex items-center gap-2">
                        <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Saving...
                    </span>
                </PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out duration-300"
                    enter-from-class="opacity-0 transform scale-95"
                    enter-to-class="opacity-100 transform scale-100"
                    leave-active-class="transition ease-in-out duration-300"
                    leave-from-class="opacity-100 transform scale-100"
                    leave-to-class="opacity-0 transform scale-95"
                >
                    <div
                        v-if="form.recentlySuccessful"
                        class="flex items-center gap-2 px-4 py-2 bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 rounded-lg border border-green-200 dark:border-green-800"
                    >
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                        <span class="text-sm font-medium">Profile updated successfully!</span>
                    </div>
                </Transition>
            </div>
        </form>
    </section>
</template>

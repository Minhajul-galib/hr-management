<script setup>
import InputError from '@/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Mail, Lock, Eye, EyeOff, Shield } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps({
    email: {
        type: String,
        required: true,
    },
    token: {
        type: String,
        required: true,
    },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const showPassword = ref(false);
const showConfirmPassword = ref(false);

const submit = () => {
    form.post(route('password.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head title="Reset Password" />
    
    <div class="min-h-screen bg-gradient-to-br from-slate-50 via-blue-50 to-indigo-100 dark:from-gray-900 dark:via-gray-900 dark:to-gray-950 flex items-center justify-center p-4">
        <div class="w-full max-w-md">
            <!-- Logo & Header -->
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-r from-blue-600 to-indigo-600 rounded-2xl mb-4 shadow-lg">
                    <Shield class="w-8 h-8 text-white" />
                </div>
                <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100 mb-2">Reset Password</h1>
                <p class="text-gray-600 dark:text-gray-400">Create a new secure password for your account</p>
            </div>

            <!-- Main Card -->
            <div class="bg-white/80 dark:bg-gray-800/80 backdrop-blur-sm rounded-2xl shadow-xl border border-white/20 dark:border-gray-700/50 p-8">
                <form @submit.prevent="submit" class="space-y-6">
                    <!-- Email Field (Read-only) -->
                    <div class="space-y-2">
                        <label for="email" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Email Address</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <Mail class="h-5 w-5 text-gray-400" />
                            </div>
                            <input
                                id="email"
                                type="email"
                                v-model="form.email"
                                required
                                autofocus
                                autocomplete="username"
                                readonly
                                class="block w-full pl-10 pr-3 py-3 border border-gray-300 dark:border-gray-600 rounded-xl text-gray-900 dark:text-gray-100 bg-gray-50 dark:bg-gray-900 cursor-not-allowed transition-all duration-200"
                            />
                        </div>
                        <InputError class="text-xs" :message="form.errors.email" />
                    </div>

                    <!-- New Password Field -->
                    <div class="space-y-2">
                        <label for="password" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">New Password</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <Lock class="h-5 w-5 text-gray-400" />
                            </div>
                            <input
                                id="password"
                                :type="showPassword ? 'text' : 'password'"
                                v-model="form.password"
                                required
                                autocomplete="new-password"
                                class="block w-full pl-10 pr-12 py-3 border border-gray-300 dark:border-gray-600 rounded-xl text-gray-900 dark:text-gray-100 dark:bg-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                                placeholder="Enter your new password"
                            />
                            <button
                                type="button"
                                @click="showPassword = !showPassword"
                                class="absolute inset-y-0 right-0 pr-3 flex items-center"
                            >
                                <Eye v-if="!showPassword" class="h-5 w-5 text-gray-400 hover:text-gray-600" />
                                <EyeOff v-else class="h-5 w-5 text-gray-400 hover:text-gray-600" />
                            </button>
                        </div>
                        <InputError class="text-xs" :message="form.errors.password" />
                    </div>

                    <!-- Confirm Password Field -->
                    <div class="space-y-2">
                        <label for="password_confirmation" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Confirm New Password</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <Lock class="h-5 w-5 text-gray-400" />
                            </div>
                            <input
                                id="password_confirmation"
                                :type="showConfirmPassword ? 'text' : 'password'"
                                v-model="form.password_confirmation"
                                required
                                autocomplete="new-password"
                                class="block w-full pl-10 pr-12 py-3 border border-gray-300 dark:border-gray-600 rounded-xl text-gray-900 dark:text-gray-100 dark:bg-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                                placeholder="Confirm your new password"
                            />
                            <button
                                type="button"
                                @click="showConfirmPassword = !showConfirmPassword"
                                class="absolute inset-y-0 right-0 pr-3 flex items-center"
                            >
                                <Eye v-if="!showConfirmPassword" class="h-5 w-5 text-gray-400 hover:text-gray-600" />
                                <EyeOff v-else class="h-5 w-5 text-gray-400 hover:text-gray-600" />
                            </button>
                        </div>
                        <InputError class="text-xs" :message="form.errors.password_confirmation" />
                    </div>

                    <!-- Password Requirements -->
                    <div class="text-xs text-gray-600 bg-blue-50 dark:bg-blue-950/30 p-3 rounded-lg border border-blue-200 dark:border-blue-900/60">
                        <p class="font-medium text-blue-800 dark:text-blue-300 mb-1">Password Requirements:</p>
                        <ul class="space-y-1 text-blue-700 dark:text-blue-400">
                            <li>• At least 8 characters long</li>
                            <li>• Include uppercase and lowercase letters</li>
                            <li>• Include at least one number</li>
                            <li>• Include at least one special character</li>
                        </ul>
                    </div>

                    <!-- Submit Button -->
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full bg-gradient-to-r from-blue-600 to-indigo-600 text-white font-semibold py-3 px-4 rounded-xl hover:from-blue-700 hover:to-indigo-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed transition-all duration-200 shadow-lg"
                    >
                        <span v-if="form.processing" class="flex items-center justify-center">
                            <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Updating password...
                        </span>
                        <span v-else>Update Password</span>
                    </button>
                </form>

                <!-- Security Notice -->
                <div class="mt-8 p-4 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/60 rounded-xl">
                    <div class="flex items-start">
                        <Shield class="h-5 w-5 text-amber-600 dark:text-amber-400 mt-0.5 mr-3 flex-shrink-0" />
                        <div class="text-sm">
                            <p class="font-medium text-amber-800 dark:text-amber-300">Security Notice</p>
                            <p class="text-amber-700 dark:text-amber-400 mt-1">
                                After resetting your password, you'll be automatically signed in to your account. 
                                Make sure to keep your new password secure.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="mt-8 text-center">
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    © 2026 Your Company. All rights reserved.
                </p>
            </div>
        </div>
    </div>
</template>

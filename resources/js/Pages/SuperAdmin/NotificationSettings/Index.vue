<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Checkbox from '@/Components/Checkbox.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    settings: Array,
});

const form = useForm({
    settings: props.settings.map(setting => ({
        id: setting.id,
        enabled: setting.enabled,
        config: setting.config || {},
    })),
});

const showConfigModal = ref(false);
const selectedSetting = ref(null);

const updateSetting = () => {
    form.patch(route('notification-settings.update'), {
        preserveScroll: true,
        onSuccess: () => {
            showConfigModal.value = false;
        },
    });
};

const toggleSetting = (setting) => {
    const settingIndex = form.settings.findIndex(s => s.id === setting.id);
    if (settingIndex !== -1) {
        form.settings[settingIndex].enabled = !form.settings[settingIndex].enabled;
        updateSetting();
    }
};

const openConfigModal = (setting) => {
    selectedSetting.value = setting;
    showConfigModal.value = true;
};

const updateConfig = () => {
    if (!selectedSetting.value) return;
    
    const settingIndex = form.settings.findIndex(s => s.id === selectedSetting.value.id);
    if (settingIndex !== -1) {
        form.settings[settingIndex].config = selectedSetting.value.config;
        updateSetting();
    }
};

const getNotificationIcon = (type) => {
    switch (type) {
        case 'calendar_reminder':
            return '📅';
        case 'meeting_assignment':
            return '👥';
        case 'todo_assignment':
            return '✅';
        case 'chat_message':
            return '💬';
        default:
            return '🔔';
    }
};
</script>

<template>
    <Head title="Notification Settings" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">Notification Settings</h2>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">
                            Configure which notifications users will receive. Super System Admin can enable or disable notification types system-wide.
                        </p>

                        <div class="space-y-4">
                            <div
                                v-for="setting in settings"
                                :key="setting.id"
                                class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:bg-gray-50 dark:hover:bg-gray-700 transition"
                            >
                                <div class="flex items-start justify-between">
                                    <div class="flex items-start gap-4 flex-1">
                                        <div class="text-2xl">{{ getNotificationIcon(setting.notification_type) }}</div>
                                        <div class="flex-1">
                                            <div class="flex items-center gap-3">
                                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ setting.name }}</h3>
                                                <span
                                                    :class="[
                                                        'px-2 py-1 text-xs font-medium rounded-full',
                                                        setting.enabled
                                                            ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300'
                                                            : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'
                                                    ]"
                                                >
                                                    {{ setting.enabled ? 'Enabled' : 'Disabled' }}
                                                </span>
                                            </div>
                                            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ setting.description }}</p>
                                            <div v-if="setting.config && Object.keys(setting.config).length > 0" class="mt-2">
                                                <button
                                                    @click="openConfigModal(setting)"
                                                    class="text-xs text-blue-600 dark:text-blue-400 hover:underline"
                                                >
                                                    Configure settings
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input
                                                type="checkbox"
                                                :checked="form.settings.find(s => s.id === setting.id)?.enabled"
                                                @change="toggleSetting(setting)"
                                                class="sr-only peer"
                                            />
                                            <div
                                                class="w-11 h-6 bg-gray-200 dark:bg-gray-600 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"
                                            ></div>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
                            <div class="flex justify-end">
                                <PrimaryButton
                                    @click="updateSetting"
                                    :disabled="form.processing"
                                    :class="{ 'opacity-25': form.processing }"
                                >
                                    Save Changes
                                </PrimaryButton>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Config Modal -->
        <Modal :show="showConfigModal" @close="showConfigModal = false">
            <div class="p-6 dark:bg-gray-800" v-if="selectedSetting">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                    Configure: {{ selectedSetting.name }}
                </h2>

                <div class="space-y-4">
                    <div v-if="selectedSetting.notification_type === 'calendar_reminder'">
                        <InputLabel for="reminder_before" value="Remind Before (minutes)" />
                        <TextInput
                            id="reminder_before"
                            type="number"
                            class="mt-1 block w-full"
                            v-model="selectedSetting.config.reminder_before_minutes"
                            min="1"
                        />
                    </div>

                    <div v-if="selectedSetting.notification_type === 'meeting_assignment'">
                        <InputLabel for="notify_days_before" value="Notify Days Before Meeting" />
                        <TextInput
                            id="notify_days_before"
                            type="number"
                            class="mt-1 block w-full"
                            v-model="selectedSetting.config.notify_days_before"
                            min="0"
                        />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Users will receive a notification this many days before the meeting date.
                        </p>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton @click="showConfigModal = false">Cancel</SecondaryButton>
                    <PrimaryButton @click="updateConfig">Save Configuration</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

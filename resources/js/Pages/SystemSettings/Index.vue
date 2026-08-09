<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import { Settings, Upload, Image as ImageIcon, Globe } from 'lucide-vue-next';
import { useSweetAlert } from '@/composables/useSweetAlert';

const { showSuccess, showError, showLoading, closeLoading } = useSweetAlert();

const props = defineProps({
    currentSettings: Object,
});

const form = useForm({
    site_name: props.currentSettings.site_name || '',
    site_tagline: props.currentSettings.site_tagline || '',
    site_email: props.currentSettings.site_email || '',
    site_phone: props.currentSettings.site_phone || '',
    site_address: props.currentSettings.site_address || '',
    site_logo_light: null,
    site_logo_dark: null,
    site_favicon: null,
});

const lightLogoPreview = ref(
    props.currentSettings.site_logo_light ? `/storage/${props.currentSettings.site_logo_light}` : null
);
const darkLogoPreview = ref(
    props.currentSettings.site_logo_dark ? `/storage/${props.currentSettings.site_logo_dark}` : null
);
const faviconPreview = ref(props.currentSettings.site_favicon ? `/storage/${props.currentSettings.site_favicon}` : null);

const handleLogoChange = (event, fieldName, previewRef, label) => {
    const file = event.target.files[0];
    console.log(`${label} file selected:`, file);
    
    if (file) {
        console.log(`${label} file details:`, {
            name: file.name,
            size: file.size,
            type: file.type,
            lastModified: file.lastModified
        });
        
        // Validate file size (2MB max)
        if (file.size > 2 * 1024 * 1024) {
            showError(`${label} file size must be less than 2MB`, 'File Size Error');
            event.target.value = ''; // Clear the input
            return;
        }
        
        // Validate file type
        const allowedTypes = ['image/jpeg', 'image/png', 'image/svg+xml', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            showError(`${label} must be a JPG, PNG, SVG, or WebP file. Current type: ${file.type}`, 'File Type Error');
            event.target.value = ''; // Clear the input
            return;
        }
        
        form[fieldName] = file;
        previewRef.value = URL.createObjectURL(file);
        console.log(`${label} file added to form`);
    }
};

const handleFaviconChange = (event) => {
    const file = event.target.files[0];
    console.log('Favicon file selected:', file);
    
    if (file) {
        console.log('Favicon file details:', {
            name: file.name,
            size: file.size,
            type: file.type,
            lastModified: file.lastModified
        });
        
        // Validate file size (512KB max)
        if (file.size > 512 * 1024) {
            showError('Favicon file size must be less than 512KB', 'File Size Error');
            event.target.value = ''; // Clear the input
            return;
        }
        
        // Validate file type
        const allowedTypes = ['image/x-icon', 'image/png'];
        if (!allowedTypes.includes(file.type)) {
            showError(`Favicon must be an ICO or PNG file. Current type: ${file.type}`, 'File Type Error');
            event.target.value = ''; // Clear the input
            return;
        }
        
        form.site_favicon = file;
        faviconPreview.value = URL.createObjectURL(file);
        console.log('Favicon file added to form');
    }
};

const submit = () => {
    console.log('Form submission started');
    console.log('Form data:', {
        site_name: form.site_name,
        site_tagline: form.site_tagline,
        site_email: form.site_email,
        site_phone: form.site_phone,
        site_address: form.site_address,
        has_site_logo_light: !!form.site_logo_light,
        site_logo_light_name: form.site_logo_light?.name,
        site_logo_light_size: form.site_logo_light?.size,
        has_site_logo_dark: !!form.site_logo_dark,
        site_logo_dark_name: form.site_logo_dark?.name,
        site_logo_dark_size: form.site_logo_dark?.size,
        has_site_favicon: !!form.site_favicon,
        site_favicon_name: form.site_favicon?.name,
        site_favicon_size: form.site_favicon?.size,
    });
    
    showLoading('Saving settings...');
    
    form.post(route('settings.update'), {
        forceFormData: true,
        onSuccess: () => {
            closeLoading();
            showSuccess('System settings have been updated successfully!', 'Settings Saved');
        },
        onError: (errors) => {
            closeLoading();
            
            // Log errors to console for debugging
            console.error('Form submission errors:', errors);
            
            // Create detailed error message
            let errorMessage = 'There was an error saving your settings:\n\n';
            
            if (typeof errors === 'string') {
                errorMessage = errors;
            } else if (typeof errors === 'object') {
                Object.keys(errors).forEach(key => {
                    errorMessage += `• ${key}: ${errors[key]}\n`;
                });
            }
            
            // Check for specific upload errors
            if (errors.site_logo_light) {
                errorMessage += '\nLight logo upload failed: ' + errors.site_logo_light;
            }
            if (errors.site_logo_dark) {
                errorMessage += '\nDark logo upload failed: ' + errors.site_logo_dark;
            }
            if (errors.site_favicon) {
                errorMessage += '\nFavicon upload failed: ' + errors.site_favicon;
            }
            
            showError(errorMessage, 'Upload Error');
        },
        onFinish: () => {
            // This will be called regardless of success or error
            console.log('Form submission finished');
        }
    });
};
</script>

<template>
    <Head title="System Settings" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Settings class="h-6 w-6 text-gray-600 dark:text-gray-400" />
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">System Settings</h2>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <form @submit.prevent="submit" class="p-6">
                        <!-- General Settings -->
                        <div class="mb-8">
                            <div class="flex items-center gap-2 mb-4 pb-2 border-b dark:border-gray-700">
                                <Globe class="h-5 w-5 text-indigo-600 dark:text-indigo-400" />
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">General Information</h3>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <InputLabel for="site_name" value="Site Name" />
                                    <TextInput 
                                        id="site_name" 
                                        v-model="form.site_name" 
                                        type="text" 
                                        class="mt-1 block w-full" 
                                        placeholder="Enter site name"
                                    />
                                    <InputError :message="form.errors.site_name" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="site_tagline" value="Site Tagline" />
                                    <TextInput 
                                        id="site_tagline" 
                                        v-model="form.site_tagline" 
                                        type="text" 
                                        class="mt-1 block w-full" 
                                        placeholder="Enter site tagline"
                                    />
                                    <InputError :message="form.errors.site_tagline" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="site_email" value="Contact Email" />
                                    <TextInput 
                                        id="site_email" 
                                        v-model="form.site_email" 
                                        type="email" 
                                        class="mt-1 block w-full" 
                                        placeholder="contact@example.com"
                                    />
                                    <InputError :message="form.errors.site_email" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="site_phone" value="Contact Phone" />
                                    <TextInput 
                                        id="site_phone" 
                                        v-model="form.site_phone" 
                                        type="text" 
                                        class="mt-1 block w-full" 
                                        placeholder="+880 1XXX-XXXXXX"
                                    />
                                    <InputError :message="form.errors.site_phone" class="mt-2" />
                                </div>

                                <div class="md:col-span-2">
                                    <InputLabel for="site_address" value="Address" />
                                    <textarea 
                                        id="site_address" 
                                        v-model="form.site_address" 
                                        rows="2"
                                        class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                        placeholder="Enter office address"
                                    ></textarea>
                                    <InputError :message="form.errors.site_address" class="mt-2" />
                                </div>
                            </div>
                        </div>

                        <!-- Appearance Settings -->
                        <div class="mb-8">
                            <div class="flex items-center gap-2 mb-4 pb-2 border-b dark:border-gray-700">
                                <ImageIcon class="h-5 w-5 text-indigo-600 dark:text-indigo-400" />
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Appearance</h3>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Light Logo Upload -->
                                <div>
                                    <InputLabel for="site_logo_light" value="Light Theme Logo" />
                                    <div class="mt-2">
                                        <div v-if="lightLogoPreview" class="mb-3 p-4 bg-white rounded-lg border border-gray-200">
                                            <img :src="lightLogoPreview" alt="Light Logo Preview" class="h-20 object-contain mx-auto" />
                                        </div>
                                        <label
                                            for="site_logo_light"
                                            class="flex items-center justify-center px-4 py-3 bg-white dark:bg-gray-700 border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:border-indigo-400 dark:hover:border-indigo-500 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 transition-colors"
                                        >
                                            <Upload class="h-5 w-5 text-gray-400 dark:text-gray-500 mr-2" />
                                            <span class="text-sm text-gray-600 dark:text-gray-300">Upload Light Logo (JPG, PNG, SVG, WebP)</span>
                                        </label>
                                        <input
                                            id="site_logo_light"
                                            type="file"
                                            @change="handleLogoChange($event, 'site_logo_light', lightLogoPreview, 'Light logo')"
                                            accept="image/jpeg,image/png,image/jpg,image/svg+xml,image/webp"
                                            class="hidden"
                                        />
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Max size: 2MB</p>
                                    </div>
                                    <InputError :message="form.errors.site_logo_light" class="mt-2" />
                                </div>

                                <!-- Dark Logo Upload -->
                                <div>
                                    <InputLabel for="site_logo_dark" value="Dark Theme Logo" />
                                    <div class="mt-2">
                                        <div v-if="darkLogoPreview" class="mb-3 p-4 bg-gray-950 rounded-lg border border-gray-700">
                                            <img :src="darkLogoPreview" alt="Dark Logo Preview" class="h-20 object-contain mx-auto" />
                                        </div>
                                        <label
                                            for="site_logo_dark"
                                            class="flex items-center justify-center px-4 py-3 bg-white dark:bg-gray-700 border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:border-indigo-400 dark:hover:border-indigo-500 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 transition-colors"
                                        >
                                            <Upload class="h-5 w-5 text-gray-400 dark:text-gray-500 mr-2" />
                                            <span class="text-sm text-gray-600 dark:text-gray-300">Upload Dark Logo (JPG, PNG, SVG, WebP)</span>
                                        </label>
                                        <input
                                            id="site_logo_dark"
                                            type="file"
                                            @change="handleLogoChange($event, 'site_logo_dark', darkLogoPreview, 'Dark logo')"
                                            accept="image/jpeg,image/png,image/jpg,image/svg+xml,image/webp"
                                            class="hidden"
                                        />
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Max size: 2MB</p>
                                    </div>
                                    <InputError :message="form.errors.site_logo_dark" class="mt-2" />
                                </div>

                                <!-- Favicon Upload -->
                                <div>
                                    <InputLabel for="site_favicon" value="Site Favicon" />
                                    <div class="mt-2">
                                        <div v-if="faviconPreview" class="mb-3 p-4 bg-gray-50 dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700">
                                            <img :src="faviconPreview" alt="Favicon Preview" class="h-12 w-12 object-contain mx-auto" />
                                        </div>
                                        <label
                                            for="site_favicon"
                                            class="flex items-center justify-center px-4 py-3 bg-white dark:bg-gray-700 border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:border-indigo-400 dark:hover:border-indigo-500 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 transition-colors"
                                        >
                                            <Upload class="h-5 w-5 text-gray-400 dark:text-gray-500 mr-2" />
                                            <span class="text-sm text-gray-600 dark:text-gray-300">Upload Favicon (ICO, PNG)</span>
                                        </label>
                                        <input
                                            id="site_favicon"
                                            type="file"
                                            @change="handleFaviconChange"
                                            accept="image/x-icon,image/png"
                                            class="hidden"
                                        />
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Max size: 512KB</p>
                                    </div>
                                    <InputError :message="form.errors.site_favicon" class="mt-2" />
                                </div>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="flex justify-end pt-6 border-t dark:border-gray-700">
                            <PrimaryButton 
                                :class="{ 'opacity-25': form.processing }" 
                                :disabled="form.processing"
                            >
                                <Settings class="h-4 w-4 mr-2" />
                                Save Settings
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

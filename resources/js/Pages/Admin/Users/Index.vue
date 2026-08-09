<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import { Edit, Trash2, UserPlus, Eye, EyeOff } from 'lucide-vue-next';
import { usePage } from '@inertiajs/vue3';
import { useSweetAlert } from '@/composables/useSweetAlert';

const props = defineProps({
    users: Array,
    roles: Array,
    departments: Array,
    showTrashed: Boolean,
});

const page = usePage();
const currentUser = page.props.auth.user;
const currentUserRoles = page.props.auth.roles || [];

const isSuperAdmin = currentUserRoles.includes('Super System Admin');

const showUserModal = ref(false);
const isEditing = ref(false);
const showPassword = ref(false);
const showPasswordConfirmation = ref(false);
const form = useForm({
    id: null,
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    roles: [],
    department_id: null,
});

const openCreateModal = () => {
    isEditing.value = false;
    form.reset();
    form.clearErrors();
    showPassword.value = false;
    showPasswordConfirmation.value = false;
    showUserModal.value = true;
};

const openEditModal = (user) => {
    isEditing.value = true;
    form.reset();
    form.clearErrors();
    form.id = user.id;
    form.name = user.name;
    form.email = user.email;
    form.password = '';
    form.password_confirmation = '';
    form.roles = user.roles.map(r => r.name);
    form.department_id = user.department?.id || null;
    showPassword.value = false;
    showPasswordConfirmation.value = false;
    showUserModal.value = true;
};

const closeModal = () => {
    showUserModal.value = false;
    form.reset();
};

const submit = () => {
    if (isEditing.value) {
        form.put(route('users.update', form.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('users.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const { showDeleteConfirm, showConfirm } = useSweetAlert();

const deleteUser = async (user) => {
    const result = await showDeleteConfirm(`Are you sure you want to delete "<strong>${user.name}</strong>"?<br><span class="text-sm text-gray-500">This user will be moved to trash.</span>`);
    if (result.isConfirmed) {
        useForm({}).delete(route('users.destroy', user.id));
    }
};

const restoreUser = async (user) => {
    const result = await showConfirm(`Restore user "<strong>${user.name}</strong>"?`, 'Restore');
    if (result.isConfirmed) {
        useForm({}).post(route('users.restore', user.id));
    }
};

const forceDeleteUser = async (user) => {
    const result = await showDeleteConfirm(`Permanently delete "<strong>${user.name}</strong>"?<br><span class="text-sm text-red-500 font-semibold">This action cannot be undone.</span>`);
    if (result.isConfirmed) {
        useForm({}).delete(route('users.force-destroy', user.id));
    }
};
</script>

<template>
    <Head title="User Management" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">User Management</h2>
                <div class="flex gap-2">
                    <a :href="route('users.index', props.showTrashed ? {} : { trash: 1 })">
                        <SecondaryButton>{{ props.showTrashed ? 'Back to List' : 'Show Trash' }}</SecondaryButton>
                    </a>
                    <PrimaryButton @click="openCreateModal">Create New User</PrimaryButton>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-4 lg:px-4 xl:max-w-[1440px] 2xl:max-w-[1600px]">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100 overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Email</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Department</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Roles</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Impersonate</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="user in users" :key="user.id">
                                    <td class="px-6 py-4 whitespace-nowrap">{{ user.name }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">{{ user.email }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">{{ user.department?.name || '-' }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span v-for="role in user.roles" :key="role.id" class="px-2 py-1 mr-1 text-xs font-semibold text-blue-800 bg-blue-100 dark:bg-blue-900/40 dark:text-blue-300 rounded-full">
                                            {{ role.name }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        <a v-if="isSuperAdmin && user.id !== currentUser.id" :href="route('impersonate.start', user.id)" class="inline-flex items-center text-purple-600 hover:text-purple-900 dark:text-purple-400 dark:hover:text-purple-300" title="Impersonate User">
                                            <UserPlus :size="18" />
                                        </a>
                                        <span v-else class="text-gray-300 dark:text-gray-600">-</span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium flex justify-end gap-2">
                                        <template v-if="!props.showTrashed">
                                            <button @click="openEditModal(user)" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300 mr-3"><Edit :size="16" class="inline" /></button>
                                            <button @click="deleteUser(user)" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300"><Trash2 :size="16" class="inline" /></button>
                                        </template>
                                        <template v-else>
                                            <button @click="restoreUser(user)" class="text-green-600 hover:text-green-800 dark:text-green-400 dark:hover:text-green-300">Restore</button>
                                            <button @click="forceDeleteUser(user)" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">Delete Permanently</button>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <Modal :show="showUserModal" @close="closeModal">
            <div class="p-6 dark:bg-gray-800">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                    {{ isEditing ? 'Edit User' : 'Create New User' }}
                </h2>

                <div class="mt-6 space-y-4">
                    <div>
                        <InputLabel for="name" value="Name" />
                        <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" />
                        <InputError :message="form.errors.name" class="mt-2" />
                    </div>

                    <div>
                        <InputLabel for="email" value="Email" />
                        <TextInput id="email" v-model="form.email" type="email" class="mt-1 block w-full" />
                        <InputError :message="form.errors.email" class="mt-2" />
                    </div>

                    <div>
                        <InputLabel for="department_id" value="Department" />
                        <select
                            id="department_id"
                            v-model="form.department_id"
                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                        >
                            <option value="">Select Department</option>
                            <option v-for="department in departments" :key="department.id" :value="department.id">
                                {{ department.name }}
                            </option>
                        </select>
                        <InputError :message="form.errors.department_id" class="mt-2" />
                    </div>

                    <div>
                        <InputLabel for="password" value="Password" />
                        <div class="relative mt-1">
                            <TextInput
                                id="password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                class="block w-full pr-10"
                                placeholder="Leave blank to keep current"
                            />
                            <button
                                type="button"
                                @click="showPassword = !showPassword"
                                class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                                :title="showPassword ? 'Hide password' : 'Show password'"
                            >
                                <EyeOff v-if="showPassword" :size="18" />
                                <Eye v-else :size="18" />
                            </button>
                        </div>
                        <InputError :message="form.errors.password" class="mt-2" />
                    </div>

                    <div>
                        <InputLabel for="password_confirmation" value="Confirm Password" />
                        <div class="relative mt-1">
                            <TextInput
                                id="password_confirmation"
                                v-model="form.password_confirmation"
                                :type="showPasswordConfirmation ? 'text' : 'password'"
                                class="block w-full pr-10"
                            />
                            <button
                                type="button"
                                @click="showPasswordConfirmation = !showPasswordConfirmation"
                                class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                                :title="showPasswordConfirmation ? 'Hide password' : 'Show password'"
                            >
                                <EyeOff v-if="showPasswordConfirmation" :size="18" />
                                <Eye v-else :size="18" />
                            </button>
                        </div>
                        <InputError :message="form.errors.password_confirmation" class="mt-2" />
                    </div>

                    <div>
                        <InputLabel value="Roles" />
                        <div class="mt-2 space-y-2 max-h-48 overflow-y-auto border p-2 rounded dark:border-gray-600">
                            <div v-for="role in roles" :key="role.id" class="flex items-center">
                                <input type="checkbox" :id="'role-'+role.id" :value="role.name" v-model="form.roles" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700">
                                <label :for="'role-'+role.id" class="ml-2 text-sm text-gray-600 dark:text-gray-300 cursor-pointer select-none">{{ role.name }}</label>
                            </div>
                        </div>
                         <InputError :message="form.errors.roles" class="mt-2" />
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="closeModal">Cancel</SecondaryButton>
                    <PrimaryButton class="ml-3" @click="submit" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                        {{ isEditing ? 'Update User' : 'Create User' }}
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import {
    Building2, Users, Phone, Mail, User, Shield,
    Search, ChevronDown, ChevronUp, Briefcase, Plus, Trash2
} from 'lucide-vue-next';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import { useSweetAlert } from '@/composables/useSweetAlert';

const props = defineProps({
    departments: Array,
    unassignedUsers: Array,
});

const search = ref('');
const expandedDepts = ref({});

const showCreateModal = ref(false);
const departmentForm = useForm({
    name: '',
    description: '',
});

const openCreateModal = () => {
    departmentForm.reset();
    departmentForm.clearErrors();
    showCreateModal.value = true;
};

const closeCreateModal = () => {
    showCreateModal.value = false;
    departmentForm.reset();
};

const submitDepartment = () => {
    departmentForm.post(route('departments.store'), {
        onSuccess: () => closeCreateModal(),
    });
};

const { showDeleteConfirm } = useSweetAlert();

const deleteDepartment = async (dept) => {
    const result = await showDeleteConfirm(`Are you sure you want to delete "<strong>${dept.name}</strong>"?<br><span class="text-sm text-gray-500">Users in this department will become unassigned.</span>`);
    if (result.isConfirmed) {
        router.delete(route('departments.destroy', dept.id), {
            preserveScroll: true,
        });
    }
};

const toggleDept = (id) => {
    expandedDepts.value[id] = !expandedDepts.value[id];
};

const filteredDepartments = computed(() => {
    if (!search.value.trim()) return props.departments;
    const q = search.value.toLowerCase();
    return props.departments.filter(d =>
        d.name?.toLowerCase().includes(q) ||
        d.users?.some(u =>
            u.name?.toLowerCase().includes(q) ||
            u.email?.toLowerCase().includes(q) ||
            u.unit?.toLowerCase().includes(q)
        )
    );
});

const totalUsers = computed(() =>
    props.departments.reduce((s, d) => s + d.users_count, 0)
);

const deptColor = (index) => {
    const colors = [
        'from-indigo-500 to-purple-600',
        'from-blue-500 to-cyan-600',
        'from-emerald-500 to-teal-600',
        'from-orange-500 to-red-500',
        'from-pink-500 to-rose-600',
        'from-violet-500 to-purple-600',
        'from-amber-500 to-orange-600',
        'from-sky-500 to-blue-600',
        'from-green-500 to-emerald-600',
        'from-red-500 to-pink-600',
    ];
    return colors[index % colors.length];
};

const deptBorderColor = (index) => {
    const colors = [
        'border-indigo-200 bg-indigo-50',
        'border-blue-200 bg-blue-50',
        'border-emerald-200 bg-emerald-50',
        'border-orange-200 bg-orange-50',
        'border-pink-200 bg-pink-50',
        'border-violet-200 bg-violet-50',
        'border-amber-200 bg-amber-50',
        'border-sky-200 bg-sky-50',
        'border-green-200 bg-green-50',
        'border-red-200 bg-red-50',
    ];
    return colors[index % colors.length];
};

const deptTextColor = (index) => {
    const colors = [
        'text-indigo-700',
        'text-blue-700',
        'text-emerald-700',
        'text-orange-700',
        'text-pink-700',
        'text-violet-700',
        'text-amber-700',
        'text-sky-700',
        'text-green-700',
        'text-red-700',
    ];
    return colors[index % colors.length];
};
</script>

<template>
    <Head title="Departments" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800 flex items-center gap-2">
                    <Building2 :size="22" class="text-indigo-600" />
                    Departments
                </h2>
                <PrimaryButton @click="openCreateModal">
                    <Plus :size="16" class="mr-1" /> Create Department
                </PrimaryButton>
            </div>
        </template>

        <div class="py-8">
            <div  class="mx-auto max-w-7xl sm:px-4 lg:px-4 xl:max-w-[1440px] 2xl:max-w-[1600px] space-y-6">

                <!-- Summary Cards -->
                <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-indigo-100 dark:border-indigo-900/50 p-4 flex items-center gap-4 shadow-sm">
                        <div class="w-12 h-12 rounded-full bg-indigo-100 dark:bg-indigo-900/40 flex items-center justify-center">
                            <Building2 :size="22" class="text-indigo-600 dark:text-indigo-400" />
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Total Departments</p>
                            <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ departments.length }}</p>
                        </div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-blue-100 dark:border-blue-900/50 p-4 flex items-center gap-4 shadow-sm">
                        <div class="w-12 h-12 rounded-full bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center">
                            <Users :size="22" class="text-blue-600 dark:text-blue-400" />
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Total Users</p>
                            <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ totalUsers }}</p>
                        </div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-orange-100 dark:border-orange-900/50 p-4 flex items-center gap-4 shadow-sm">
                        <div class="w-12 h-12 rounded-full bg-orange-100 dark:bg-orange-900/40 flex items-center justify-center">
                            <User :size="22" class="text-orange-600 dark:text-orange-400" />
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Unassigned Users</p>
                            <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ unassignedUsers.length }}</p>
                        </div>
                    </div>
                </div>

                <!-- Search -->
                <div class="relative w-full md:w-80">
                    <Search :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search departments or users..."
                        class="w-full pl-9 pr-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400"
                    />
                </div>

                <!-- Department Cards -->
                <div class="space-y-4">
                    <div
                        v-for="(dept, index) in filteredDepartments"
                        :key="dept.id"
                        class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden"
                    >
                        <!-- Department Header -->
                        <div
                            class="flex items-center justify-between p-5 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                            @click="toggleDept(dept.id)"
                        >
                            <div class="flex items-center gap-4">
                                <!-- Gradient Avatar -->
                                <div :class="['w-14 h-14 rounded-xl bg-gradient-to-br flex items-center justify-center text-white font-bold text-lg shadow flex-shrink-0', deptColor(index)]">
                                    {{ dept.name?.charAt(0)?.toUpperCase() }}
                                </div>
                                <div>
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">{{ dept.name }}</h3>
                                        <span :class="['px-2 py-0.5 text-xs font-semibold rounded-full border', deptBorderColor(index), deptTextColor(index)]">
                                            <Shield :size="10" class="inline mr-0.5" />Role / Department
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 flex items-center gap-1">
                                        <Users :size="12" />
                                        {{ dept.users_count }} {{ dept.users_count === 1 ? 'member' : 'members' }}
                                    </p>
                                </div>
                            </div>

                            <!-- Toggle -->
                            <div class="flex items-center gap-4">
                                <div class="hidden md:flex items-center gap-2 flex-wrap">
                                    <div
                                        v-for="user in dept.users.slice(0, 4)"
                                        :key="user.id"
                                        :title="user.name"
                                        class="w-8 h-8 rounded-full bg-gradient-to-br from-gray-400 to-gray-600 flex items-center justify-center text-white text-xs font-bold border-2 border-white dark:border-gray-800 shadow-sm -ml-2 first:ml-0"
                                    >
                                        {{ user.name?.charAt(0)?.toUpperCase() }}
                                    </div>
                                    <span v-if="dept.users_count > 4" class="text-xs text-gray-500 dark:text-gray-400 ml-1">
                                        +{{ dept.users_count - 4 }} more
                                    </span>
                                </div>
                                <button
                                    @click.stop="deleteDepartment(dept)"
                                    class="text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 p-1 rounded hover:bg-red-50 dark:hover:bg-red-900/30"
                                    title="Delete Department"
                                >
                                    <Trash2 :size="16" />
                                </button>
                                <ChevronUp v-if="expandedDepts[dept.id]" :size="20" class="text-gray-400 dark:text-gray-500 flex-shrink-0" />
                                <ChevronDown v-else :size="20" class="text-gray-400 dark:text-gray-500 flex-shrink-0" />
                            </div>
                        </div>

                        <!-- Users Table (Expanded) -->
                        <div v-if="expandedDepts[dept.id]" class="border-t border-gray-100 dark:border-gray-700">
                            <div v-if="dept.users.length > 0" class="overflow-x-auto">
                                <table class="min-w-full">
                                    <thead :class="['border-b dark:border-gray-700 dark:bg-gray-900/50', deptBorderColor(index)]">
                                        <tr>
                                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">#</th>
                                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Name</th>
                                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Email</th>
                                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Phone</th>
                                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Unit / Company</th>
                                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Duty</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                        <tr
                                            v-for="(user, idx) in dept.users"
                                            :key="user.id"
                                            class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                                        >
                                            <td class="px-5 py-3 text-sm text-gray-500 dark:text-gray-400">{{ idx + 1 }}</td>
                                            <td class="px-5 py-3">
                                                <div class="flex items-center gap-3">
                                                    <div :class="['w-8 h-8 rounded-full bg-gradient-to-br flex items-center justify-center text-white text-xs font-bold flex-shrink-0', deptColor(index)]">
                                                        {{ user.name?.charAt(0)?.toUpperCase() }}
                                                    </div>
                                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ user.name }}</span>
                                                </div>
                                            </td>
                                            <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                                <a :href="`mailto:${user.email}`" class="flex items-center gap-1 hover:text-indigo-600 dark:hover:text-indigo-400">
                                                    <Mail :size="13" class="text-gray-400 dark:text-gray-500" />{{ user.email }}
                                                </a>
                                            </td>
                                            <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                                <a v-if="user.phone" :href="`tel:${user.phone}`" class="flex items-center gap-1 hover:text-indigo-600 dark:hover:text-indigo-400">
                                                    <Phone :size="13" class="text-gray-400 dark:text-gray-500" />{{ user.phone }}
                                                </a>
                                                <span v-else class="text-gray-400 dark:text-gray-500">-</span>
                                            </td>
                                            <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                                <span v-if="user.unit" class="flex items-center gap-1">
                                                    <Building2 :size="13" class="text-gray-400 dark:text-gray-500" />
                                                    {{ user.unit }}
                                                    <span v-if="user.unit_code" class="text-xs text-gray-400 dark:text-gray-500">({{ user.unit_code }})</span>
                                                </span>
                                                <span v-else class="text-gray-400 dark:text-gray-500 italic text-xs">Not assigned</span>
                                            </td>
                                            <td class="px-5 py-3">
                                                <span :class="['inline-flex items-center gap-1 px-2.5 py-0.5 text-xs font-semibold rounded-full border', deptBorderColor(index), deptTextColor(index)]">
                                                    <Briefcase :size="10" />{{ dept.name }}
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-else class="px-5 py-6 text-center text-sm text-gray-400 dark:text-gray-500 italic">
                                No users in this department.
                            </div>
                        </div>
                    </div>

                    <div v-if="filteredDepartments.length === 0" class="text-center py-12 text-gray-400 dark:text-gray-500">
                        No departments found.
                    </div>
                </div>

                <!-- Unassigned Users -->
                <div v-if="unassignedUsers.length > 0" class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-orange-200 dark:border-orange-900/50 overflow-hidden">
                    <div class="flex items-center gap-3 p-5 bg-orange-50 dark:bg-orange-900/20 border-b border-orange-100 dark:border-orange-900/40">
                        <div class="w-10 h-10 rounded-full bg-orange-100 dark:bg-orange-900/40 flex items-center justify-center">
                            <Users :size="18" class="text-orange-600 dark:text-orange-400" />
                        </div>
                        <div>
                            <h3 class="font-bold text-gray-900 dark:text-gray-100">Unassigned Users</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">No role / department assigned</p>
                        </div>
                        <span class="ml-auto px-3 py-1 bg-orange-500 text-white text-xs font-bold rounded-full">
                            {{ unassignedUsers.length }}
                        </span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead class="bg-gray-50 dark:bg-gray-900/50">
                                <tr>
                                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">#</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Name</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Email</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Phone</th>
                                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Unit</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="(user, idx) in unassignedUsers" :key="user.id" class="hover:bg-orange-50/30 dark:hover:bg-orange-900/10">
                                    <td class="px-5 py-3 text-sm text-gray-500 dark:text-gray-400">{{ idx + 1 }}</td>
                                    <td class="px-5 py-3">
                                        <div class="flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-orange-400 to-red-400 flex items-center justify-center text-white text-xs font-bold">
                                                {{ user.name?.charAt(0)?.toUpperCase() }}
                                            </div>
                                            <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ user.name }}</span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ user.email }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ user.phone || '-' }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                        <span v-if="user.unit">{{ user.unit }}</span>
                                        <span v-else class="text-gray-400 dark:text-gray-500 italic text-xs">Not assigned</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>

        <!-- Create Department Modal -->
        <Modal :show="showCreateModal" @close="closeCreateModal">
            <div class="p-6 dark:bg-gray-800">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Create New Department</h2>

                <div class="mt-6">
                    <InputLabel for="dept_name" value="Department Name" />
                    <TextInput
                        id="dept_name"
                        type="text"
                        class="mt-1 block w-full"
                        v-model="departmentForm.name"
                        placeholder="e.g. Procurement"
                        autofocus
                        @keyup.enter="submitDepartment"
                    />
                    <InputError class="mt-2" :message="departmentForm.errors.name" />
                </div>

                <div class="mt-4">
                    <InputLabel for="dept_description" value="Description" />
                    <TextInput
                        id="dept_description"
                        type="text"
                        class="mt-1 block w-full"
                        v-model="departmentForm.description"
                        placeholder="Optional"
                        @keyup.enter="submitDepartment"
                    />
                    <InputError class="mt-2" :message="departmentForm.errors.description" />
                </div>

                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="closeCreateModal">Cancel</SecondaryButton>
                    <PrimaryButton
                        class="ms-3"
                        :class="{ 'opacity-25': departmentForm.processing }"
                        :disabled="departmentForm.processing"
                        @click="submitDepartment"
                    >
                        Create Department
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

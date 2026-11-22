<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import Checkbox from '@/Components/Checkbox.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Pencil, Trash2 } from 'lucide-vue-next';

const props = defineProps({
    roles: Array,
    permissions: Array,
    // Every master-data screen: [{ label, actions: [{ action, permission, label? }], manage }], from the server.
    masterData: { type: Array, default: () => [] },
});

const showRoleModal = ref(false);
const isEditing = ref(false);
const editingRoleId = ref(null);

const roleForm = useForm({
    name: '',
    display_name: '',
});

const openCreateModal = () => {
    isEditing.value = false;
    editingRoleId.value = null;
    roleForm.reset();
    showRoleModal.value = true;
};

const openEditModal = (role) => {
    isEditing.value = true;
    editingRoleId.value = role.id;
    roleForm.name = role.name;
    roleForm.display_name = role.display_name || '';
    showRoleModal.value = true;
};

const submitRole = () => {
    if (isEditing.value) {
        roleForm.patch(route('roles.update', editingRoleId.value), {
            onSuccess: () => {
                showRoleModal.value = false;
                roleForm.reset();
            },
        });
    } else {
        roleForm.post(route('roles.store'), {
            onSuccess: () => {
                showRoleModal.value = false;
                roleForm.reset();
            },
        });
    }
};

const deleteRole = (role) => {
    if (!confirm(`Are you sure you want to delete the role "${role.name}"?`)) return;
    
    router.delete(route('roles.destroy', role.id), {
        preserveScroll: true,
    });
};

const updatePermission = (role, permissionName, checked) => {
    if (!permissionName) return;

    let currentPermissions = role.permissions.map(p => p.name);
    
    console.log(`🔄 PERMISSION UPDATE: ${permissionName} for role: ${role.name}`);
    console.log(`🔄 PERMISSION UPDATE: Current permissions before:`, currentPermissions);
    console.log(`🔄 PERMISSION UPDATE: Checked: ${checked}`);
    
    if (checked) {
        if (!currentPermissions.includes(permissionName)) {
            currentPermissions.push(permissionName);
            console.log(`🔄 PERMISSION UPDATE: Added permission: ${permissionName}`);
        } else {
            console.log(`🔄 PERMISSION UPDATE: Permission already exists: ${permissionName}`);
        }
    } else {
        currentPermissions = currentPermissions.filter(p => p !== permissionName);
        console.log(`🔄 PERMISSION UPDATE: Removed permission: ${permissionName}`);
    }
    
    console.log(`🔄 PERMISSION UPDATE: Final permissions:`, currentPermissions);

    const form = useForm({
        permissions: currentPermissions
    });

    form.patch(route('roles.permissions.update', role.id), {
        preserveScroll: true,
        onSuccess: () => {
            console.log(`✅ PERMISSION UPDATE: Success for role: ${role.name}`);
            router.reload();
        },
        onError: (errors) => {
            console.log(`❌ PERMISSION UPDATE: Error for role: ${role.name}`, errors);
        }
    });
};

const hasPermission = (role, permissionName, impliedBy = null) => {
    if (!permissionName) return false;
    
    // Super System Admin has all permissions by default
    if (role.name === 'Super System Admin') {
        return true;
    }
    
    return role.permissions.some(p => p.name === permissionName || (impliedBy && p.name === impliedBy));
};

// A master's "Full access" row stands in for every other row of that master. They show as
// ticked (and locked) while it is on; untick it to choose action by action.
const isImplied = (role, feature) =>
    role.name !== 'Super System Admin'
    && !!feature.impliedBy
    && role.permissions.some(p => p.name === feature.impliedBy);

// Map setColor keys to Tailwind bg classes for feature set grouping
const setColorMap = {
    blue:    { row: 'bg-blue-50 dark:bg-blue-900/10',    label: 'text-blue-800 dark:text-blue-200',   dot: 'bg-blue-400' },
    purple:  { row: 'bg-purple-50 dark:bg-purple-900/10',  label: 'text-purple-800 dark:text-purple-200', dot: 'bg-purple-400' },
    emerald: { row: 'bg-emerald-50 dark:bg-emerald-900/10', label: 'text-emerald-800 dark:text-emerald-200',dot: 'bg-emerald-400' },
    amber:   { row: 'bg-amber-50 dark:bg-amber-900/10',   label: 'text-amber-800 dark:text-amber-200',  dot: 'bg-amber-400' },
};

const getSetRowClass = (feature) => {
    if (!feature.setColor) return 'hover:bg-gray-50 dark:hover:bg-gray-700/40';
    return `${setColorMap[feature.setColor].row} hover:brightness-95`;
};

const getSetLabelClass = (feature) => {
    if (!feature.setColor) return 'text-gray-700 dark:text-gray-100';
    return setColorMap[feature.setColor].label;
};

const isParentRow = (feature) => feature.name && !feature.name.startsWith('↳');

// Master data: each screen gets a row per thing a role can do on it (see, add, edit, delete,
// and Excel import / export where the screen has them), then "Full access", which allows all.
const ACTION_LABELS = {
    create: 'Add',
    edit: 'Edit',
    delete: 'Delete',
    import: 'Import from Excel',
    export: 'Export to Excel',
};

const masterRows = (master) => [
    ...master.actions.map(({ action, permission, label }) =>
        action === 'view'
            ? { name: master.label, permission, setColor: 'blue', impliedBy: master.manage }
            : { name: `↳ ${label ?? ACTION_LABELS[action]}`, permission, setColor: 'blue', impliedBy: master.manage },
    ),
    { name: '↳ Full access (manage)', permission: master.manage, setColor: 'blue' },
];

// Define system features mapping with categories
const systemFeatures = computed(() => {
    return [
        { category: 'Core Modules', items: [
            { name: 'Dashboard', permission: 'view dashboard' },
            { name: 'Clifto Talk', permission: 'access clifto talk' },
            { name: 'Calendar & Todo', permission: 'view calendar' },
            { name: 'Todos', permission: 'access todo' },
            { name: 'User Management', permission: 'manage users' },
            { name: 'Role Management', permission: 'manage roles' },
            { name: 'Settings', permission: 'manage settings' },
            { name: 'Documentation', permission: 'view documentation' },
        ]},
        // Same order as the sidebar's Master Data group.
        { category: 'Master Data', items: props.masterData.flatMap(masterRows) },
        { category: 'HR & Finance', items: [
            { name: 'Attendance', permission: 'view attendance' },
            { name: 'Leave Requests', permission: 'view leave requests' },
            { name: 'Payroll', permission: 'manage payroll' },
            { name: 'Reports', permission: 'view reports' },
            { name: 'Notification Settings', permission: 'manage notification settings' },
        ]},
    ];
});

</script>

<template>
    <Head title="Role Management" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">Role & Permission Management</h2>
                <PrimaryButton @click="openCreateModal">Create New Role</PrimaryButton>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-4 lg:px-4 xl:max-w-[1440px] 2xl:max-w-[1600px]">
                
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg overflow-x-auto">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <h3 class="text-base sm:text-lg font-medium mb-4 text-gray-900 dark:text-gray-100">System Feature Permission Matrix</h3>

                        <table class="min-w-full border-collapse border border-gray-200 dark:border-gray-700">
                            <thead>
                                <tr class="bg-gray-100 dark:bg-gray-900">
                                    <th class="sticky top-0 z-10 border border-gray-300 dark:border-gray-600 bg-gray-100 dark:bg-gray-900 px-4 py-2 text-left w-1/4 text-xs sm:text-sm text-gray-700 dark:text-gray-200">System Feature</th>
                                    <td v-for="role in roles" :key="role.id" class="sticky top-0 z-10 border text-xs border-gray-300 dark:border-gray-600 bg-gray-100 dark:bg-gray-900 px-2 py-2 text-center align-top min-w-[120px]">
                                        <div class="font-bold mb-1 text-gray-900 dark:text-gray-100">{{ role.name }}</div>
                                        <div v-if="role.display_name" class="text-[11px] text-indigo-600 dark:text-indigo-400 font-medium mb-2 italic">{{ role.display_name }}</div>
                                        <div v-if="role.name !== 'Super System Admin' && role.name !== 'Applicant'" class="flex justify-center gap-2">
                                            <button
                                                @click="openEditModal(role)"
                                                class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 p-1 rounded hover:bg-blue-50 dark:hover:bg-blue-950/40"
                                                title="Edit Role Name"
                                            >
                                                <Pencil :size="14" />
                                            </button>
                                            <button
                                                @click="deleteRole(role)"
                                                class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 p-1 rounded hover:bg-red-50 dark:hover:bg-red-950/40"
                                                title="Delete Role"
                                            >
                                                <Trash2 :size="14" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="group in systemFeatures" :key="group.category">
                                    <tr class="bg-gray-50 dark:bg-gray-900/60">
                                        <td :colspan="roles.length + 1" class="border border-gray-300 dark:border-gray-600 px-4 py-2 font-bold text-indigo-700 dark:text-indigo-400 bg-indigo-50/50 dark:bg-indigo-950/30 uppercase tracking-wider text-xs">
                                            {{ group.category }}
                                        </td>
                                    </tr>
                                    <tr v-for="feature in group.items" :key="feature.permission || feature.name" :class="getSetRowClass(feature)">
                                        <td class="border border-gray-300 dark:border-gray-600 px-4 py-2 text-xs sm:text-sm" :class="[getSetLabelClass(feature), isParentRow(feature) && feature.setColor ? 'font-semibold pl-4' : 'font-medium pl-8']">
                                            <span v-if="isParentRow(feature) && feature.setColor"
                                                  class="inline-flex items-center gap-1.5">
                                                <span :class="['inline-block w-2 h-2 rounded-full', setColorMap[feature.setColor].dot]"></span>
                                                {{ feature.name }}
                                            </span>
                                            <span v-else>{{ feature.name }}</span>
                                        </td>
                                        <td v-for="role in roles" :key="role.id" class="border border-gray-300 dark:border-gray-600 px-4 py-2 text-center">
                                            <template v-if="feature.permission">
                                                <input
                                                    type="checkbox"
                                                    :checked="hasPermission(role, feature.permission, feature.impliedBy)"
                                                    @change="(e) => updatePermission(role, feature.permission, e.target.checked)"
                                                    class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                                    :disabled="role.name === 'Super System Admin' || isImplied(role, feature)"
                                                    :title="isImplied(role, feature) ? 'Included in Full access. Untick Full access to choose one by one.' : undefined"
                                                />
                                            </template>
                                            <template v-else>
                                                <span class="text-gray-400 dark:text-gray-500">-</span>
                                            </template>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Note: <strong>Super System Admin</strong> retains all permissions automatically to ensure full system access; changes to other roles will take effect immediately.</p>

                        <!-- Set color legend -->
                        <div class="mt-3 flex flex-wrap items-center gap-3 text-xs text-gray-600 dark:text-gray-400">
                            <span class="font-medium text-gray-500 dark:text-gray-400">Set colors:</span>
                            <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded bg-blue-100 text-blue-700 border border-blue-200 dark:bg-blue-900/40 dark:text-blue-300 dark:border-blue-800">
                                <span class="inline-block w-2 h-2 rounded-full bg-blue-400"></span> Master Data: tick a screen to let a role see it, then what it may do there. Full access allows everything on that screen
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Role Modal (Create/Edit) -->
        <Modal :show="showRoleModal" @close="showRoleModal = false">
            <div class="p-6 dark:bg-gray-800">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                    {{ isEditing ? 'Edit Role' : 'Create New Role' }}
                </h2>

                <div class="mt-6">
                    <InputLabel for="role_name" value="Role Name (System)" />

                    <TextInput
                        id="role_name"
                        type="text"
                        class="mt-1 block w-full"
                        v-model="roleForm.name"
                        placeholder="e.g. purchase_approval"
                        autofocus
                        @keyup.enter="submitRole"
                    />

                    <InputError class="mt-2" :message="roleForm.errors.name" />
                </div>

                <div class="mt-4">
                    <InputLabel for="role_display_name" value="Display Name (Shown in UI)" />

                    <TextInput
                        id="role_display_name"
                        type="text"
                        class="mt-1 block w-full"
                        v-model="roleForm.display_name"
                        placeholder="e.g. Purchase Approval Officer"
                        @keyup.enter="submitRole"
                    />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Optional. A friendly label shown in the permission matrix header.</p>

                    <InputError class="mt-2" :message="roleForm.errors.display_name" />
                </div>

                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="showRoleModal = false"> Cancel </SecondaryButton>

                    <PrimaryButton
                        class="ms-3"
                        :class="{ 'opacity-25': roleForm.processing }"
                        :disabled="roleForm.processing"
                        @click="submitRole"
                    >
                        {{ isEditing ? 'Update Role' : 'Create Role' }}
                    </PrimaryButton>
                </div>
            </div>
        </Modal>

    </AuthenticatedLayout>
</template>

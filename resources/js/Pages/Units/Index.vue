<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Modal from '@/Components/Modal.vue';
import { Phone, Mail, MessageCircle, Eye, Edit, Trash2, Building2, Users, MapPin, User, Shield, ChevronDown, ChevronUp, Search } from 'lucide-vue-next';
import { useSweetAlert } from '@/composables/useSweetAlert';


const props = defineProps({
    units: Array,
    showTrashed: Boolean,
    departments: { type: Array, default: () => [] },
    unassignedUsers: { type: Array, default: () => [] },
});

import { computed } from 'vue';

const page = usePage();

const canManage = computed(() => {
    const permissions = page.props.auth?.permissions || [];
    return permissions.includes('manage units');
});

const canCreate = computed(() => {
    const permissions = page.props.auth?.permissions || [];
    return permissions.includes('manage units') || permissions.includes('create units');
});

const canEdit = computed(() => {
    const permissions = page.props.auth?.permissions || [];
    return permissions.includes('manage units') || permissions.includes('edit units');
});

const canDelete = computed(() => {
    const permissions = page.props.auth?.permissions || [];
    return permissions.includes('manage units') || permissions.includes('delete units');
});

const canView = computed(() => {
    const permissions = page.props.auth?.permissions || [];
    return permissions.includes('manage units') || permissions.includes('view units');
});

const canAnyAction = computed(() => canView.value || canEdit.value || canDelete.value);

const search = ref('');
const statusFilter = ref('');

// Pure client-side live filtering (no reloads)
const filteredUnits = computed(() => {
    let result = props.units;
    if (statusFilter.value) {
        result = result.filter(unit => statusFilter.value === 'active' ? unit.is_active : !unit.is_active);
    }
    if (search.value.trim()) {
        const words = search.value.toLowerCase().split(/\s+/).filter(Boolean);
        result = result.filter(unit => {
            const haystack = [unit.unit_code, unit.short_form, unit.name, unit.location, unit.contact_person, unit.phone_number, unit.email]
                .map(v => (v || '').toString().toLowerCase())
                .join(' ');
            return words.every(word => haystack.includes(word));
        });
    }
    return result;
});

const showImportModal = ref(false);

// Department section helpers
const deptSearch = ref('');
const expandedDepts = ref({});
const toggleDept = (id) => { expandedDepts.value[id] = !expandedDepts.value[id]; };

const filteredDepartments = computed(() => {
    if (!deptSearch.value.trim()) return props.departments;
    const q = deptSearch.value.toLowerCase();
    return props.departments.filter(d =>
        d.name?.toLowerCase().includes(q) ||
        d.short_form?.toLowerCase().includes(q) ||
        d.location?.toLowerCase().includes(q) ||
        d.contact_person?.toLowerCase().includes(q) ||
        d.users?.some(u => u.name?.toLowerCase().includes(q))
    );
});

const roleColor = (role) => {
    const map = {
        'Super System Admin': 'bg-red-100 text-red-700 border-red-200',
        'admin': 'bg-purple-100 text-purple-700 border-purple-200',
        'accounts': 'bg-blue-100 text-blue-700 border-blue-200',
        'manager': 'bg-green-100 text-green-700 border-green-200',
    };
    return map[role] || 'bg-gray-100 text-gray-700 border-gray-200';
};
const importForm = useForm({
    file: null,
});

// Row selection for bulk delete
const selectedUnitIds = ref([]);
const isBulkDeleting = ref(false);

const allFilteredSelected = computed(() => {
    if (!filteredUnits.value.length) return false;
    return filteredUnits.value.every((unit) => selectedUnitIds.value.includes(unit.id));
});

const toggleSelectAllFiltered = () => {
    if (allFilteredSelected.value) {
        const filteredIds = new Set(filteredUnits.value.map((unit) => unit.id));
        selectedUnitIds.value = selectedUnitIds.value.filter((id) => !filteredIds.has(id));
        return;
    }
    const merged = new Set(selectedUnitIds.value);
    filteredUnits.value.forEach((unit) => merged.add(unit.id));
    selectedUnitIds.value = Array.from(merged);
};

const toggleRowSelection = (unitId) => {
    if (selectedUnitIds.value.includes(unitId)) {
        selectedUnitIds.value = selectedUnitIds.value.filter((id) => id !== unitId);
        return;
    }
    selectedUnitIds.value = [...selectedUnitIds.value, unitId];
};

const bulkDeleteSelected = async () => {
    if (!selectedUnitIds.value.length || isBulkDeleting.value) return;
    const { showTrashDeleteConfirm } = useSweetAlert();
    const result = await showTrashDeleteConfirm(`${selectedUnitIds.value.length} units`);
    if (!result.isConfirmed && !result.isDenied) return;
    isBulkDeleting.value = true;
    const idsToDelete = [...selectedUnitIds.value];
    for (let i = 0; i < idsToDelete.length; i++) {
        const id = idsToDelete[i];
        await new Promise((resolve) => {
            useForm({}).delete(route(result.isConfirmed ? 'units.destroy' : 'units.force-destroy', id), {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => resolve(),
            });
        });
    }
    selectedUnitIds.value = [];
    isBulkDeleting.value = false;
};

const openImportModal = () => {
    importForm.reset();
    importForm.clearErrors();
    showImportModal.value = true;
};

const closeImportModal = () => {
    showImportModal.value = false;
    importForm.reset();
};

const handleFileChange = (event) => {
    const file = event.target.files[0];
    if (!file) return;
    const allowedTypes = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel'
    ];
    const allowedExtensions = ['.xlsx', '.xls'];
    const fileName = file.name.toLowerCase();
    const valid = allowedExtensions.some(ext => fileName.endsWith(ext));
    if (!valid) {
        const { showError } = useSweetAlert();
        showError('Only .xlsx and .xls files are accepted.', 'Invalid file');
        event.target.value = '';
        importForm.file = null;
        return;
    }
    importForm.file = file;
};

const submitImport = () => {
    importForm.post(route('units.import'), {
        onSuccess: () => closeImportModal(),
    });
};

const deleteUnit = (unit) => {
    const { showDeleteConfirm } = useSweetAlert();
    Promise.resolve(showDeleteConfirm(`Are you sure you want to delete unit "${unit.name}"?`)).then((result) => {
        if (result.isConfirmed) {
            useForm({}).delete(route('units.destroy', unit.id));
        }
    });
};

const restoreUnit = (unit) => {
    const { showConfirm } = useSweetAlert();
    Promise.resolve(showConfirm(`Restore unit "${unit.name}"?`, 'Confirm')).then((result) => {
        if (result.isConfirmed) {
            useForm({}).post(route('units.restore', unit.id));
        }
    });
};
const getStatusBadgeClass = (isActive) => {
    return isActive 
        ? 'bg-green-100 text-green-800' 
        : 'bg-red-100 text-red-800';
};
</script>

<template>
    <Head title="Unit Management" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Unit Management</h2>
                <div class="flex gap-2">
                    <Link v-if="canDelete" :href="route('units.index', props.showTrashed ? {} : { trash: 1 })">
                        <SecondaryButton>{{ props.showTrashed ? 'Back to List' : 'Show Trash' }}</SecondaryButton>
                    </Link>
                    <SecondaryButton v-if="canCreate" @click="openImportModal">Import Excel</SecondaryButton>
                    <Link v-if="canCreate" :href="route('units.create')">
                        <PrimaryButton>Create New Unit</PrimaryButton>
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
          
            <div class="mx-auto max-w-7xl sm:px-4 lg:px-4 xl:max-w-[1440px] 2xl:max-w-[1600px]">

                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">

                    <div class="p-6 text-gray-900 dark:text-gray-100 overflow-x-auto">

                        <!-- filter section  -->
                        <div class="mb-6 flex flex-col md:flex-row md:items-center md:gap-4 gap-2 justify-between">
                            <div class="relative w-full md:w-1/3">
                                <input
                                    v-model="search"
                                    type="text"
                                    placeholder="Search particulars..."
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 pr-8"
                                    autocomplete="off"
                                />
                                <button
                                    v-if="search"
                                    @click="search = ''"
                                    type="button"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 focus:outline-none"
                                    aria-label="Clear search"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                            <select
                                v-model="statusFilter"
                                class="w-full md:w-48 px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            >
                                <option value="">All Statuses</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>


                        <div v-if="canDelete" class="mb-4 flex items-center justify-between">
                            <p class="text-sm text-gray-600 dark:text-gray-400">Selected: {{ selectedUnitIds.length }}</p>
                            <button
                                type="button"
                                @click="bulkDeleteSelected"
                                :disabled="!selectedUnitIds.length || isBulkDeleting"
                                class="inline-flex items-center rounded-md border border-red-300 dark:border-red-700 px-3 py-2 text-sm font-medium text-red-700 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                Delete Selected
                            </button>
                        </div>
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900/50">
                                <tr>
                                    <th v-if="canDelete" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-8">
                                        <input
                                            type="checkbox"
                                            :checked="allFilteredSelected"
                                            @change="toggleSelectAllFiltered"
                                            class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500"
                                        />
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Code</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Short</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Location</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Contact Person</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Contact</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                    <th v-if="canAnyAction" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="unit in filteredUnits" :key="unit.id">
                                    <td v-if="canDelete" class="px-4 py-4 whitespace-nowrap text-sm w-8">
                                        <input
                                            type="checkbox"
                                            :checked="selectedUnitIds.includes(unit.id)"
                                            @change="toggleRowSelection(unit.id)"
                                            class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500"
                                        />
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">{{ unit.unit_code }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap dark:text-gray-300">{{ unit.short_form || '-' }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap dark:text-gray-300">{{ unit.name }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap dark:text-gray-300"> {{ unit.location ? unit.location.split(' ').slice(0, 3).join(' ') + (unit.location.split(' ').length > 3 ? '...' : '') : '-' }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap dark:text-gray-300">{{ unit.contact_person || '-' }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div v-if="unit.phone_number || unit.email" class="flex items-center gap-2">
                                            <a v-if="unit.phone_number" :href="`https://wa.me/${unit.phone_number.replace(/[^0-9]/g, '')}`" target="_blank" class="text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300" title="WhatsApp">
                                                <MessageCircle :size="18" />
                                            </a>
                                            <a v-if="unit.phone_number" :href="`tel:${unit.phone_number}`" class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300" title="Call">
                                                <Phone :size="18" />
                                            </a>
                                            <a v-if="unit.email" :href="`mailto:${unit.email}`" class="text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200" title="Email">
                                                <Mail :size="18" />
                                            </a>
                                        </div>
                                        <span v-else class="text-gray-400 dark:text-gray-500">-</span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span :class="getStatusBadgeClass(unit.is_active)" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium">
                                            {{ unit.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td v-if="canAnyAction" class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="flex justify-end gap-2">
                                            <Link v-if="canView" :href="route('units.show', unit.id)" class="text-blue-600 dark:text-blue-400 hover:text-blue-900 dark:hover:text-blue-300"><Eye :size="16" class="inline" /></Link>
                                            <template v-if="!props.showTrashed">
                                                <Link v-if="canEdit" :href="route('units.edit', unit.id)" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300"><Edit :size="16" class="inline" /></Link>
                                                <button v-if="canDelete" @click="deleteUnit(unit)" class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300"><Trash2 :size="16" class="inline" /></button>
                                            </template>
                                            <template v-else>
                                                <button v-if="canDelete" @click="restoreUnit(unit)" class="text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300">Restore</button>
                                                <button v-if="canDelete" @click="useForm({}).delete(route('units.force-destroy', unit.id))" class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">Delete Permanently</button>
                                            </template>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="filteredUnits.length === 0">
                                    <td :colspan="canAnyAction ? (canDelete ? 9 : 8) : (canDelete ? 8 : 7)" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">No units found</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ===== Units / Users Section ===== -->
            <div v-if="canView && departments.length > 0" class="pt-6 mx-auto max-w-7xl sm:px-4 lg:px-4 xl:max-w-[1440px] 2xl:max-w-[1600px]">

                <!-- Section Header + Search -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-3 pb-2 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                        <Building2 :size="20" class="text-indigo-600 dark:text-indigo-400" />
                        Units Overview
                    </h3>
                    <div class="relative w-full md:w-72">
                        <Search :size="15" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500" />
                        <input
                            v-model="deptSearch"
                            type="text"
                            placeholder="Search departments or users..."
                            class="w-full pl-9 pr-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400"
                        />
                    </div>
                </div>



                <!-- Department Cards -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-3">
                    <div
                        v-for="dept in filteredDepartments"
                        :key="dept.id"
                        class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden"
                    >
                        <!-- Header Row -->
                        <div
                            class="flex items-center justify-between p-4 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                            @click="toggleDept(dept.id)"
                        >
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white font-bold text-sm shadow flex-shrink-0">
                                    {{ dept.short_form || dept.name?.charAt(0) }}
                                </div>
                                <div>
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <h4 class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ dept.name }}</h4>
                                        <span class="px-2 py-0.5 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 text-xs font-semibold rounded-full border border-indigo-200 dark:border-indigo-800">{{ dept.unit_code }}</span>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-3 mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                        <span v-if="dept.location" class="flex items-center gap-1"><MapPin :size="11" />{{ dept.location }}</span>
                                        <span v-if="dept.contact_person" class="flex items-center gap-1"><User :size="11" />{{ dept.contact_person }}</span>
                                        <span v-if="dept.phone_number" class="flex items-center gap-1"><Phone :size="11" />{{ dept.phone_number }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-5">
                                <div class="hidden md:flex items-center gap-5 text-center">
                                    <div><p class="text-xs text-gray-400 dark:text-gray-500">Users</p><p class="text-base font-bold text-gray-900 dark:text-gray-100">{{ dept.users_count }}</p></div>
                                </div>
                                <ChevronUp v-if="expandedDepts[dept.id]" :size="18" class="text-gray-400 dark:text-gray-500" />
                                <ChevronDown v-else :size="18" class="text-gray-400 dark:text-gray-500" />
                            </div>
                        </div>

                        <!-- Expanded: Ledger Transactions -->
                        <div v-if="expandedDepts[dept.id]" class="border-t border-gray-100 dark:border-gray-700">
                            <!-- Transactions Table -->
                            <div v-if="dept.ledger_transactions && dept.ledger_transactions.length > 0" class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-gray-50 dark:bg-gray-900/50 border-b border-gray-200 dark:border-gray-700">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">#</th>
                                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Date</th>
                                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Supplier</th>
                                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Doc ID</th>
                                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Method</th>
                                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Ref / Note</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                        <tr
                                            v-for="(tx, idx) in dept.ledger_transactions"
                                            :key="tx.id"
                                            class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                                        >
                                            <td class="px-4 py-2.5 text-gray-400 dark:text-gray-500 text-xs">{{ idx + 1 }}</td>
                                            <td class="px-4 py-2.5 text-gray-600 dark:text-gray-300 whitespace-nowrap text-xs">
                                                {{ new Date(tx.created_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) }}
                                            </td>
                                            <td class="px-4 py-2.5 font-medium text-gray-800 dark:text-gray-200">{{ tx.supplier_code || '-' }}</td>
                                            <td class="px-4 py-2.5 text-gray-600 dark:text-gray-300">
                                                <span v-if="tx.document_id" class="px-1.5 py-0.5 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 rounded text-xs font-mono">#{{ tx.document_id }}</span>
                                                <span v-else class="text-gray-400 dark:text-gray-500">-</span>
                                            </td>
                                            <td class="px-4 py-2.5 text-gray-600 dark:text-gray-300 capitalize text-xs">{{ tx.payment_method || '-' }}</td>
                                            <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400 text-xs max-w-[180px] truncate" :title="tx.description || tx.reference_no">
                                                {{ tx.reference_no || tx.description || '-' }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-else class="px-4 py-6 text-center text-sm text-gray-400 dark:text-gray-500 italic">
                                No ledger transactions found for this unit.
                            </div>
                        </div>
                    </div>

                    <div v-if="filteredDepartments.length === 0" class="text-center py-8 text-gray-400 dark:text-gray-500 text-sm">
                        No departments found.
                    </div>
                </div>

             
             
            </div>
            <!-- ===== End Department Section ===== -->
        </div>

        <Modal :show="showImportModal" @close="closeImportModal">
            <div class="p-6 dark:bg-gray-800">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Import Units from Excel</h2>

                <div class="mt-6">
                    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-md p-4 mb-4">
                        <div class="flex items-start">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-blue-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div class="ml-3 flex-1">
                                <p class="text-sm text-blue-700 dark:text-blue-300">
                                    Download the sample template to see the correct format for importing units.
                                </p>
                                <div class="mt-2">
                                    <a
                                        :href="route('units.template')"
                                        class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-blue-700 dark:text-blue-300 bg-blue-100 dark:bg-blue-900/40 hover:bg-blue-200 dark:hover:bg-blue-900/60 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                                    >
                                        <svg class="mr-1.5 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                        Download Sample Template
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                        Upload an Excel file (.xlsx, .xls) with unit data. Required columns: unit_code (numbers and small letters only, with at least one number, e.g. 101), name. Optional: location, contact_person, phone_number, email, is_active.
                    </p>

                    <input
                        type="file"
                        @change="handleFileChange"
                        accept=".xlsx,.xls"
                        class="block w-full text-sm text-gray-500 dark:text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 dark:file:bg-indigo-900/40 file:text-indigo-700 dark:file:text-indigo-300 hover:file:bg-indigo-100 dark:hover:file:bg-indigo-900/60"
                    />
                    <p v-if="importForm.errors.file" class="mt-2 text-sm text-red-600">{{ importForm.errors.file }}</p>
                </div>

                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="closeImportModal">Cancel</SecondaryButton>
                    <PrimaryButton 
                        class="ml-3" 
                        @click="submitImport" 
                        :class="{ 'opacity-25': importForm.processing }" 
                        :disabled="importForm.processing || !importForm.file"
                    >
                        Import
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

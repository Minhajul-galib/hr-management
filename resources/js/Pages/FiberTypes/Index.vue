<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import { useSweetAlert } from '@/composables/useSweetAlert';
import { Flame, Pencil, Plus, Trash2 } from 'lucide-vue-next';

defineProps({
    fiberTypes: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { showDeleteConfirm } = useSweetAlert();

const permissions = computed(() => page.props.auth?.permissions || []);
const roles = computed(() => page.props.auth?.roles || []);
const isSuperAdmin = computed(() => roles.value.includes('Super System Admin'));

// Legacy umbrella permission: still grants full create/edit/delete access.
const canManage = computed(
    () =>
        isSuperAdmin.value ||
        permissions.value.includes('manage fiber types') ||
        permissions.value.includes('manage bookings'),
);

const canCreate = computed(() => canManage.value || permissions.value.includes('create fiber types'));
const canEdit = computed(() => canManage.value || permissions.value.includes('edit fiber types'));
const canDelete = computed(() => canManage.value || permissions.value.includes('delete fiber types'));
const canAnyAction = computed(() => canCreate.value || canEdit.value || canDelete.value);

const modalOpen = ref(false);
const editingId = ref(null);

const form = useForm({
    name: '',
    fiber_description: '',
    excel_description: '',
    composition: '',
    is_active: true,
});

const inputCls =
    'mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';

function openCreate() {
    editingId.value = null;
    form.reset();
    form.is_active = true;
    form.clearErrors();
    modalOpen.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    form.name = row.name ?? '';
    form.fiber_description = row.fiber_description ?? '';
    form.excel_description = row.excel_description ?? '';
    form.composition = row.composition ?? '';
    form.is_active = row.is_active !== false;
    form.clearErrors();
    modalOpen.value = true;
}

function closeModal() {
    modalOpen.value = false;
    editingId.value = null;
}

function submitModal() {
    if (editingId.value ? !canEdit.value : !canCreate.value) return;
    if (editingId.value) {
        form.put(route('fiber-types.update', editingId.value), {
            preserveScroll: true,
            onSuccess: closeModal,
        });
    } else {
        form.post(route('fiber-types.store'), {
            preserveScroll: true,
            onSuccess: closeModal,
        });
    }
}

function destroyRow(row) {
    if (!canDelete.value) return;
    Promise.resolve(showDeleteConfirm(`Delete fiber type "${row.name}"?`)).then((r) => {
        if (r?.isConfirmed)
            useForm({}).delete(route('fiber-types.destroy', row.id), { preserveScroll: true });
    });
}
</script>

<template>
    <Head title="Fiber Types" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                    <Flame class="text-orange-600 dark:text-orange-400 shrink-0" :size="22" />
                    Fiber Types
                </h2>
                <div class="flex flex-wrap gap-2">
                    <PrimaryButton
                        v-if="canCreate"
                        type="button"
                        class="inline-flex items-center gap-2 !normal-case tracking-normal font-semibold"
                        @click="openCreate"
                    >
                        <Plus :size="18" /> Add fiber
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="py-6 sm:py-8">
            <div class="mx-auto max-w-7xl sm:px-4 lg:px-4 xl:max-w-[1440px] 2xl:max-w-[1600px] space-y-6">
                <div
                    class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800"
                >
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900/50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">
                                        Fiber Description
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">
                                        Fiber
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">
                                        Excel Description
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">
                                        Active
                                    </th>
                                    <th
                                        v-if="canAnyAction"
                                        class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400"
                                    >
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800"
                            >
                                <tr v-if="!fiberTypes.length">
                                    <td
                                        :colspan="(canAnyAction ? 1 : 0) + 4"
                                        class="px-6 py-16 text-center text-sm text-gray-500 dark:text-gray-400"
                                    >
                                        <div class="flex flex-col items-center gap-2">
                                            <Flame class="opacity-30" :size="40" stroke-width="1.25" />
                                            <p class="font-medium text-gray-700 dark:text-gray-300">
                                                No fiber types yet
                                            </p>
                                            <button
                                                v-if="canCreate"
                                                type="button"
                                                class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 text-sm font-medium"
                                                @click="openCreate"
                                            >
                                                Define a fiber type
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr
                                    v-for="f in fiberTypes"
                                    :key="f.id"
                                    class="transition-colors hover:bg-gray-50/80 dark:hover:bg-gray-700/40"
                                >
                                    <td class="max-w-xs px-4 py-3 text-sm text-gray-700 dark:text-gray-300 truncate" :title="f.fiber_description || ''">
                                        {{ f.fiber_description || '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100 whitespace-nowrap">
                                        {{ f.name }}
                                    </td>
                                    <td class="max-w-xs px-4 py-3 text-sm text-gray-700 dark:text-gray-300 truncate" :title="f.excel_description || ''">
                                        {{ f.excel_description || '—' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset"
                                            :class="
                                                f.is_active
                                                    ? 'bg-emerald-100 text-emerald-800 ring-emerald-600/15 dark:bg-emerald-900/40 dark:text-emerald-300'
                                                    : 'bg-gray-100 text-gray-700 ring-gray-500/15 dark:bg-gray-700 dark:text-gray-300'
                                            "
                                        >
                                            {{ f.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td v-if="canAnyAction" class="px-4 py-3 text-right whitespace-nowrap">
                                        <button
                                            v-if="canEdit"
                                            type="button"
                                            class="mr-2 inline-flex rounded-lg p-2 text-indigo-600 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-indigo-950/40"
                                            title="Edit"
                                            @click="openEdit(f)"
                                        >
                                            <Pencil :size="16" />
                                        </button>
                                        <button
                                            v-if="canDelete"
                                            type="button"
                                            class="inline-flex rounded-lg p-2 text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                                            title="Delete"
                                            @click="destroyRow(f)"
                                        >
                                            <Trash2 :size="16" />
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <Modal :show="modalOpen" max-width="lg" @close="closeModal">
            <div
                class="border-b border-gray-200 bg-gray-50 px-6 py-4 dark:border-gray-700 dark:bg-gray-900/50"
            >
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ editingId ? 'Edit fiber type' : 'New fiber type' }}
                </h3>
            </div>
            <form class="space-y-4 p-6 dark:bg-gray-800" @submit.prevent="submitModal">
                <div>
                    <InputLabel for="fiber_description" value="Fiber Description" />
                    <TextInput id="fiber_description" v-model="form.fiber_description" type="text" placeholder="e.g. 100% Cotton Combed Compact" />
                    <InputError class="mt-1" :message="form.errors.fiber_description" />
                </div>
                <div>
                    <InputLabel for="name" value="Fiber *" />
                    <TextInput id="name" v-model="form.name" type="text" required placeholder="e.g. Conventional" />
                    <InputError class="mt-1" :message="form.errors.name" />
                </div>
                <div>
                    <InputLabel for="excel_description" value="Excel Description" />
                    <TextInput id="excel_description" v-model="form.excel_description" type="text" placeholder="e.g. 100% Ctn Cmb Cmpt" />
                    <InputError class="mt-1" :message="form.errors.excel_description" />
                </div>
                <div>
                    <InputLabel for="composition" value="Composition" />
                    <textarea
                        id="composition"
                        v-model="form.composition"
                        rows="2"
                        :class="inputCls"
                        placeholder="Fiber blend / composition..."
                    />
                    <InputError class="mt-1" :message="form.errors.composition" />
                </div>
                <label
                    v-if="editingId"
                    class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700"
                >
                    <input
                        v-model="form.is_active"
                        type="checkbox"
                        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700"
                    />
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Active</span>
                </label>
                <InputError class="mt-1" :message="form.errors.is_active" />

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-5 dark:border-gray-700">
                    <SecondaryButton type="button" @click="closeModal">Cancel</SecondaryButton>
                    <PrimaryButton
                        type="submit"
                        class="!normal-case tracking-normal font-semibold"
                        :disabled="form.processing"
                        :class="{ 'opacity-50': form.processing }"
                    >
                        Save
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>

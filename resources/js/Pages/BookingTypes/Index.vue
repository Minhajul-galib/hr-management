<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import { useSweetAlert } from '@/composables/useSweetAlert';
import { Tags, Pencil, Plus, Trash2, ArrowLeftCircle } from 'lucide-vue-next';

defineProps({
    bookingTypes: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { showDeleteConfirm } = useSweetAlert();

const permissions = computed(() => page.props.auth?.permissions || []);
const canManage = computed(() => permissions.value.includes('manage bookings'));

const modalOpen = ref(false);
const editingId = ref(null);

const form = useForm({
    code: '',
    name: '',
    is_active: true,
});

function openCreate() {
    editingId.value = null;
    form.reset();
    form.code = '';
    form.name = '';
    form.is_active = true;
    form.clearErrors();
    modalOpen.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    form.code = row.code ?? '';
    form.name = row.name ?? '';
    form.is_active = row.is_active !== false;
    form.clearErrors();
    modalOpen.value = true;
}

function closeModal() {
    modalOpen.value = false;
    editingId.value = null;
}

function submitModal() {
    if (!canManage.value) return;
    if (editingId.value) {
        form.put(route('booking-types.update', editingId.value), {
            preserveScroll: true,
            onSuccess: closeModal,
        });
    } else {
        form.post(route('booking-types.store'), {
            preserveScroll: true,
            onSuccess: closeModal,
        });
    }
}

function destroyRow(row) {
    if (!canManage.value) return;
    Promise.resolve(
        showDeleteConfirm(`Delete booking type "${row.code}" — ${row.name}?`),
    ).then((r) => {
        if (r?.isConfirmed)
            useForm({}).delete(route('booking-types.destroy', row.id), { preserveScroll: true });
    });
}
</script>

<template>
    <Head title="Booking Types" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                    <Tags class="text-indigo-600 dark:text-indigo-400 shrink-0" :size="22" />
                    Booking Types
                </h2>
                <div class="flex flex-wrap gap-2">
                    <Link
                        :href="route('bookings.index')"
                        class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                    >
                        <ArrowLeftCircle :size="18" /> Bookings
                    </Link>
                    <PrimaryButton
                        v-if="canManage"
                        type="button"
                        class="inline-flex items-center gap-2 !normal-case tracking-normal font-semibold"
                        @click="openCreate"
                    >
                        <Plus :size="18" /> Add type
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
                                    <th
                                        class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400"
                                    >
                                        Code
                                    </th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400"
                                    >
                                        Name
                                    </th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400"
                                    >
                                        Active
                                    </th>
                                    <th
                                        v-if="canManage"
                                        class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400"
                                    >
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800"
                            >
                                <tr v-if="!bookingTypes.length">
                                    <td
                                        :colspan="canManage ? 4 : 3"
                                        class="px-6 py-16 text-center text-sm text-gray-500 dark:text-gray-400"
                                    >
                                        <div class="flex flex-col items-center gap-2">
                                            <Tags class="opacity-30" :size="40" stroke-width="1.25" />
                                            <p class="font-medium text-gray-700 dark:text-gray-300">
                                                No booking types (CF/CC) configured
                                            </p>
                                            <button
                                                v-if="canManage"
                                                type="button"
                                                class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 text-sm font-medium"
                                                @click="openCreate"
                                            >
                                                Add CF/CC codes
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr
                                    v-for="t in bookingTypes"
                                    :key="t.id"
                                    class="transition-colors hover:bg-gray-50/80 dark:hover:bg-gray-700/40"
                                >
                                    <td
                                        class="px-4 py-3 font-mono text-sm font-semibold text-gray-900 dark:text-gray-100"
                                    >
                                        {{ t.code }}
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-800 dark:text-gray-200">
                                        {{ t.name }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset"
                                            :class="
                                                t.is_active
                                                    ? 'bg-emerald-100 text-emerald-800 ring-emerald-600/15 dark:bg-emerald-900/40 dark:text-emerald-300'
                                                    : 'bg-gray-100 text-gray-700 ring-gray-500/15 dark:bg-gray-700 dark:text-gray-300'
                                            "
                                        >
                                            {{ t.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td v-if="canManage" class="px-4 py-3 text-right whitespace-nowrap">
                                        <button
                                            type="button"
                                            class="mr-2 inline-flex rounded-lg p-2 text-indigo-600 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-indigo-950/40"
                                            title="Edit"
                                            @click="openEdit(t)"
                                        >
                                            <Pencil :size="16" />
                                        </button>
                                        <button
                                            type="button"
                                            class="inline-flex rounded-lg p-2 text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                                            title="Delete"
                                            @click="destroyRow(t)"
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
                    {{ editingId ? 'Edit booking type' : 'New booking type' }}
                </h3>
            </div>
            <form class="space-y-4 p-6 dark:bg-gray-800" @submit.prevent="submitModal">
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <div class="sm:col-span-1">
                        <InputLabel for="code" value="Code *" />
                        <TextInput id="code" v-model="form.code" type="text" maxlength="10" required class="font-mono uppercase" />
                        <InputError class="mt-1" :message="form.errors.code" />
                    </div>
                    <div class="sm:col-span-3">
                        <InputLabel for="name" value="Name *" />
                        <TextInput id="name" v-model="form.name" type="text" required />
                        <InputError class="mt-1" :message="form.errors.name" />
                    </div>
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

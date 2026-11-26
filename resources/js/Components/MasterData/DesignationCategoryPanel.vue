<script setup>
/**
 * "Designation Categories" on the Designations page: the kinds of designation the company has
 * (Merchandising, Machine Operators, General, ...). Each category is its own ladder: its
 * designations are numbered from 1 on their own. Categories can be added, renamed, and deleted
 * while no designation belongs to them. Clicking one shows only its designations in the table.
 */
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { useSweetAlert } from '@/composables/useSweetAlert';

const props = defineProps({
    // [{ id, name, color, designations_count }]
    categories: { type: Array, required: true },
    // What this person may do with designations, which covers categories: { create, edit, delete }.
    can: { type: Object, default: () => ({}) },
    // The category the table is filtered to ('' for all).
    filter: { type: [Number, String], default: '' },
});

const emit = defineEmits(['filter']);

// Full class strings so Tailwind can see them.
const BADGE_CLASSES = {
    blue: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
    purple: 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300',
    green: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
    orange: 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-300',
    yellow: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300',
};

const inputClass =
    'block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';

// ----- add / rename ------------------------------------------------------------

// `editing` is the category being renamed, or { id: null } for a new one.
const editing = ref(null);
const form = ref(null);

const openEdit = (category) => {
    editing.value = category;
    form.value = useForm({ name: category.name });
};

const openCreate = () => openEdit({ id: null, name: '' });

const close = () => {
    editing.value = null;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: close };
    if (editing.value.id) form.value.put(route('designations.categories.update', { category: editing.value.id }), options);
    else form.value.post(route('designations.categories.store'), options);
};

// ----- delete -------------------------------------------------------------------

const remove = async (category) => {
    const { showDeleteConfirm } = useSweetAlert();
    const name = String(category.name).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const result = await showDeleteConfirm(`Delete the category "<b>${name}</b>"?`);
    if (!result.isConfirmed) return;

    if (props.filter === category.id) emit('filter', ''); // do not leave the table filtered to a category that is gone
    router.delete(route('designations.categories.destroy', { category: category.id }), { preserveScroll: true });
};

const toggleFilter = (category) => emit('filter', props.filter === category.id ? '' : category.id);
</script>

<template>
    <section class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
        <div class="p-6 text-gray-900 dark:text-gray-100">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold">Designation Categories</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Each category is its own ladder: its designations are numbered from 1 on their own, and the same title
                        can sit in more than one. Click a category to see only its designations.
                    </p>
                </div>
                <SecondaryButton v-if="can.create" type="button" @click="openCreate">
                    <Plus :size="16" class="mr-1.5" /> Add category
                </SecondaryButton>
            </div>

            <p v-if="!categories.length" class="mt-4 rounded-md border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-600 dark:text-gray-400">
                No categories yet. Add one, then put designations in it.
            </p>

            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <article
                    v-for="category in categories"
                    :key="category.id"
                    :class="[
                        'flex items-start justify-between gap-3 rounded-lg border p-3',
                        filter === category.id ? 'border-indigo-400 bg-indigo-50/50 dark:border-indigo-500 dark:bg-indigo-900/20' : 'border-gray-200 dark:border-gray-700',
                    ]"
                >
                    <button
                        type="button"
                        class="min-w-0 text-left"
                        :aria-pressed="filter === category.id"
                        :title="filter === category.id ? 'Show every category' : `Show only ${category.name}`"
                        @click="toggleFilter(category)"
                    >
                        <span :class="['inline-flex max-w-full items-center rounded-full px-2.5 py-0.5 text-sm font-semibold', BADGE_CLASSES[category.color]]">
                            <span class="truncate">{{ category.name }}</span>
                        </span>
                        <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">
                            {{ category.designations_count === 0 ? 'No designations yet' : `${category.designations_count} designation${category.designations_count === 1 ? '' : 's'}` }}
                        </span>
                    </button>

                    <div class="flex shrink-0 items-center gap-2">
                        <button
                            v-if="can.edit"
                            type="button"
                            class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                            :title="`Rename ${category.name}`"
                            @click="openEdit(category)"
                        >
                            <Pencil :size="15" />
                        </button>
                        <button
                            v-if="can.delete"
                            type="button"
                            class="text-red-600 hover:text-red-800 disabled:cursor-not-allowed disabled:opacity-30 dark:text-red-400 dark:hover:text-red-300"
                            :disabled="category.designations_count > 0"
                            :title="category.designations_count > 0 ? 'It holds designations. Move them to another category first.' : `Delete ${category.name}`"
                            @click="remove(category)"
                        >
                            <Trash2 :size="15" />
                        </button>
                    </div>
                </article>
            </div>
        </div>

        <Modal :show="editing !== null" max-width="md" @close="close">
            <form v-if="form && editing" class="p-6" @submit.prevent="submit">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ editing.id ? `Rename category: ${editing.name}` : 'Add category' }}
                </h3>

                <div class="mt-4">
                    <InputLabel for="designation_category_name" value="Name *" />
                    <input
                        id="designation_category_name"
                        v-model="form.name"
                        type="text"
                        maxlength="100"
                        placeholder="e.g. Merchandising"
                        :class="[inputClass, 'mt-1']"
                        autofocus
                    />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Designations and the Excel import pick the category by this name.</p>
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton type="button" @click="close">Cancel</SecondaryButton>
                    <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">{{ editing.id ? 'Save' : 'Add category' }}</PrimaryButton>
                </div>
            </form>
        </Modal>
    </section>
</template>

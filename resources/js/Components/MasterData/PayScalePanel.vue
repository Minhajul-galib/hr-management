<script setup>
/**
 * "Salary Formulas" on the Grades & Pay Scales page: one card per formula (Group, VBL, ...) with
 * its salary components, each a title (Basic, Gross, House Rent, ...) and a formula the user types,
 * Excel style: [Grade] * 25 + 950, [Gross] * 60%, [Basic] / 2, 2000. Every grade picks the formula
 * it is paid by, so formulas can be added, and deleted while no grade uses them. While editing,
 * the server works out the unsaved formulas for a grade (preview) so what is shown is exactly what
 * the grades list will show.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { ArrowDown, ArrowUp, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { useSweetAlert } from '@/composables/useSweetAlert';

const props = defineProps({
    // { formulas: [{ id, name, grades_count, lines: [{ key, id, title, formula, example }] }], titles, example_grade }
    payScales: { type: Object, required: true },
    // What this person may do with grades, which covers formulas: { create, edit, delete }.
    can: { type: Object, default: () => ({}) },
});

const inputClass =
    'block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';

const money = (value) => (value === null || value === undefined ? '—' : Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 }));

const EXAMPLES = ['[Grade] * 25 + 950', '[Gross] * 60%', '[Basic] / 2', '2000', '[Basic] + [House Rent] + [Conveyance]'];

// ----- edit ------------------------------------------------------------------

const editing = ref(null);
const form = ref(null);
const previewGrade = ref(String(props.payScales.example_grade));
const preview = ref({ values: {}, errors: {} });
let newLines = 0;

// `editing` is the formula being edited, or { id: null } for a new one.
const openEdit = (formula) => {
    editing.value = formula;
    previewGrade.value = String(props.payScales.example_grade);
    preview.value = { values: {}, errors: {} };
    form.value = useForm({
        name: formula.name,
        lines: formula.lines.map((line) => ({ key: line.key, id: line.id, title: line.title, formula: line.formula })),
    });
};

// A new formula starts with a Basic to show how one is written.
const openCreate = () =>
    openEdit({ id: null, name: '', grades_count: 0, lines: [{ key: `n${++newLines}`, id: null, title: 'Basic', formula: '[Grade] * 25 + 950' }] });

const close = () => {
    editing.value = null;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: close };
    if (editing.value.id) form.value.put(route('grades.pay-scales.update', { payScale: editing.value.id }), options);
    else form.value.post(route('grades.pay-scales.store'), options);
};

const remove = async (formula) => {
    const { showDeleteConfirm } = useSweetAlert();
    const name = String(formula.name).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const result = await showDeleteConfirm(`Delete the formula "<b>${name}</b>" and its components?`);
    if (result.isConfirmed) router.delete(route('grades.pay-scales.destroy', { payScale: formula.id }), { preserveScroll: true });
};

const lines = computed(() => form.value?.lines ?? []);

const titleInputs = ref([]);
const formulaInputs = ref([]);
const activeFormula = ref(null);

const addLine = () => {
    lines.value.push({ key: `n${++newLines}`, id: null, title: '', formula: '' });
    nextTick(() => titleInputs.value[lines.value.length - 1]?.focus());
};

const removeLine = (index) => {
    lines.value.splice(index, 1);
    activeFormula.value = null;
};

const moveLine = (index, by) => {
    const target = index + by;
    if (target < 0 || target >= lines.value.length) return;
    [lines.value[index], lines.value[target]] = [lines.value[target], lines.value[index]];
    activeFormula.value = null;
};

// Clicking a name puts it into the formula being edited, where the cursor is.
const names = computed(() => ['Grade', ...lines.value.map((l) => l.title.trim()).filter(Boolean)]);

const insertName = (name) => {
    const index = activeFormula.value ?? lines.value.length - 1;
    const line = lines.value[index];
    if (!line) return;

    const input = formulaInputs.value[index];
    const text = `[${name}]`;
    const start = input?.selectionStart ?? line.formula.length;
    const end = input?.selectionEnd ?? line.formula.length;
    line.formula = line.formula.slice(0, start) + text + line.formula.slice(end);

    nextTick(() => {
        input?.focus();
        input?.setSelectionRange(start + text.length, start + text.length);
    });
};

// ----- live preview: the server works out the unsaved formulas -----------------

let previewTimer = null;
let previewRun = 0;

const runPreview = () => {
    clearTimeout(previewTimer);
    if (!form.value || !editing.value) return;

    previewTimer = setTimeout(async () => {
        const run = ++previewRun;
        try {
            const { data } = await axios.post(route('grades.pay-scales.preview'), {
                lines: lines.value.map(({ key, title, formula }) => ({ key, title, formula })),
                grade: previewGrade.value,
            });
            if (run === previewRun) preview.value = data;
        } catch {
            // A failed preview only leaves the amounts blank; saving still checks everything.
            if (run === previewRun) preview.value = { values: {}, errors: {} };
        }
    }, 300);
};

watch(() => [editing.value, previewGrade.value, JSON.stringify(lines.value)], runPreview);
onBeforeUnmount(() => clearTimeout(previewTimer));

// Problems found while saving (red) win over those the preview finds while typing (amber).
const problem = (index, field) => {
    const saved = form.value?.errors[`lines.${index}.${field}`];
    if (saved) return { message: saved, saved: true };
    const typed = preview.value.errors?.[`lines.${index}.${field}`];
    return typed ? { message: typed, saved: false } : null;
};
</script>

<template>
    <section class="overflow-hidden bg-white shadow-sm dark:bg-gray-800 sm:rounded-lg">
        <div class="p-6 text-gray-900 dark:text-gray-100">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold">Salary Formulas</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Each grade is paid by the formula it is given. A formula is a set of components worked out the way you
                        write them; add one for every way of paying.
                    </p>
                </div>
                <SecondaryButton v-if="can.create" type="button" @click="openCreate">
                    <Plus :size="16" class="mr-1.5" /> Add formula
                </SecondaryButton>
            </div>

            <p v-if="!payScales.formulas.length" class="mt-4 rounded-md border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-600 dark:text-gray-400">
                No formulas yet. Add one, then give it to grades.
            </p>

            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <article
                    v-for="formula in payScales.formulas"
                    :key="formula.id"
                    class="flex flex-col rounded-lg border border-gray-200 p-4 dark:border-gray-700"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <span class="inline-flex items-center rounded-full bg-indigo-100 px-2.5 py-0.5 text-sm font-semibold text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300">
                                {{ formula.name }}
                            </span>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ formula.grades_count === 0 ? 'No grade uses it yet' : `${formula.grades_count} grade${formula.grades_count === 1 ? '' : 's'} paid by it` }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <button
                                v-if="can.edit"
                                type="button"
                                class="inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                                :title="`Edit the ${formula.name} formula`"
                                @click="openEdit(formula)"
                            >
                                <Pencil :size="14" /> Edit
                            </button>
                            <button
                                v-if="can.delete"
                                type="button"
                                class="inline-flex items-center gap-1 text-sm font-medium text-red-600 hover:text-red-800 disabled:cursor-not-allowed disabled:opacity-40 dark:text-red-400 dark:hover:text-red-300"
                                :disabled="formula.grades_count > 0"
                                :title="formula.grades_count > 0 ? 'Grades are paid by it. Give them another formula first.' : `Delete the ${formula.name} formula`"
                                @click="remove(formula)"
                            >
                                <Trash2 :size="14" /> Delete
                            </button>
                        </div>
                    </div>

                    <div v-if="formula.lines.length" class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    <th class="pb-1 font-medium">Component</th>
                                    <th class="pb-1 font-medium">Formula</th>
                                    <th class="whitespace-nowrap pb-1 text-right font-medium">Grade {{ payScales.example_grade }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="line in formula.lines" :key="line.key">
                                    <td class="whitespace-nowrap py-1.5 pr-3 font-medium">{{ line.title }}</td>
                                    <td class="py-1.5 pr-3 font-mono text-xs text-gray-600 dark:text-gray-300">{{ line.formula }}</td>
                                    <td class="py-1.5 text-right tabular-nums">{{ money(line.example) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="mt-3 text-sm text-amber-600 dark:text-amber-400">No components yet. Use Edit to add Basic, Gross, ...</p>
                </article>
            </div>
        </div>

        <Modal :show="editing !== null" max-width="5xl" @close="close">
            <form v-if="form && editing" class="p-6" @submit.prevent="submit">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ editing.id ? `Edit formula: ${editing.name}` : 'Add formula' }}
                </h3>

                <div class="mt-4 flex flex-wrap items-end justify-between gap-3">
                    <div class="w-full sm:w-72">
                        <InputLabel for="pay_scale_name" value="Name *" />
                        <input id="pay_scale_name" v-model="form.name" type="text" maxlength="100" placeholder="e.g. Group" :class="[inputClass, 'mt-1']" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Grades and the Excel import pick the formula by this name.</p>
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                        Try with grade
                        <input v-model="previewGrade" type="text" inputmode="numeric" :class="[inputClass, '!w-24']" aria-label="Grade to try the formulas with" />
                    </label>
                </div>

                <!-- How to write a formula -->
                <div class="mt-4 rounded-md bg-gray-50 p-3 text-xs text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">
                    <p>
                        Use <b>[Grade]</b> for the grade number and <b>[Title]</b> for another component, with + − * / ( ).
                        A % after a number means per cent. Functions: min, max, round. Examples:
                        <code v-for="example in EXAMPLES" :key="example" class="ml-1 rounded bg-white px-1 py-0.5 font-mono dark:bg-gray-800">{{ example }}</code>
                    </p>
                    <p class="mt-2 flex flex-wrap items-center gap-1">
                        <span>Click to put into the formula:</span>
                        <button
                            v-for="name in names"
                            :key="name"
                            type="button"
                            class="rounded border border-indigo-200 bg-white px-1.5 py-0.5 font-mono text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:bg-gray-800 dark:text-indigo-300"
                            @mousedown.prevent
                            @click="insertName(name)"
                        >
                            [{{ name }}]
                        </button>
                    </p>
                </div>

                <!-- Components -->
                <div class="mt-4 hidden grid-cols-[minmax(0,1fr)_minmax(0,2fr)_7rem_5.5rem] gap-2 px-1 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 md:grid">
                    <span>Component</span>
                    <span>Formula</span>
                    <span class="text-right">Grade {{ previewGrade || '—' }}</span>
                    <span></span>
                </div>

                <p v-if="!lines.length" class="mt-3 rounded-md border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-600 dark:text-gray-400">
                    Nothing yet. Add a component, e.g. <b>Basic</b> with <code class="font-mono">[Grade] * 25 + 950</code>.
                </p>

                <div
                    v-for="(line, index) in lines"
                    :key="line.key"
                    class="mt-2 grid grid-cols-1 items-start gap-2 rounded-md border border-gray-200 p-2 dark:border-gray-700 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_7rem_5.5rem] md:border-0 md:p-0"
                >
                    <div>
                        <input
                            :ref="(el) => (titleInputs[index] = el)"
                            v-model="line.title"
                            type="text"
                            maxlength="100"
                            placeholder="e.g. Basic"
                            :class="inputClass"
                            :aria-label="`Title of component ${index + 1}`"
                        />
                        <p
                            v-if="problem(index, 'title')"
                            :class="['mt-1 text-xs', problem(index, 'title').saved ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400']"
                        >
                            {{ problem(index, 'title').message }}
                        </p>
                    </div>
                    <div>
                        <input
                            :ref="(el) => (formulaInputs[index] = el)"
                            v-model="line.formula"
                            type="text"
                            maxlength="500"
                            placeholder="e.g. [Grade] * 25 + 950"
                            :class="[inputClass, 'font-mono']"
                            :aria-label="`Formula of ${line.title || 'component ' + (index + 1)}`"
                            @focus="activeFormula = index"
                        />
                        <p
                            v-if="problem(index, 'formula')"
                            :class="['mt-1 text-xs', problem(index, 'formula').saved ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400']"
                        >
                            {{ problem(index, 'formula').message }}
                        </p>
                    </div>
                    <div class="pt-2 text-right text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                        {{ money(preview.values?.[line.key]) }}
                    </div>
                    <div class="flex items-center justify-end gap-0.5 pt-1">
                        <button type="button" class="rounded p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30 dark:hover:text-gray-200" :disabled="index === 0" title="Move up" @click="moveLine(index, -1)">
                            <ArrowUp :size="16" />
                        </button>
                        <button type="button" class="rounded p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30 dark:hover:text-gray-200" :disabled="index === lines.length - 1" title="Move down" @click="moveLine(index, 1)">
                            <ArrowDown :size="16" />
                        </button>
                        <button type="button" class="rounded p-1 text-gray-400 hover:text-red-600 dark:hover:text-red-400" title="Remove this component" @click="removeLine(index)">
                            <Trash2 :size="16" />
                        </button>
                    </div>
                </div>

                <InputError :message="form.errors.lines" class="mt-2" />

                <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:text-indigo-800 disabled:opacity-40 dark:text-indigo-400 dark:hover:text-indigo-300"
                        :disabled="lines.length >= 30"
                        @click="addLine"
                    >
                        <Plus :size="14" /> Add component
                    </button>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        The order is only how they are listed; a formula can use a component above or below it.
                    </p>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton type="button" @click="close">Cancel</SecondaryButton>
                    <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">{{ editing.id ? 'Save formula' : 'Add formula' }}</PrimaryButton>
                </div>
            </form>
        </Modal>
    </section>
</template>

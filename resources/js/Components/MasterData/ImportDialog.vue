<script setup>
/**
 * Upload dialog for a master's Excel import. The file is only read and
 * checked here (nothing is saved); the rows come back for review on the page.
 */
import { reactive, ref, watch } from 'vue';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputError from '@/Components/InputError.vue';
import { CloudUpload, FileSpreadsheet } from 'lucide-vue-next';

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, required: true },
    previewUrl: { type: String, required: true },
    templateUrl: { type: String, required: true },
    limits: { type: Object, required: true },
    /** Sheet columns: [{ label, required }] */
    columns: { type: Array, default: () => [] },
    matchLabel: { type: String, default: 'Code' },
    singular: { type: String, default: 'record' },
    /** How many sample rows the template starts with. */
    samples: { type: Number, default: 0 },
});

const emit = defineEmits(['close', 'previewed']);

const ALLOWED_EXTENSIONS = ['xlsx', 'xls', 'csv'];

const fileInput = ref(null);
const dragging = ref(false);
const fileProblem = ref('');
// The upload goes through axios: the answer is data for the review grid, not a new page.
const form = reactive({ file: null, processing: false, progress: null, errors: {} });

const fileSize = (bytes) =>
    bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`;

const clearFile = () => {
    form.file = null;
    fileProblem.value = '';
    if (fileInput.value) fileInput.value.value = '';
};

watch(
    () => props.show,
    (open) => {
        if (open) {
            form.errors = {};
            form.progress = null;
            clearFile();
        }
    },
);

const close = () => {
    if (!form.processing) emit('close');
};

const chooseFile = (file) => {
    fileProblem.value = '';
    form.errors = {};
    if (!file) return;

    const extension = file.name.split('.').pop().toLowerCase();
    if (!ALLOWED_EXTENSIONS.includes(extension)) {
        fileProblem.value = 'Choose an Excel file (.xlsx or .xls) or a .csv file.';
        return;
    }
    if (file.size > props.limits.max_mb * 1024 * 1024) {
        fileProblem.value = `That file is larger than ${props.limits.max_mb} MB.`;
        return;
    }

    form.file = file;
};

const onDrop = (event) => {
    dragging.value = false;
    chooseFile(event.dataTransfer?.files?.[0]);
};

const submit = async () => {
    if (!form.file || form.processing) return;

    const data = new FormData();
    data.append('file', form.file);

    form.processing = true;
    form.errors = {};
    try {
        const { data: preview } = await axios.post(props.previewUrl, data, {
            headers: { Accept: 'application/json' },
            onUploadProgress: (e) => (form.progress = e.total ? { percentage: Math.round((e.loaded / e.total) * 100) } : null),
        });
        clearFile();
        emit('previewed', preview);
    } catch (error) {
        const response = error.response;
        form.errors = {
            file:
                response?.data?.errors?.file?.[0] ??
                (response?.status === 422 ? response.data?.message : null) ??
                (response?.status === 403 ? 'You are not allowed to import here.' : 'The file could not be checked. Please try again.'),
        };
    } finally {
        form.processing = false;
        form.progress = null;
    }
};
</script>

<template>
    <Modal :show="show" max-width="lg" :closeable="!form.processing" @close="close">
        <form class="p-6" @submit.prevent="submit">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ title }}</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Use the template so the columns match.
                <a :href="templateUrl" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">Download template</a>
                <template v-if="samples"> — it starts with {{ samples }} sample rows (yellow) that show how to fill it in.</template>
            </p>

            <div
                :class="[
                    'mt-5 flex cursor-pointer flex-col items-center rounded-lg border-2 border-dashed px-6 py-8 text-center transition-colors',
                    dragging ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/20' : 'border-gray-300 hover:border-indigo-400 dark:border-gray-600',
                ]"
                role="button"
                tabindex="0"
                @click="fileInput?.click()"
                @keydown.enter.prevent="fileInput?.click()"
                @keydown.space.prevent="fileInput?.click()"
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="onDrop"
            >
                <template v-if="form.file">
                    <FileSpreadsheet :size="32" class="text-green-600" />
                    <p class="mt-2 max-w-full truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ form.file.name }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ fileSize(form.file.size) }}</p>
                    <button
                        type="button"
                        class="mt-2 text-xs text-red-600 hover:underline dark:text-red-400"
                        :disabled="form.processing"
                        @click.stop="clearFile"
                    >
                        Choose a different file
                    </button>
                </template>
                <template v-else>
                    <CloudUpload :size="32" class="text-gray-400" />
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                        <span class="font-medium text-indigo-600 dark:text-indigo-400">Choose a file</span> or drop it here
                    </p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        .xlsx, .xls or .csv · up to {{ limits.max_mb }} MB · up to {{ limits.max_rows.toLocaleString() }} rows
                    </p>
                </template>

                <input
                    ref="fileInput"
                    type="file"
                    class="sr-only"
                    accept=".xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv"
                    tabindex="-1"
                    @change="chooseFile($event.target.files?.[0])"
                />
            </div>

            <InputError class="mt-2" :message="fileProblem || form.errors.file" />

            <ul class="mt-4 space-y-1 text-xs text-gray-500 dark:text-gray-400">
                <li>
                    • Columns:
                    <template v-for="(column, i) in columns" :key="column.label">
                        <b v-if="column.required">{{ column.label }}</b><span v-else>{{ column.label }}</span>{{ i < columns.length - 1 ? ', ' : '' }}
                    </template>
                    (bold = required).
                </li>
                <li>• A new {{ matchLabel }} adds a {{ singular }}; an existing {{ matchLabel }} updates it. Uploading twice is safe.</li>
                <li>• Empty optional cells keep what is already saved.</li>
                <li>• Nothing is saved yet: every row is shown for review first, with problems marked and free codes suggested.</li>
            </ul>

            <div v-if="form.processing && form.progress" class="mt-4">
                <div class="h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                    <div class="h-full bg-indigo-600 transition-all" :style="{ width: `${form.progress.percentage}%` }"></div>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton type="button" :disabled="form.processing" @click="close">Cancel</SecondaryButton>
                <PrimaryButton type="submit" :disabled="!form.file || form.processing">
                    {{ form.processing ? 'Checking…' : 'Upload & review' }}
                </PrimaryButton>
            </div>
        </form>
    </Modal>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { Upload, Download, ArrowLeft, CheckCircle2, AlertTriangle } from 'lucide-vue-next';

const props = defineProps({
  canonicalColumns: { type: Array, default: () => [] },
});

const file = ref(null);
const dragging = ref(false);

const importId = ref(null);
const importBatch = ref(null);
const headers = ref([]);
const suggestedMapping = ref({});
const mapping = ref({});
const preview = ref([]);
const totalRows = ref(0);
const totals = ref({ order_qty_kg: 0, received_qty: 0 });

const stage = ref('idle'); // idle | previewing | ready | importing | done
const errorMsg = ref(null);

const state = ref(null); // progress state from server
let pollTimer = null;

const hasUnmappedRequired = computed(() => {
  // Only PI number is required to import.
  const pi = mapping.value?.pi_number;
  return !pi;
});

const onPick = (e) => {
  const f = e.target.files?.[0];
  if (f) file.value = f;
};

const onDrop = (e) => {
  dragging.value = false;
  const f = e.dataTransfer?.files?.[0];
  if (f) file.value = f;
};

watch(file, () => {
  importId.value = null;
  importBatch.value = null;
  headers.value = [];
  suggestedMapping.value = {};
  mapping.value = {};
  preview.value = [];
  totalRows.value = 0;
  totals.value = { order_qty_kg: 0, received_qty: 0 };
  state.value = null;
  stage.value = file.value ? 'previewing' : 'idle';
  errorMsg.value = null;
  if (file.value) runPreview();
});

const runPreview = async () => {
  if (!file.value) return;
  stage.value = 'previewing';
  errorMsg.value = null;
  try {
    const fd = new FormData();
    fd.append('file', file.value);
    const res = await axios.post(route('procurement-followup.import.preview'), fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    importId.value = res.data.import_id;
    headers.value = res.data.headers || [];
    suggestedMapping.value = res.data.suggested_mapping || {};
    mapping.value = { ...(res.data.suggested_mapping || {}) };
    preview.value = res.data.preview || [];
    totalRows.value = res.data.total_rows || 0;
    totals.value = res.data.totals || { order_qty_kg: 0, received_qty: 0 };
    stage.value = 'ready';
  } catch (e) {
    errorMsg.value = e?.response?.data?.message || e?.message || 'Preview failed';
    stage.value = 'idle';
  }
};

const confirmImport = async () => {
  if (!importId.value) return;
  stage.value = 'importing';
  errorMsg.value = null;
  try {
    const res = await axios.post(route('procurement-followup.import.confirm'), {
      import_id: importId.value,
      mapping: mapping.value,
    });
    importBatch.value = res.data.import_batch;
    startPolling();
  } catch (e) {
    errorMsg.value = e?.response?.data?.message || e?.message || 'Import failed to start';
    stage.value = 'ready';
  }
};

const startPolling = () => {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = setInterval(async () => {
    try {
      const res = await axios.get(route('procurement-followup.import.progress', importId.value));
      state.value = res.data;
      if (res.data?.status === 'completed' || res.data?.status === 'failed') {
        clearInterval(pollTimer);
        pollTimer = null;
        stage.value = 'done';
      }
    } catch {
      // ignore
    }
  }, 1500);
};

const fmtCount = (n) => (n === null || n === undefined ? 0 : Number(n) || 0);
const fmtNum = (n) => (n === null || n === undefined ? '—' : Number(n).toLocaleString('en-US', { maximumFractionDigits: 3 }));
</script>

<template>
  <Head title="PROCUREMENT FOLLOWUP IMPORT" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
          <Link
            :href="route('procurement-followup.index')"
            class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-100 dark:border-gray-600 dark:hover:bg-gray-700"
          >
            <ArrowLeft :size="18" /> Back
          </Link>
          <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2 leading-tight">
            <Upload class="text-indigo-600 dark:text-indigo-400 shrink-0" :size="22" />
            Procurement Followup Import
          </h2>
        </div>

        <a
          :href="route('procurement-followup.import.template')"
          class="inline-flex items-center gap-2 rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-700"
        >
          <Download :size="18" /> Download Template
        </a>
      </div>
    </template>

    <div class="py-6 sm:py-8">
      <div class="mx-auto max-w-6xl sm:px-4 lg:px-4 space-y-6">
        <div v-if="errorMsg" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800/40 dark:bg-red-900/20 dark:text-red-200">
          {{ errorMsg }}
        </div>

        <div
          class="rounded-xl border border-dashed p-8 text-center transition-colors"
          :class="dragging ? 'border-indigo-500 bg-indigo-50/60 dark:bg-indigo-900/10' : 'border-gray-300 bg-white dark:bg-gray-800 dark:border-gray-700'"
          @dragenter.prevent="dragging = true"
          @dragover.prevent="dragging = true"
          @dragleave.prevent="dragging = false"
          @drop.prevent="onDrop"
        >
          <div class="mx-auto max-w-md space-y-2">
            <div class="mx-auto w-12 h-12 rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center dark:bg-indigo-900/30 dark:text-indigo-300">
              <Upload :size="22" />
            </div>
            <div class="text-base font-semibold text-gray-900 dark:text-gray-100">Drag & drop Excel/CSV here</div>
            <div class="text-sm text-gray-600 dark:text-gray-300">or choose a file to preview all rows before importing.</div>
            <div class="pt-2">
              <input type="file" accept=".xlsx,.csv" class="block w-full text-sm text-gray-700 dark:text-gray-200" @change="onPick" />
            </div>
            <div v-if="file" class="text-xs text-gray-500 dark:text-gray-400 pt-1">Selected: {{ file.name }}</div>
          </div>
        </div>

        <div v-if="stage !== 'idle' && importId" class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-4 sm:p-5 space-y-4">
          <div class="flex items-center justify-between gap-3 flex-wrap">
            <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">
              Import ID: <span class="font-mono text-xs">{{ importId }}</span>
              <span v-if="importBatch" class="ml-2 text-xs text-gray-500 dark:text-gray-400">Batch: {{ importBatch }}</span>
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400" v-if="stage === 'previewing'">Reading file & building preview…</div>
            <div class="text-xs text-gray-500 dark:text-gray-400" v-else-if="stage === 'ready'">Adjust mapping if needed, then import.</div>
            <div class="text-xs text-gray-500 dark:text-gray-400" v-else-if="stage === 'importing'">Import running…</div>
          </div>

          <!-- Column mapping -->
          <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <div class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3">Column mapping</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-x-6 gap-y-2">
              <div v-for="col in canonicalColumns" :key="col" class="grid grid-cols-12 gap-2 items-center">
                <div class="col-span-5 text-xs font-medium text-gray-600 dark:text-gray-300 truncate">
                  {{ col }}
                  <span v-if="col === 'pi_number'" class="text-red-600 dark:text-red-300">*</span>
                </div>
                <div class="col-span-7">
                  <select v-model="mapping[col]" class="w-full rounded-md border-gray-300 text-xs shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    <option :value="null">— not mapped —</option>
                    <option v-for="h in headers" :key="h" :value="h">{{ h }}</option>
                  </select>
                </div>
              </div>
            </div>
            <div v-if="hasUnmappedRequired" class="mt-3 text-xs text-amber-700 dark:text-amber-300 flex items-center gap-2">
              <AlertTriangle :size="16" />
              Map at least <code>pi_number</code> to enable import.
            </div>
          </div>

          <!-- Full preview -->
          <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <div class="flex items-center justify-between flex-wrap gap-3 mb-3">
              <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                Full Preview
                <span v-if="totalRows" class="ml-1 text-xs font-normal text-gray-500 dark:text-gray-400">({{ totalRows }} rows)</span>
              </div>
              <!-- Totals summary -->
              <div v-if="totalRows" class="flex flex-wrap gap-4 text-xs font-medium">
                <span class="text-gray-700 dark:text-gray-200">
                  Total <span class="font-semibold">order_qty_kg</span>:
                  <strong class="text-indigo-700 dark:text-indigo-300 ml-1">{{ fmtNum(totals.order_qty_kg) }}</strong>
                </span>
                <span class="text-gray-700 dark:text-gray-200">
                  Total <span class="font-semibold">received_qty</span>:
                  <strong class="text-emerald-700 dark:text-emerald-300 ml-1">{{ fmtNum(totals.received_qty) }}</strong>
                </span>
              </div>
            </div>

            <!-- Legend -->
            <div class="flex flex-wrap gap-3 mb-2 text-[11px]">
              <span class="flex items-center gap-1.5"><span class="inline-block w-3 h-3 rounded bg-red-100 dark:bg-red-900/30 border border-red-300 dark:border-red-700"></span> Validation error</span>
              <span class="flex items-center gap-1.5"><span class="inline-block w-3 h-3 rounded bg-amber-100 dark:bg-amber-900/30 border border-amber-300 dark:border-amber-700"></span> Possible issue (e.g. number couldn't be parsed)</span>
            </div>

            <div class="overflow-auto max-h-[540px]">
              <table class="min-w-[1100px] w-full text-xs">
                <thead class="sticky top-0 z-10 bg-white dark:bg-gray-800">
                  <tr class="text-left text-gray-600 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700">
                    <th class="py-2 pr-3 whitespace-nowrap">#</th>
                    <th v-for="col in canonicalColumns" :key="col" class="py-2 pr-3 whitespace-nowrap">{{ col }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                  <tr
                    v-for="r in preview"
                    :key="r.row"
                    :class="Object.keys(r.errors || {}).length
                      ? 'bg-red-50 dark:bg-red-900/10'
                      : Object.keys(r.warnings || {}).length
                        ? 'bg-amber-50 dark:bg-amber-900/10'
                        : ''"
                  >
                    <td class="py-1.5 pr-3 font-mono text-gray-500 dark:text-gray-400">{{ r.row }}</td>
                    <td v-for="col in canonicalColumns" :key="col" class="py-1.5 pr-3 whitespace-nowrap">
                      <span
                        :class="(r.errors || {})[col]
                          ? 'text-red-700 dark:text-red-300 font-semibold'
                          : (r.warnings || {})[col]
                            ? 'text-amber-700 dark:text-amber-300 font-semibold'
                            : 'text-gray-800 dark:text-gray-100'"
                        :title="(r.errors || {})[col]
                          ? Object.values((r.errors || {})[col]).flat().join('; ')
                          : (r.warnings || {})[col] || ''"
                      >
                        {{ r.data?.[col] ?? '—' }}
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="mt-2 flex flex-wrap gap-4 text-[11px] text-gray-500 dark:text-gray-400">
              <span>Hover over a red/amber cell to see the issue.</span>
              <span v-if="preview.filter(r => Object.keys(r.errors || {}).length).length" class="text-red-600 dark:text-red-400 font-medium">
                {{ preview.filter(r => Object.keys(r.errors || {}).length).length }} row(s) with errors
              </span>
              <span v-if="preview.filter(r => !Object.keys(r.errors || {}).length && Object.keys(r.warnings || {}).length).length" class="text-amber-600 dark:text-amber-400 font-medium">
                {{ preview.filter(r => !Object.keys(r.errors || {}).length && Object.keys(r.warnings || {}).length).length }} row(s) with warnings
              </span>
            </div>
          </div>

          <div class="flex items-center justify-end gap-2">
            <button
              type="button"
              class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-100 dark:border-gray-600 dark:hover:bg-gray-700"
              @click="runPreview"
              :disabled="stage === 'previewing' || !file"
            >
              Rebuild Preview
            </button>
            <button
              type="button"
              class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
              @click="confirmImport"
              :disabled="stage !== 'ready' || hasUnmappedRequired"
            >
              <CheckCircle2 :size="18" /> Confirm Import
            </button>
          </div>

          <div v-if="state" class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3">

            <!-- Status bar -->
            <div class="flex items-center justify-between flex-wrap gap-2">
              <div class="text-sm font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                <span>Progress:</span>
                <span
                  class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold"
                  :class="{
                    'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300': state.status === 'running' || state.status === 'queued',
                    'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300': state.status === 'completed',
                    'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300': state.status === 'failed',
                  }"
                >{{ state.status }}</span>
              </div>
              <div class="flex flex-wrap gap-3 text-xs font-medium">
                <span class="text-gray-500 dark:text-gray-400">Processed: <strong class="text-gray-800 dark:text-gray-100">{{ fmtCount(state.total_processed) }}</strong></span>
                <span class="text-emerald-600 dark:text-emerald-400">Success: <strong>{{ fmtCount(state.success) }}</strong></span>
                <span class="text-sky-600 dark:text-sky-400">Updated: <strong>{{ fmtCount(state.updated) }}</strong></span>
                <span class="text-red-600 dark:text-red-400">Failed: <strong>{{ fmtCount(state.failed) }}</strong></span>
              </div>
            </div>

            <!-- Progress bar -->
            <div class="h-2 w-full rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
              <div
                class="h-2 rounded-full transition-all duration-500"
                :class="state.status === 'completed' ? 'bg-emerald-500' : state.status === 'failed' ? 'bg-red-500' : 'bg-indigo-500 animate-pulse'"
                :style="{ width: state.status === 'completed' || state.status === 'failed' ? '100%' : '45%' }"
              />
            </div>

            <!-- Completed message -->
            <div v-if="state.status === 'completed' && fmtCount(state.failed) === 0" class="flex items-center gap-2 text-sm text-emerald-700 dark:text-emerald-300">
              <CheckCircle2 :size="16" />
              All {{ fmtCount(state.success) }} rows imported successfully.
              <Link :href="route('procurement-followup.index')" class="underline ml-1">Go to list →</Link>
            </div>
            <div v-else-if="state.status === 'completed' && fmtCount(state.success) > 0" class="flex items-center gap-2 text-sm text-amber-700 dark:text-amber-300">
              <AlertTriangle :size="16" />
              {{ fmtCount(state.success) }} rows imported, {{ fmtCount(state.failed) }} failed.
              <Link :href="route('procurement-followup.index')" class="underline ml-1">Go to list →</Link>
            </div>

            <!-- Job-level failure -->
            <div v-if="state.status === 'failed'" class="flex items-center gap-2 text-sm text-red-700 dark:text-red-300 bg-red-50 dark:bg-red-900/20 rounded-lg p-3">
              <AlertTriangle :size="16" class="shrink-0" />
              <span>Job failed: {{ state.message || 'Unknown error' }}</span>
            </div>

            <!-- Row-level errors table -->
            <div v-if="state.errors && state.errors.length" class="space-y-2">
              <div class="flex items-center justify-between">
                <div class="text-xs font-semibold text-red-700 dark:text-red-300 flex items-center gap-1.5">
                  <AlertTriangle :size="14" />
                  Row validation errors (showing up to {{ state.errors.length }})
                </div>
              </div>
              <div class="overflow-auto max-h-72 rounded-lg border border-red-200 dark:border-red-800/50">
                <table class="min-w-full text-xs">
                  <thead class="bg-red-50 dark:bg-red-900/20 sticky top-0">
                    <tr>
                      <th class="px-3 py-2 text-left font-semibold text-red-700 dark:text-red-300 whitespace-nowrap">Row #</th>
                      <th class="px-3 py-2 text-left font-semibold text-red-700 dark:text-red-300 whitespace-nowrap">PI Number</th>
                      <th class="px-3 py-2 text-left font-semibold text-red-700 dark:text-red-300">Validation Errors</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-red-100 dark:divide-red-900/30 bg-white dark:bg-gray-800">
                    <tr v-for="err in state.errors" :key="err.row">
                      <td class="px-3 py-2 font-mono text-gray-700 dark:text-gray-300 whitespace-nowrap">{{ err.row }}</td>
                      <td class="px-3 py-2 text-gray-700 dark:text-gray-300 whitespace-nowrap">{{ err.pi_number || '—' }}</td>
                      <td class="px-3 py-2 text-red-700 dark:text-red-300">
                        <div v-for="(msgs, field) in err.errors" :key="field" class="mb-0.5 last:mb-0">
                          <span class="font-semibold">{{ field }}:</span>
                          {{ Array.isArray(msgs) ? msgs.join(', ') : msgs }}
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>


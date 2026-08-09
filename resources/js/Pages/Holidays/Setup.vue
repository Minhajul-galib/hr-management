<script setup>
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
  settings: {
    type: Object,
    default: () => ({ all_fridays: false, weekday_off: [] })
  },
  canManage: {
    type: Boolean,
    default: false
  }
});

const page = usePage();
const permissions = computed(() => page.props.auth?.permissions || []);
const roles = computed(() => page.props.auth?.roles || []);
const canManage = computed(() => props.canManage || permissions.value.includes('manage holidays') || roles.value.includes('Super System Admin'));

const form = ref({
  all_fridays: !!props.settings.all_fridays,
  weekday_off: Array.isArray(props.settings.weekday_off) ? [...props.settings.weekday_off] : []
});

const days = [
  { label: 'Sunday', value: 0 },
  { label: 'Monday', value: 1 },
  { label: 'Tuesday', value: 2 },
  { label: 'Wednesday', value: 3 },
  { label: 'Thursday', value: 4 },
  { label: 'Friday', value: 5 },
  { label: 'Saturday', value: 6 },
];

function toggleDay(val) {
  const idx = form.value.weekday_off.indexOf(val);
  if (idx === -1) form.value.weekday_off.push(val);
  else form.value.weekday_off.splice(idx, 1);
}

function saveSettings() {
  router.post(route('calendar.holidays.settings.update'), form.value, {
    preserveScroll: true
  });
}

const fileInput = ref(null);
function importCSV() {
  const file = fileInput.value?.files?.[0];
  if (!file) return;
  const fd = new FormData();
  fd.append('file', file);
  router.post(route('calendar.holidays.import'), fd, {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => {
      fileInput.value.value = '';
    }
  });
}

// Single-day import
const single = ref({ date: '', title: '' });
function importSingle() {
  if (!single.value.date) return alert('Please select a date');
  router.post(route('calendar.holidays.import_single'), single.value, {
    preserveScroll: true,
    onSuccess: () => {
      single.value.date = '';
      single.value.title = '';
    }
  });
}

// Download sample CSV
function downloadSample() {
  const sample = '2026-01-26,Republic Day\n2026-03-26,Independence Day';
  const blob = new Blob([sample], { type: 'text/csv' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'holidays-sample.csv';
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}
</script>

<template>
  <Head title="Holidays Setup" />
  <AuthenticatedLayout>
    <template #header>
      <div class="flex items-center justify-between">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-100">
          Holidays Setup
        </h2>
      </div>
    </template>

    <div class="py-6">
      <div class="mx-auto max-w-7xl sm:px-4 lg:px-4 xl:max-w-[1440px] 2xl:max-w-[1600px]">
        <div class="bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 rounded-lg shadow-sm p-6">
          <div v-if="!canManage" class="text-red-600 dark:text-red-400">
            You do not have permission to manage holidays.
          </div>

          <div v-else class="space-y-8">
            <div>
              <h3 class="text-lg font-semibold mb-3">Weekly Off Days</h3>
              <div class="flex items-center gap-3 mb-4">
                <input id="all-fridays" type="checkbox" v-model="form.all_fridays" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700" />
                <label for="all-fridays" class="text-sm">All Fridays off</label>
              </div>
              <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                <label v-for="d in days" :key="d.value" class="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    :checked="form.weekday_off.includes(d.value)"
                    @change="toggleDay(d.value)"
                    class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700"
                  />
                  <span>{{ d.label }}</span>
                </label>
              </div>
              <div class="mt-4">
                <button
                  @click="saveSettings"
                  class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors"
                >
                  Save Settings
                </button>
              </div>
            </div>

            <div class="border-t dark:border-gray-700 pt-6">
              <h3 class="text-lg font-semibold mb-3">Bulk Import Single-Day Holidays</h3>
              <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                Upload a CSV file with rows like: 2026-01-26, Republic Day
              </p>
              <div class="space-y-4">
                <!-- Single-day import form -->
                <div class="flex flex-col sm:flex-row items-center gap-3">
                    <input id="single-date" type="date" v-model="single.date" class="border rounded px-3 py-2 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                    <input id="single-title" type="text" v-model="single.title" placeholder="Title (optional)" class="border rounded px-3 py-2 flex-1 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                    <button @click="importSingle" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Add Holiday</button>
                </div>

                <div class="flex items-center gap-3">
                    <input ref="fileInput" type="file" accept=".csv,text/csv" class="text-sm" />
                    <button
                      @click="importCSV"
                      class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors"
                    >
                      Import CSV
                    </button>

                    <button @click="downloadSample" class="ms-4 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-200 px-3 py-2 rounded text-sm">Download sample CSV</button>
                </div>

                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Sample CSV format (date,title) — date can be any parseable date, e.g.: <code>2026-01-26,Republic Day</code></p>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
  </template>

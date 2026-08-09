<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, reactive, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useSweetAlert } from '@/composables/useSweetAlert';
import {
  BarChart3,
  ClipboardList,
  Download,
  Eye,  
  Filter,
  Plus,
  Pencil,
  RotateCcw,
  Trash2,
  Upload,
} from 'lucide-vue-next';

const props = defineProps({
  followups: { type: Object, required: true },
  filters: { type: Object, default: () => ({}) },
  filterOptions: { type: Object, default: () => ({ piYears: [], fiscalYears: [], suppliers: [], merchantTeams: [] }) },
  summary: { type: Object, default: () => ({}) },
  serverToday: { type: String, default: null },
  showTrashed: { type: Boolean, default: false },
  yarnSuppliers: { type: Array, default: () => [] },
  merchantTeams: { type: Array, default: () => [] },
  yarnCounts: { type: Array, default: () => [] },
  fiberTypes: { type: Array, default: () => [] },
  merchandiserOptions: { type: Array, default: () => [] },
});

const page = usePage();
const { showDeleteConfirm, showConfirm } = useSweetAlert();

const permissions = computed(() => page.props.auth?.permissions || []);
const roles = computed(() => page.props.auth?.roles || []);
const isSuperAdmin = computed(() => roles.value.includes('Super System Admin'));

const canViewProcurement = computed(
  () =>
    isSuperAdmin.value ||
    permissions.value.includes('manage procurement followup') ||
    permissions.value.includes('view procurement followup') ||
    permissions.value.includes('manage bookings') ||
    permissions.value.includes('view bookings'),
);

// Legacy umbrella permission: still grants full create/edit/delete/import/export access.
const canManageProcurement = computed(
  () =>
    isSuperAdmin.value ||
    permissions.value.includes('manage procurement followup') ||
    permissions.value.includes('manage bookings'),
);

const canViewAnalysis = computed(
  () =>
    isSuperAdmin.value ||
    permissions.value.includes('view pi wise analysis') ||
    permissions.value.includes('manage procurement followup') ||
    permissions.value.includes('manage bookings'),
);

const canView = computed(() => canViewProcurement.value);
const canManage = computed(() => canManageProcurement.value);

// Per-action permissions, each also granted by the legacy "manage" umbrella.
const canCreate = computed(
  () => canManage.value || permissions.value.includes('create procurement followup'),
);
const canEdit = computed(
  () => canManage.value || permissions.value.includes('edit procurement followup'),
);
const canDelete = computed(
  () => canManage.value || permissions.value.includes('delete procurement followup'),
);
const canImport = computed(
  () => canManage.value || permissions.value.includes('import procurement followup'),
);
const canExport = computed(
  () => canManage.value || permissions.value.includes('export procurement followup'),
);

const canAnyAction = computed(
  () =>
    canView.value ||
    canCreate.value ||
    canEdit.value ||
    canDelete.value ||
    canImport.value ||
    canExport.value,
);

const rows = computed(() => props.followups?.data ?? []);

const filterState = reactive({
  pi_year:      props.filters?.pi_year       ?? '',
  pi_month:     props.filters?.pi_month      ?? '',
  pi_number:    props.filters?.pi_number     ?? '',
  supplier:     props.filters?.supplier      ?? '',
  merchandiser: props.filters?.merchandiser  ?? '',
  merchant_team:props.filters?.merchant_team ?? '',
  fiber:        props.filters?.fiber         ?? '',
  yarn_count:   props.filters?.yarn_count    ?? '',
});

const perPage = ref(props.filters?.per_page ?? 50);

watch(
  () => props.filters,
  (f) => {
    filterState.pi_year       = f?.pi_year       ?? '';
    filterState.pi_month      = f?.pi_month      ?? '';
    filterState.pi_number     = f?.pi_number     ?? '';
    filterState.supplier      = f?.supplier      ?? '';
    filterState.merchandiser  = f?.merchandiser  ?? '';
    filterState.merchant_team = f?.merchant_team ?? '';
    filterState.fiber         = f?.fiber         ?? '';
    filterState.yarn_count    = f?.yarn_count    ?? '';
  },
  { deep: true },
);

const buildQuery = (includeTrash = true) => {
  const q = {};
  if (filterState.pi_year)       q.pi_year       = filterState.pi_year;
  if (filterState.pi_month)      q.pi_month      = filterState.pi_month;
  if (filterState.pi_number)     q.pi_number     = filterState.pi_number;
  if (filterState.supplier)      q.supplier      = filterState.supplier;
  if (filterState.merchandiser)  q.merchandiser  = filterState.merchandiser;
  if (filterState.merchant_team) q.merchant_team = filterState.merchant_team;
  if (filterState.fiber)         q.fiber         = filterState.fiber;
  if (filterState.yarn_count)    q.yarn_count    = filterState.yarn_count;
  if (perPage.value)            q.per_page     = perPage.value;
  if (includeTrash && props.showTrashed) q.trash = 1;
  return q;
};

let applyTimer = null;
const scheduleApply = () => {
  clearTimeout(applyTimer);
  applyTimer = setTimeout(() => {
    router.get(route('procurement-followup.index'), buildQuery(), { preserveState: true, replace: true });
  }, 280);
};

watch(
  () => filterState.pi_year,
  (newVal, oldVal) => {
    if (newVal === oldVal) return;
    filterState.pi_month = '';
  },
);

watch(() => [filterState.pi_year, filterState.pi_month, filterState.pi_number, filterState.supplier, filterState.merchandiser, filterState.merchant_team, filterState.fiber, filterState.yarn_count], scheduleApply);
watch(() => perPage.value, () => scheduleApply());
onUnmounted(() => clearTimeout(applyTimer));

const clearFilters = () => {
  filterState.pi_year       = '';
  filterState.pi_month      = '';
  filterState.pi_number     = '';
  filterState.supplier      = '';
  filterState.merchandiser  = '';
  filterState.merchant_team = '';
  filterState.fiber         = '';
  filterState.yarn_count    = '';
  router.get(route('procurement-followup.index'), props.showTrashed ? { trash: 1 } : {}, {
    preserveState: true,
    replace: true,
  });
};

const monthOptions = [
  { value: '1', label: 'Jan' },
  { value: '2', label: 'Feb' },
  { value: '3', label: 'Mar' },
  { value: '4', label: 'Apr' },
  { value: '5', label: 'May' },
  { value: '6', label: 'Jun' },
  { value: '7', label: 'Jul' },
  { value: '8', label: 'Aug' },
  { value: '9', label: 'Sep' },
  { value: '10', label: 'Oct' },
  { value: '11', label: 'Nov' },
  { value: '12', label: 'Dec' },
];
const toggleTrashHref = computed(() => {
  const q = buildQuery(false);
  if (props.showTrashed) return route('procurement-followup.index', q);
  return route('procurement-followup.index', { ...q, trash: 1 });
});

const supplierMap = computed(() => {
  const map = {};
  props.yarnSuppliers.forEach((s) => { if (s.code) map[s.code] = s.name; });
  return map;
});

const fiberNames = computed(() => props.fiberTypes.map((f) => f.name));

const resolveSupplier = (val) => {
  if (!val) return '';
  return supplierMap.value[val] || val;
};

const supplierSearch = ref('');
const supplierDropdownOpen = ref(false);

const merchantTeamMap = computed(() => {
  const map = {};
  props.merchantTeams.forEach((t) => { if (t.code) map[t.code] = t.name; });
  return map;
});

const resolveMerchantTeam = (val) => {
  if (!val) return '';
  return merchantTeamMap.value[val] || val;
};

const merchantTeamSearch = ref('');
const merchantTeamDropdownOpen = ref(false);

const filteredMerchantTeams = computed(() => {
  const q = merchantTeamSearch.value.toLowerCase();
  if (!q) return props.merchantTeams;
  return props.merchantTeams.filter(
    (t) => t.name.toLowerCase().includes(q) || (t.code || '').toLowerCase().includes(q),
  );
});

const selectMerchantTeam = (t) => {
  editForm.merchant_team = t.code;
  merchantTeamSearch.value = t.name;
  merchantTeamDropdownOpen.value = false;
};

const onMerchantTeamBlur = () => {
  setTimeout(() => {
    merchantTeamDropdownOpen.value = false;
    merchantTeamSearch.value = resolveMerchantTeam(editForm.merchant_team);
  }, 200);
};

const yarnCountMap = computed(() => {
  const map = {};
  props.yarnCounts.forEach((c) => { if (c.code) map[c.code] = c.count_value; });
  return map;
});

const resolveYarnCount = (val) => {
  if (!val) return '';
  return yarnCountMap.value[val] || val;
};

const yarnCountSearch = ref('');
const yarnCountDropdownOpen = ref(false);

const filteredYarnCounts = computed(() => {
  const q = yarnCountSearch.value.toLowerCase();
  if (!q) return props.yarnCounts;
  return props.yarnCounts.filter(
    (c) => c.count_value.toLowerCase().includes(q) || (c.code || '').toLowerCase().includes(q),
  );
});

const selectYarnCount = (c) => {
  editForm.yarn_count = c.code;
  yarnCountSearch.value = c.count_value;
  yarnCountDropdownOpen.value = false;
};

const onYarnCountBlur = () => {
  setTimeout(() => {
    yarnCountDropdownOpen.value = false;
    yarnCountSearch.value = resolveYarnCount(editForm.yarn_count);
  }, 200);
};

const merchandiserSearch = ref('');
const merchandiserDropdownOpen = ref(false);

const filteredMerchandisers = computed(() => {
  const q = merchandiserSearch.value.toLowerCase();
  if (!q) return props.merchandiserOptions;
  return props.merchandiserOptions.filter((m) => m.toLowerCase().includes(q));
});

const selectMerchandiser = (m) => {
  editForm.merchandiser = m;
  merchandiserSearch.value = m;
  merchandiserDropdownOpen.value = false;
};

const onMerchandiserBlur = () => {
  setTimeout(() => {
    merchandiserDropdownOpen.value = false;
    if (!editForm.merchandiser) merchandiserSearch.value = '';
  }, 200);
};

const fiberTypeSearch = ref('');
const fiberTypeDropdownOpen = ref(false);

const filteredFiberTypes = computed(() => {
  const q = fiberTypeSearch.value.toLowerCase();
  if (!q) return props.fiberTypes;
  return props.fiberTypes.filter(
    (f) =>
      (f.fiber_description || '').toLowerCase().includes(q) ||
      (f.name || '').toLowerCase().includes(q),
  );
});

const selectFiberType = (f) => {
  editForm.fiber_description = f.fiber_description ?? '';
  editForm.fiber = f.name ?? '';
  fiberTypeSearch.value = f.fiber_description ?? '';
  fiberTypeDropdownOpen.value = false;
};

const onFiberTypeBlur = () => {
  setTimeout(() => {
    fiberTypeDropdownOpen.value = false;
    fiberTypeSearch.value = editForm.fiber_description;
  }, 200);
};

// ── CREATE FORM search state ──────────────────────────────────────────────────
const cSupplierSearch = ref('');
const cSupplierOpen = ref(false);
const cMerchandiserSearch = ref('');
const cMerchandiserOpen = ref(false);
const cMerchantTeamSearch = ref('');
const cMerchantTeamOpen = ref(false);
const cYarnCountSearch = ref('');
const cYarnCountOpen = ref(false);
const cFiberSearch = ref('');
const cFiberOpen = ref(false);

const filteredCSuppliers = computed(() => {
  const q = cSupplierSearch.value.toLowerCase();
  if (!q) return props.yarnSuppliers;
  return props.yarnSuppliers.filter((s) => s.name.toLowerCase().includes(q) || (s.code || '').toLowerCase().includes(q));
});
const filteredCMerchandisers = computed(() => {
  const q = cMerchandiserSearch.value.toLowerCase();
  if (!q) return props.merchandiserOptions;
  return props.merchandiserOptions.filter((m) => m.toLowerCase().includes(q));
});
const filteredCMerchantTeams = computed(() => {
  const q = cMerchantTeamSearch.value.toLowerCase();
  if (!q) return props.merchantTeams;
  return props.merchantTeams.filter((t) => t.name.toLowerCase().includes(q) || (t.code || '').toLowerCase().includes(q));
});
const filteredCYarnCounts = computed(() => {
  const q = cYarnCountSearch.value.toLowerCase();
  if (!q) return props.yarnCounts;
  return props.yarnCounts.filter((c) => c.count_value.toLowerCase().includes(q) || (c.code || '').toLowerCase().includes(q));
});
const filteredCFiberTypes = computed(() => {
  const q = cFiberSearch.value.toLowerCase();
  if (!q) return props.fiberTypes;
  return props.fiberTypes.filter((f) => (f.fiber_description || '').toLowerCase().includes(q) || (f.name || '').toLowerCase().includes(q));
});

const selectCSupplier = (s) => { createForm.supplier = s.code; cSupplierSearch.value = s.name; cSupplierOpen.value = false; };
const selectCMerchandiser = (m) => { createForm.merchandiser = m; cMerchandiserSearch.value = m; cMerchandiserOpen.value = false; };
const selectCMerchantTeam = (t) => { createForm.merchant_team = t.code; cMerchantTeamSearch.value = t.name; cMerchantTeamOpen.value = false; };
const selectCYarnCount = (c) => { createForm.yarn_count = c.code; cYarnCountSearch.value = c.count_value; cYarnCountOpen.value = false; };
const selectCFiberType = (f) => { createForm.fiber_description = f.fiber_description ?? ''; createForm.fiber = f.name ?? ''; cFiberSearch.value = f.fiber_description ?? ''; cFiberOpen.value = false; };

const onCSupplierBlur = () => setTimeout(() => { cSupplierOpen.value = false; cSupplierSearch.value = resolveSupplier(createForm.supplier); }, 200);
const onCMerchandiserBlur = () => setTimeout(() => { cMerchandiserOpen.value = false; }, 200);
const onCMerchantTeamBlur = () => setTimeout(() => { cMerchantTeamOpen.value = false; cMerchantTeamSearch.value = resolveMerchantTeam(createForm.merchant_team); }, 200);
const onCYarnCountBlur = () => setTimeout(() => { cYarnCountOpen.value = false; cYarnCountSearch.value = resolveYarnCount(createForm.yarn_count); }, 200);
const onCFiberBlur = () => setTimeout(() => { cFiberOpen.value = false; cFiberSearch.value = createForm.fiber_description; }, 200);
// ─────────────────────────────────────────────────────────────────────────────

const filteredSuppliers = computed(() => {
  const q = supplierSearch.value.toLowerCase();
  if (!q) return props.yarnSuppliers;
  return props.yarnSuppliers.filter(
    (s) => s.name.toLowerCase().includes(q) || (s.code || '').toLowerCase().includes(q),
  );
});

const selectSupplier = (s) => {
  editForm.supplier = s.code;
  supplierSearch.value = s.name;
  supplierDropdownOpen.value = false;
};

const onSupplierBlur = () => {
  setTimeout(() => {
    supplierDropdownOpen.value = false;
    supplierSearch.value = resolveSupplier(editForm.supplier);
  }, 200);
};

const fmtDate = (val) => {
  if (!val) return '-';
  const d = typeof val === 'string' ? val.split('T')[0] : val;
  try {
    return new Date(d).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  } catch {
    return '-';
  }
};

const fmtNum = (n) => {
  if (n === null || n === undefined || n === '') return '-';
  const x = Number(n);
  return Number.isNaN(x) ? '-' : x.toLocaleString(undefined, { maximumFractionDigits: 3 });
};

const todayISO = computed(() => props.serverToday || new Date().toISOString().slice(0, 10));
const isOverdue = (r) => r?.eta && r?.balance_qty > 0 && String(r.eta).slice(0, 10) < todayISO.value;

const rowClass = (r) => {
  if (isOverdue(r)) return 'bg-red-50 dark:bg-red-900/20';
  const bal = Number(r?.balance_qty ?? 0);
  if (bal <= 0) return 'bg-emerald-50 dark:bg-emerald-900/15';
  return 'bg-amber-50 dark:bg-amber-900/15';
};

const selectedIds = ref([]);

watch(
  () => rows.value.map((r) => r.id).join(','),
  () => {
    selectedIds.value = [];
  },
);

const allVisibleSelected = computed(() => {
  if (!rows.value.length) return false;
  return rows.value.every((r) => selectedIds.value.includes(r.id));
});

const toggleSelectAllVisible = () => {
  if (!rows.value.length) return;
  if (allVisibleSelected.value) {
    const visible = new Set(rows.value.map((r) => r.id));
    selectedIds.value = selectedIds.value.filter((id) => !visible.has(id));
    return;
  }
  const merged = new Set(selectedIds.value);
  rows.value.forEach((r) => merged.add(r.id));
  selectedIds.value = Array.from(merged);
};

const toggleRowSelection = (id) => {
  if (selectedIds.value.includes(id)) {
    selectedIds.value = selectedIds.value.filter((x) => x !== id);
    return;
  }
  selectedIds.value = [...selectedIds.value, id];
};

const bulkTrashSelected = async () => {
  if (!canDelete.value || !selectedIds.value.length || props.showTrashed) return;
  const result = await showDeleteConfirm(
    `Delete <strong>${selectedIds.value.length}</strong> rows?<br><span class="text-sm text-gray-500">They will be moved to Trash.</span>`,
  );
  if (!result.isConfirmed) return;
  router.delete(route('procurement-followup.bulk-destroy'), {
    data: { ids: [...selectedIds.value] },
    preserveScroll: true,
    preserveState: true,
    onFinish: () => {
      selectedIds.value = [];
    },
  });
};

const bulkRestoreSelected = async () => {
  if (!canDelete.value || !selectedIds.value.length || !props.showTrashed) return;
  const result = await showConfirm(`Restore ${selectedIds.value.length} rows?`, 'Confirm');
  if (!result.isConfirmed) return;
  router.post(
    route('procurement-followup.bulk-restore'),
    { ids: [...selectedIds.value] },
    {
      preserveScroll: true,
      preserveState: true,
      onFinish: () => {
        selectedIds.value = [];
      },
    },
  );
};

const bulkForceDeleteSelected = async () => {
  if (!canDelete.value || !selectedIds.value.length || !props.showTrashed) return;
  const result = await showDeleteConfirm(
    `Permanently delete <strong>${selectedIds.value.length}</strong> rows?<br><span class="text-sm text-red-500 font-semibold">This action cannot be undone.</span>`,
  );
  if (!result.isConfirmed) return;
  router.delete(route('procurement-followup.bulk-force-destroy'), {
    data: { ids: [...selectedIds.value] },
    preserveScroll: true,
    preserveState: true,
    onFinish: () => {
      selectedIds.value = [];
    },
  });
};

const viewOpen = ref(false);
const editOpen = ref(false);
const createOpen = ref(false);
const activeRow = ref(null);
const editingId = ref(null);

const inputCls =
  'mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';

const editForm = useForm({
  supplier: '',
  merchandiser: '',
  merchant_team: '',
  yarn_count: '',
  booking_type: '',
  fiber: '',
  fiber_description: '',
  order_qty_kg: '',
  pi_received_date: '',
  lc_number: '',
  lc_issue_date: '',
  lc_factory: '',
  commercial_invoice: '',
  bl_qty_ctn: '',
  org_document_date: '',
  ex_factory_date: '',
  etd: '',
  eta: '',
  received_qty: '',
  received_date: '',
  remark: '',
});

const createForm = useForm({
  pi_number: '',
  supplier: '',
  merchandiser: '',
  merchant_team: '',
  yarn_count: '',
  booking_type: '',
  fiber: '',
  fiber_description: '',
  order_qty_kg: '',
  pi_received_date: '',
  lc_number: '',
  lc_issue_date: '',
  lc_factory: '',
  commercial_invoice: '',
  bl_qty_ctn: '',
  org_document_date: '',
  ex_factory_date: '',
  etd: '',
  eta: '',
  received_qty: '',
  received_date: '',
  remark: '',
});

const resetCreateSearches = () => {
  cSupplierSearch.value = '';
  cMerchandiserSearch.value = '';
  cMerchantTeamSearch.value = '';
  cYarnCountSearch.value = '';
  cFiberSearch.value = '';
};

const openCreate = () => {
  if (!canCreate.value) return;
  createForm.reset();
  createForm.clearErrors();
  resetCreateSearches();
  createOpen.value = true;
};

const closeCreate = () => {
  createOpen.value = false;
  createForm.reset();
  createForm.clearErrors();
  resetCreateSearches();
};

const submitCreate = () => {
  if (!canCreate.value) return;
  createForm.post(route('procurement-followup.store'), {
    preserveScroll: true,
    onSuccess: closeCreate,
  });
};

const openView = (row) => {
  activeRow.value = row;
  viewOpen.value = true;
};

const closeView = () => {
  viewOpen.value = false;
  activeRow.value = null;
};

const openEdit = (row) => {
  if (!canEdit.value) return;
  editingId.value = row.id;
  activeRow.value = row;
  editForm.clearErrors();

  editForm.supplier = row.supplier ?? '';
  supplierSearch.value = resolveSupplier(row.supplier ?? '');
  editForm.merchandiser = row.merchandiser ?? '';
  merchandiserSearch.value = row.merchandiser ?? '';
  editForm.merchant_team = row.merchant_team ?? '';
  merchantTeamSearch.value = resolveMerchantTeam(row.merchant_team ?? '');
  editForm.yarn_count = row.yarn_count ?? '';
  yarnCountSearch.value = resolveYarnCount(row.yarn_count ?? '');
  editForm.booking_type = row.booking_type ?? '';
  editForm.fiber = row.fiber ?? '';
  editForm.fiber_description = row.fiber_description ?? '';
  fiberTypeSearch.value = row.fiber_description ?? '';
  editForm.order_qty_kg = row.order_qty_kg ?? '';
  editForm.pi_received_date = row.pi_received_date ? String(row.pi_received_date).slice(0, 10) : '';
  editForm.lc_number = row.lc_number ?? '';
  editForm.lc_issue_date = row.lc_issue_date ? String(row.lc_issue_date).slice(0, 10) : '';
  editForm.lc_factory = row.lc_factory ?? '';
  editForm.commercial_invoice = row.commercial_invoice ?? '';
  editForm.bl_qty_ctn = row.bl_qty_ctn ?? '';
  editForm.org_document_date = row.org_document_date ? String(row.org_document_date).slice(0, 10) : '';
  editForm.ex_factory_date = row.ex_factory_date ? String(row.ex_factory_date).slice(0, 10) : '';
  editForm.etd = row.etd ? String(row.etd).slice(0, 10) : '';
  editForm.eta = row.eta ? String(row.eta).slice(0, 10) : '';
  editForm.received_qty = row.received_qty ?? '';
  editForm.received_date = row.received_date ? String(row.received_date).slice(0, 10) : '';
  editForm.remark = row.remark ?? '';

  editOpen.value = true;
};

const closeEdit = () => {
  editOpen.value = false;
  editingId.value = null;
  activeRow.value = null;
  editForm.reset();
  editForm.clearErrors();
};

const submitEdit = () => {
  if (!canEdit.value || !editingId.value) return;
  editForm.patch(route('procurement-followup.update', editingId.value), {
    preserveScroll: true,
    onSuccess: closeEdit,
  });
};

const trashRow = async (row) => {
  if (!canDelete.value) return;
  const msg = `Delete PI <strong>${row.pi_number ?? '-'}</strong>?<br><span class="text-sm text-gray-500">It will be moved to Trash.</span>`;
  const result = await showDeleteConfirm(msg);
  if (!result.isConfirmed) return;
  useForm({}).delete(route('procurement-followup.destroy', row.id), { preserveScroll: true });
};

const restoreRow = async (row) => {
  if (!canDelete.value) return;
  const result = await showConfirm(`Restore PI ${row.pi_number ?? '-'}?`, 'Confirm');
  if (!result.isConfirmed) return;
  useForm({}).post(route('procurement-followup.restore', row.id), { preserveScroll: true });
};

const forceDeleteRow = async (row) => {
  if (!canDelete.value) return;
  const msg = `Permanently delete PI <strong>${row.pi_number ?? '-'}</strong>?<br><span class="text-sm text-red-500 font-semibold">This action cannot be undone.</span>`;
  const result = await showDeleteConfirm(msg);
  if (!result.isConfirmed) return;
  useForm({}).delete(route('procurement-followup.force-destroy', row.id), { preserveScroll: true });
};
</script>

<template>
  <Head title="PROCUREMENT FOLLOWUP" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2 leading-tight">
          <ClipboardList class="text-indigo-600 dark:text-indigo-400 shrink-0" :size="22" />
          Procurement Followup
        </h2>
        <div class="flex flex-wrap items-center gap-2">
          <Link
            v-if="canDelete"
            :href="toggleTrashHref"
            class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 shadow-sm hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-100 dark:border-gray-600"
          >
            <Trash2 :size="18" /> {{ showTrashed ? 'Back to List' : 'Show Trash' }}
          </Link>
          <Link
            v-if="canImport"
            :href="route('procurement-followup.import.page')"
            class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
          >
            <Upload :size="18" /> Import
          </Link>
          <button
            v-if="canCreate && !showTrashed"
            type="button"
            class="inline-flex items-center gap-2 rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500"
            @click="openCreate"
          >
            <Plus :size="18" /> Add Data
          </button>
          <Link
            v-if="canViewAnalysis"
            :href="route('procurement-followup.pi-wise-analysis')"
            class="inline-flex items-center gap-2 rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-700"
          >
            <BarChart3 :size="18" /> PI Research & Analysis
          </Link>
          <a
            v-if="canExport"
            :href="route('procurement-followup.export', buildQuery())"
            class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 shadow-sm hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-100 dark:border-gray-600"
          >
            <Download :size="18" /> Export
          </a>
        </div>
      </div>
    </template>

    <div class="py-6 sm:py-8">
      <div class="mx-auto max-w-7xl sm:px-4 lg:px-4 xl:max-w-[1600px] space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-4 sm:p-5">
          <div class="flex items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">
            <Filter :size="16" class="text-gray-400" />
            Filters
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-9 gap-3 items-end">
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Year (PI Received)</label>
              <select v-model="filterState.pi_year" class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                <option value="">All years</option>
                <option v-for="y in filterOptions.piYears || []" :key="y" :value="String(y)">{{ y }}</option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Month (PI Received)</label>
              <select v-model="filterState.pi_month" :disabled="!filterState.pi_year" class="w-full rounded-lg border-gray-300 text-sm shadow-sm disabled:opacity-60 disabled:cursor-not-allowed dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                <option value="">All months</option>
                <option v-for="m in monthOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Yarn Count</label>
              <select v-model="filterState.yarn_count" class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                <option value="">All counts</option>
                <option v-for="yc in filterOptions.yarnCounts || []" :key="yc" :value="yc">{{ yc }}</option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Fiber</label>
              <select v-model="filterState.fiber" class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                <option value="">All fibers</option>
                <option v-for="f in filterOptions.fibers || []" :key="f" :value="f">{{ f }}</option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Supplier</label>
              <select v-model="filterState.supplier" class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                <option value="">All suppliers</option>
                <option v-for="s in filterOptions.suppliers || []" :key="s" :value="s">{{ s }}</option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Merchandiser</label>
              <select v-model="filterState.merchandiser" class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                <option value="">All merchandisers</option>
                <option v-for="m in filterOptions.merchandisers || []" :key="m" :value="m">{{ m }}</option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Merchant Team</label>
              <select v-model="filterState.merchant_team" class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                <option value="">All teams</option>
                <option v-for="t in filterOptions.merchantTeams || []" :key="t" :value="t">{{ t }}</option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">PI #</label>
              <input
                v-model="filterState.pi_number"
                type="text"
                placeholder="Search PI number…"
                class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500"
              />
            </div>

            <div class="flex flex-wrap gap-2 items-end">
              <button type="button" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-100 dark:border-gray-600" @click="clearFilters">
                Clear
              </button>
              <select v-model.number="perPage" class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                <option :value="50">50</option>
                <option :value="100">100</option>
                <option :value="200">200</option>
                <option :value="500">500</option>
              </select>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
          <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Order KG</div>
            <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ fmtNum(summary.total_order_kg) }}</div>
          </div>
          <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Received KG</div>
            <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ fmtNum(summary.total_received_kg) }}</div>
          </div>
          <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Pending KG</div>
            <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ fmtNum(summary.total_pending_kg) }}</div>
          </div>
          <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400">Completion Rate</div>
            <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">
              {{ (summary.completion_rate ?? 0).toFixed(1) }}%
            </div>
          </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 overflow-hidden">
          <div v-if="canDelete && selectedIds.length" class="border-b border-gray-200 dark:border-gray-700 px-4 py-3 bg-gray-50/60 dark:bg-gray-900/30 flex flex-wrap gap-2 items-center justify-between">
            <div class="text-sm text-gray-700 dark:text-gray-200">
              Selected: <span class="font-semibold">{{ selectedIds.length }}</span>
            </div>
            <div class="flex flex-wrap gap-2">
              <button
                v-if="!showTrashed"
                type="button"
                class="inline-flex items-center gap-2 rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-500"
                @click="bulkTrashSelected"
              >
                <Trash2 :size="16" /> Delete Selected
              </button>
              <button
                v-if="showTrashed"
                type="button"
                class="inline-flex items-center gap-2 rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-500"
                @click="bulkRestoreSelected"
              >
                <RotateCcw :size="16" /> Restore Selected
              </button>
              <button
                v-if="showTrashed"
                type="button"
                class="inline-flex items-center gap-2 rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-500"
                @click="bulkForceDeleteSelected"
              >
                <Trash2 :size="16" /> Delete Permanently
              </button>
            </div>
          </div>

          <div class="overflow-x-auto">
            <table class="min-w-full w-full divide-y divide-gray-200 dark:divide-gray-700">
              <thead class="bg-gray-50 dark:bg-gray-900/50">
                <tr>
                  <th class="sticky left-0 z-20 bg-gray-50 dark:bg-gray-900/95 px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300 whitespace-nowrap">
                    <div class="flex items-center gap-2">
                      <input
                        v-if="canDelete"
                        type="checkbox"
                        :checked="allVisibleSelected"
                        @change="toggleSelectAllVisible"
                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700"
                      />
                      <span>Supplier</span>
                    </div>
                  </th>
                  <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300 whitespace-nowrap">Merchandiser</th>
                  <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300 whitespace-nowrap">Merchant Team</th>
                  <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300 whitespace-nowrap">Yarn Count</th>
                  <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300 whitespace-nowrap">Type</th>
                  <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300 whitespace-nowrap">Fiber</th>
                  <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300 whitespace-nowrap">Fiber Description</th>
                  <th class="px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300 whitespace-nowrap">Order Qty</th>
                  <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300 whitespace-nowrap">PI #</th>
                  <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300 whitespace-nowrap">PI Date</th>
                  <th class="px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300 whitespace-nowrap">Rcvd Qty</th>
                  <th v-if="canAnyAction" class="sticky right-0 z-20 bg-gray-50 dark:bg-gray-900/95 px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300 whitespace-nowrap">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800">
                <tr v-if="rows.length === 0">
                  <td :colspan="canAnyAction ? 12 : 11" class="px-6 py-16 text-center text-sm text-gray-500 dark:text-gray-400">
                    No procurement followups found.
                  </td>
                </tr>
                <tr v-for="r in rows" :key="r.id" :class="['hover:bg-gray-50/80 dark:hover:bg-gray-700/40 transition-colors', rowClass(r)]">
                  <td class="sticky left-0 z-10 px-3 py-2.5 text-sm text-gray-900 dark:text-gray-100 whitespace-nowrap font-medium shadow-[4px_0_8px_-4px_rgba(0,0,0,.1)] dark:shadow-[4px_0_12px_-4px_rgba(0,0,0,.4)]" :class="rowClass(r)">
                    <div class="flex items-center gap-2">
                      <input
                        v-if="canDelete"
                        type="checkbox"
                        :checked="selectedIds.includes(r.id)"
                        @change="toggleRowSelection(r.id)"
                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700"
                      />
                      <span>{{ resolveSupplier(r.supplier) || '-' }}</span>
                    </div>
                  </td>
                  <td class="px-3 py-2.5 text-sm text-gray-700 dark:text-gray-200 whitespace-nowrap">{{ r.merchandiser || '-' }}</td>
                  <td class="px-3 py-2.5 text-sm text-gray-700 dark:text-gray-200 whitespace-nowrap">{{ resolveMerchantTeam(r.merchant_team) || '-' }}</td>
                  <td class="px-3 py-2.5 text-sm text-gray-700 dark:text-gray-200 whitespace-nowrap">{{ resolveYarnCount(r.yarn_count) || '-' }}</td>
                  <td class="px-3 py-2.5 text-sm text-gray-700 dark:text-gray-200 whitespace-nowrap">{{ r.booking_type || '-' }}</td>
                  <td class="px-3 py-2.5 text-sm text-gray-700 dark:text-gray-200 whitespace-nowrap">{{ r.fiber || '-' }}</td>
                  <td class="px-3 py-2.5 text-sm text-gray-700 dark:text-gray-200 whitespace-nowrap">{{ r.fiber_description || '-' }}</td>
                  <td class="px-3 py-2.5 text-sm text-right tabular-nums text-gray-800 dark:text-gray-100 whitespace-nowrap">{{ fmtNum(r.order_qty_kg) }}</td>
                  <td class="px-3 py-2.5 text-sm text-gray-700 dark:text-gray-200 whitespace-nowrap font-mono">{{ r.pi_number || '-' }}</td>
                  <td class="px-3 py-2.5 text-sm text-gray-700 dark:text-gray-200 whitespace-nowrap">{{ fmtDate(r.pi_received_date) }}</td>
                  <td class="px-3 py-2.5 text-sm text-right tabular-nums text-gray-800 dark:text-gray-100 whitespace-nowrap">{{ fmtNum(r.received_qty) }}</td>

                  <td v-if="canAnyAction" class="sticky right-0 z-10 px-3 py-2.5 text-sm text-right whitespace-nowrap" :class="rowClass(r)">
                    <div class="inline-flex items-center gap-1">
                      <button
                        type="button"
                        class="inline-flex rounded-lg p-2 text-slate-700 hover:bg-white/60 dark:text-slate-200 dark:hover:bg-black/20"
                        title="View"
                        @click="openView(r)"
                      >
                        <Eye :size="16" />
                      </button>

                      <button
                        v-if="canEdit && !showTrashed"
                        type="button"
                        class="inline-flex rounded-lg p-2 text-indigo-700 hover:bg-white/60 dark:text-indigo-300 dark:hover:bg-black/20"
                        title="Edit"
                        @click="openEdit(r)"
                      >
                        <Pencil :size="16" />
                      </button>

                      <button
                        v-if="canDelete && !showTrashed"
                        type="button"
                        class="inline-flex rounded-lg p-2 text-red-700 hover:bg-white/60 dark:text-red-300 dark:hover:bg-black/20"
                        title="Delete (Trash)"
                        @click="trashRow(r)"
                      >
                        <Trash2 :size="16" />
                      </button>

                      <button
                        v-if="canDelete && showTrashed"
                        type="button"
                        class="inline-flex rounded-lg p-2 text-emerald-700 hover:bg-white/60 dark:text-emerald-300 dark:hover:bg-black/20"
                        title="Restore"
                        @click="restoreRow(r)"
                      >
                        <RotateCcw :size="16" />
                      </button>

                      <button
                        v-if="canDelete && showTrashed"
                        type="button"
                        class="inline-flex rounded-lg p-2 text-red-700 hover:bg-white/60 dark:text-red-300 dark:hover:bg-black/20"
                        title="Delete Permanently"
                        @click="forceDeleteRow(r)"
                      >
                        <Trash2 :size="16" />
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div
            v-if="(followups.last_page ?? 1) > 1"
            class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-t border-gray-200 dark:border-gray-700 px-4 py-3 bg-gray-50/50 dark:bg-gray-900/30"
          >
            <div class="text-sm text-gray-600 dark:text-gray-400">
              Showing
              <span class="font-medium text-gray-900 dark:text-gray-100">{{ followups.from ?? 0 }}</span>
              to
              <span class="font-medium text-gray-900 dark:text-gray-100">{{ followups.to ?? 0 }}</span>
              of
              <span class="font-medium text-gray-900 dark:text-gray-100">{{ followups.total ?? 0 }}</span>
            </div>
            <nav class="flex flex-wrap gap-1" aria-label="Pagination">
              <button
                v-for="(link, idx) in followups.links ?? []"
                :key="idx"
                type="button"
                :disabled="!link.url"
                @click="
                  link.url &&
                    router.visit(link.url, {
                      preserveScroll: true,
                      preserveState: true,
                    })
                "
                :class="[
                  'px-3 py-1.5 rounded-md text-sm border transition-colors min-w-[2.25rem]',
                  link.active
                    ? 'bg-indigo-600 text-white border-indigo-600'
                    : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700',
                  !link.url ? 'pointer-events-none opacity-40' : '',
                ]"
                v-html="link.label"
              />
            </nav>
          </div>
        </div>
      </div>
    </div>

    <Modal :show="viewOpen" max-width="4xl" @close="closeView">
      <div class="border-b border-gray-200 bg-gray-50 px-6 py-4 dark:border-gray-700 dark:bg-gray-900/50">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">View Procurement Followup</h3>
      </div>
      <div class="p-6 dark:bg-gray-800 max-h-[75vh] overflow-y-auto">
        <div v-if="activeRow" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">PI #</span><div class="font-mono font-semibold text-gray-900 dark:text-gray-100">{{ activeRow.pi_number || '-' }}</div></div>
          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">Import Batch</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ activeRow.import_batch || '-' }}</div></div>
          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">Fiscal Year</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ activeRow.fiscal_year || '-' }}</div></div>

          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">Supplier</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ resolveSupplier(activeRow.supplier) || '-' }}</div></div>
          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">Merchandiser</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ activeRow.merchandiser || '-' }}</div></div>
          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">Merchant Team</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ resolveMerchantTeam(activeRow.merchant_team) || '-' }}</div></div>
          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">Yarn Count</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ resolveYarnCount(activeRow.yarn_count) || '-' }}</div></div>

          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">Booking Type</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ activeRow.booking_type || '-' }}</div></div>
          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">Fiber Description</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ activeRow.fiber_description || '-' }}</div></div>
          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">Fiber</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ activeRow.fiber || '-' }}</div></div>

          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">Order Qty (KG)</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ fmtNum(activeRow.order_qty_kg) }}</div></div>
          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">Received Qty</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ fmtNum(activeRow.received_qty) }}</div></div>
          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">Balance Qty</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ fmtNum(activeRow.balance_qty) }}</div></div>

          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">PI Received Date</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ fmtDate(activeRow.pi_received_date) }}</div></div>
          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">Received Date</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ fmtDate(activeRow.received_date) }}</div></div>
          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">LC #</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ activeRow.lc_number || '-' }}</div></div>

          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">LC Issue Date</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ fmtDate(activeRow.lc_issue_date) }}</div></div>
          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">LC Factory</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ activeRow.lc_factory || '-' }}</div></div>
          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">Commercial Invoice</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ activeRow.commercial_invoice || '-' }}</div></div>

          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">BL Qty (CTN)</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ fmtNum(activeRow.bl_qty_ctn) }}</div></div>
          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">Org Doc Date</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ fmtDate(activeRow.org_document_date) }}</div></div>
          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">Ex-Factory Date</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ fmtDate(activeRow.ex_factory_date) }}</div></div>

          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">ETD</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ fmtDate(activeRow.etd) }}</div></div>
          <div><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">ETA</span><div class="font-semibold text-gray-900 dark:text-gray-100">{{ fmtDate(activeRow.eta) }}</div></div>

          <div class="lg:col-span-3"><span class="text-gray-500 dark:text-gray-400 text-xs font-medium">Remark</span><div class="font-semibold whitespace-pre-wrap text-gray-900 dark:text-gray-100">{{ activeRow.remark || '-' }}</div></div>
        </div>
      </div>
      <div class="border-t border-gray-200 px-6 py-4 dark:border-gray-700 dark:bg-gray-800 flex justify-end gap-2">
        <SecondaryButton type="button" @click="closeView">Close</SecondaryButton>
      </div>
    </Modal>

    <Modal :show="editOpen" max-width="5xl" @close="closeEdit">
      <div class="border-b border-gray-200 bg-gray-50 px-6 py-4 dark:border-gray-700 dark:bg-gray-900/50">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Edit Procurement Followup</h3>
      </div>
      <form class="p-6 dark:bg-gray-800" @submit.prevent="submitEdit">
        <div class="max-h-[75vh] overflow-y-auto pr-1">
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div>
              <InputLabel value="PI #" />
              <TextInput :modelValue="activeRow?.pi_number ?? ''" disabled class="mt-1 block w-full" />
            </div>

            <div>
              <InputLabel value="Supplier" />
              <div class="relative mt-1">
                <input
                  v-model="supplierSearch"
                  type="text"
                  placeholder="Search supplier..."
                  :class="inputCls + ' mt-0'"
                  autocomplete="off"
                  @focus="supplierDropdownOpen = true"
                  @blur="onSupplierBlur"
                />
                <div
                  v-if="supplierDropdownOpen && filteredSuppliers.length"
                  class="absolute z-50 mt-1 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg max-h-48 overflow-y-auto"
                >
                  <button
                    v-for="s in filteredSuppliers"
                    :key="s.code"
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-2 text-sm text-left hover:bg-indigo-50 dark:hover:bg-indigo-900/40"
                    @mousedown.prevent="selectSupplier(s)"
                  >
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ s.name }}</span>
                    <span class="text-xs text-gray-400">{{ s.code }}</span>
                  </button>
                </div>
              </div>
              <InputError class="mt-1" :message="editForm.errors.supplier" />
            </div>

            <div>
              <InputLabel value="Merchandiser" />
              <div class="relative mt-1">
                <input
                  v-model="merchandiserSearch"
                  type="text"
                  placeholder="Search merchandiser..."
                  :class="inputCls + ' mt-0'"
                  autocomplete="off"
                  @focus="merchandiserDropdownOpen = true"
                  @blur="onMerchandiserBlur"
                  @input="editForm.merchandiser = merchandiserSearch"
                />
                <div
                  v-if="merchandiserDropdownOpen && filteredMerchandisers.length"
                  class="absolute z-50 mt-1 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg max-h-48 overflow-y-auto"
                >
                  <button
                    v-for="m in filteredMerchandisers"
                    :key="m"
                    type="button"
                    class="block w-full px-3 py-2 text-sm text-left text-gray-900 dark:text-gray-100 hover:bg-indigo-50 dark:hover:bg-indigo-900/40"
                    @mousedown.prevent="selectMerchandiser(m)"
                  >
                    {{ m }}
                  </button>
                </div>
              </div>
              <InputError class="mt-1" :message="editForm.errors.merchandiser" />
            </div>

            <div>
              <InputLabel value="Merchant Team" />
              <div class="relative mt-1">
                <input
                  v-model="merchantTeamSearch"
                  type="text"
                  placeholder="Search merchant team..."
                  :class="inputCls + ' mt-0'"
                  autocomplete="off"
                  @focus="merchantTeamDropdownOpen = true"
                  @blur="onMerchantTeamBlur"
                />
                <div
                  v-if="merchantTeamDropdownOpen && filteredMerchantTeams.length"
                  class="absolute z-50 mt-1 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg max-h-48 overflow-y-auto"
                >
                  <button
                    v-for="t in filteredMerchantTeams"
                    :key="t.code"
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-2 text-sm text-left hover:bg-indigo-50 dark:hover:bg-indigo-900/40"
                    @mousedown.prevent="selectMerchantTeam(t)"
                  >
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ t.name }}</span>
                    <span class="text-xs text-gray-400">{{ t.code }}</span>
                  </button>
                </div>
              </div>
              <InputError class="mt-1" :message="editForm.errors.merchant_team" />
            </div>

            <div>
              <InputLabel value="Yarn Count" />
              <div class="relative mt-1">
                <input
                  v-model="yarnCountSearch"
                  type="text"
                  placeholder="Search yarn count..."
                  :class="inputCls + ' mt-0'"
                  autocomplete="off"
                  @focus="yarnCountDropdownOpen = true"
                  @blur="onYarnCountBlur"
                />
                <div
                  v-if="yarnCountDropdownOpen && filteredYarnCounts.length"
                  class="absolute z-50 mt-1 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg max-h-48 overflow-y-auto"
                >
                  <button
                    v-for="c in filteredYarnCounts"
                    :key="c.code"
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-2 text-sm text-left hover:bg-indigo-50 dark:hover:bg-indigo-900/40"
                    @mousedown.prevent="selectYarnCount(c)"
                  >
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ c.count_value }}</span>
                    <span class="text-xs text-gray-400">{{ c.code }}</span>
                  </button>
                </div>
              </div>
              <InputError class="mt-1" :message="editForm.errors.yarn_count" />
            </div>

            <div>
              <InputLabel value="Booking Type" />
              <select v-model="editForm.booking_type" :class="inputCls">
                <option value="">Select type</option>
                <option value="CC">CC</option>
                <option value="CF">CF</option>
              </select>
              <InputError class="mt-1" :message="editForm.errors.booking_type" />
            </div>

            <div>
              <InputLabel value="Fiber Description" />
              <div class="relative mt-1">
                <input
                  v-model="fiberTypeSearch"
                  type="text"
                  placeholder="Search fiber description..."
                  :class="inputCls + ' mt-0'"
                  autocomplete="off"
                  @focus="fiberTypeDropdownOpen = true"
                  @blur="onFiberTypeBlur"
                />
                <div
                  v-if="fiberTypeDropdownOpen && filteredFiberTypes.length"
                  class="absolute z-50 mt-1 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg max-h-48 overflow-y-auto"
                >
                  <button
                    v-for="f in filteredFiberTypes"
                    :key="f.name"
                    type="button"
                    class="flex w-full flex-col px-3 py-2 text-sm text-left hover:bg-indigo-50 dark:hover:bg-indigo-900/40"
                    @mousedown.prevent="selectFiberType(f)"
                  >
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ f.fiber_description }}</span>
                    <span class="text-xs text-gray-400">{{ f.name }}</span>
                  </button>
                </div>
              </div>
              <InputError class="mt-1" :message="editForm.errors.fiber_description" />
            </div>

            <div>
              <InputLabel value="Fiber" />
              <select v-model="editForm.fiber" :class="inputCls">
                <option value="">Select fiber</option>
                <option v-if="editForm.fiber && !fiberNames.includes(editForm.fiber)" :value="editForm.fiber">{{ editForm.fiber }}</option>
                <option v-for="f in fiberTypes" :key="f.name" :value="f.name">{{ f.name }}</option>
              </select>
              <InputError class="mt-1" :message="editForm.errors.fiber" />
            </div>

            <div>
              <InputLabel value="Order Qty (KG)" />
              <TextInput v-model="editForm.order_qty_kg" :class="inputCls" />
              <InputError class="mt-1" :message="editForm.errors.order_qty_kg" />
            </div>

            <div>
              <InputLabel value="PI Received Date" />
              <TextInput v-model="editForm.pi_received_date" type="date" :class="inputCls" />
              <InputError class="mt-1" :message="editForm.errors.pi_received_date" />
            </div>

            <div>
              <InputLabel value="LC #" />
              <TextInput v-model="editForm.lc_number" :class="inputCls" />
              <InputError class="mt-1" :message="editForm.errors.lc_number" />
            </div>

            <div>
              <InputLabel value="LC Issue Date" />
              <TextInput v-model="editForm.lc_issue_date" type="date" :class="inputCls" />
              <InputError class="mt-1" :message="editForm.errors.lc_issue_date" />
            </div>

            <div>
              <InputLabel value="LC Factory" />
              <TextInput v-model="editForm.lc_factory" :class="inputCls" />
              <InputError class="mt-1" :message="editForm.errors.lc_factory" />
            </div>

            <div>
              <InputLabel value="Commercial Invoice" />
              <TextInput v-model="editForm.commercial_invoice" :class="inputCls" />
              <InputError class="mt-1" :message="editForm.errors.commercial_invoice" />
            </div>

            <div>
              <InputLabel value="BL Qty (CTN)" />
              <TextInput v-model="editForm.bl_qty_ctn" :class="inputCls" />
              <InputError class="mt-1" :message="editForm.errors.bl_qty_ctn" />
            </div>

            <div>
              <InputLabel value="Org Doc Date" />
              <TextInput v-model="editForm.org_document_date" type="date" :class="inputCls" />
              <InputError class="mt-1" :message="editForm.errors.org_document_date" />
            </div>

            <div>
              <InputLabel value="Ex-Factory Date" />
              <TextInput v-model="editForm.ex_factory_date" type="date" :class="inputCls" />
              <InputError class="mt-1" :message="editForm.errors.ex_factory_date" />
            </div>

            <div>
              <InputLabel value="ETD" />
              <TextInput v-model="editForm.etd" type="date" :class="inputCls" />
              <InputError class="mt-1" :message="editForm.errors.etd" />
            </div>

            <div>
              <InputLabel value="ETA" />
              <TextInput v-model="editForm.eta" type="date" :class="inputCls" />
              <InputError class="mt-1" :message="editForm.errors.eta" />
            </div>

            <div>
              <InputLabel value="Received Qty" />
              <TextInput v-model="editForm.received_qty" :class="inputCls" />
              <InputError class="mt-1" :message="editForm.errors.received_qty" />
            </div>

            <div>
              <InputLabel value="Received Date" />
              <TextInput v-model="editForm.received_date" type="date" :class="inputCls" />
              <InputError class="mt-1" :message="editForm.errors.received_date" />
            </div>

            <div class="sm:col-span-2 lg:col-span-3">
              <InputLabel value="Remark" />
              <textarea v-model="editForm.remark" rows="3" :class="inputCls" />
              <InputError class="mt-1" :message="editForm.errors.remark" />
            </div>
          </div>
        </div>

        <div class="mt-6 flex justify-end gap-2">
          <SecondaryButton type="button" @click="closeEdit">Cancel</SecondaryButton>
          <PrimaryButton type="submit" :disabled="editForm.processing">Save</PrimaryButton>
        </div>
      </form>
    </Modal>

    <Modal :show="createOpen" max-width="5xl" @close="closeCreate">
      <div class="border-b border-gray-200 bg-gray-50 px-6 py-4 dark:border-gray-700 dark:bg-gray-900/50">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Add Procurement Followup</h3>
      </div>
      <form class="p-6 dark:bg-gray-800" @submit.prevent="submitCreate">
        <div class="max-h-[75vh] overflow-y-auto pr-1">
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div>
              <InputLabel value="PI # (Required)" />
              <TextInput v-model="createForm.pi_number" :class="inputCls" />
              <InputError class="mt-1" :message="createForm.errors.pi_number" />
            </div>

            <div>
              <InputLabel value="Supplier" />
              <div class="relative mt-1">
                <input v-model="cSupplierSearch" type="text" placeholder="Search supplier..." :class="inputCls + ' mt-0'" autocomplete="off" @focus="cSupplierOpen = true" @blur="onCSupplierBlur" />
                <div v-if="cSupplierOpen && filteredCSuppliers.length" class="absolute z-50 mt-1 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                  <button v-for="s in filteredCSuppliers" :key="s.code" type="button" class="flex w-full items-center gap-2 px-3 py-2 text-sm text-left hover:bg-indigo-50 dark:hover:bg-indigo-900/40" @mousedown.prevent="selectCSupplier(s)">
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ s.name }}</span><span class="text-xs text-gray-400">{{ s.code }}</span>
                  </button>
                </div>
              </div>
              <InputError class="mt-1" :message="createForm.errors.supplier" />
            </div>

            <div>
              <InputLabel value="Merchandiser" />
              <div class="relative mt-1">
                <input v-model="cMerchandiserSearch" type="text" placeholder="Search merchandiser..." :class="inputCls + ' mt-0'" autocomplete="off" @focus="cMerchandiserOpen = true" @blur="onCMerchandiserBlur" @input="createForm.merchandiser = cMerchandiserSearch" />
                <div v-if="cMerchandiserOpen && filteredCMerchandisers.length" class="absolute z-50 mt-1 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                  <button v-for="m in filteredCMerchandisers" :key="m" type="button" class="block w-full px-3 py-2 text-sm text-left text-gray-900 dark:text-gray-100 hover:bg-indigo-50 dark:hover:bg-indigo-900/40" @mousedown.prevent="selectCMerchandiser(m)">{{ m }}</button>
                </div>
              </div>
              <InputError class="mt-1" :message="createForm.errors.merchandiser" />
            </div>

            <div>
              <InputLabel value="Merchant Team" />
              <div class="relative mt-1">
                <input v-model="cMerchantTeamSearch" type="text" placeholder="Search merchant team..." :class="inputCls + ' mt-0'" autocomplete="off" @focus="cMerchantTeamOpen = true" @blur="onCMerchantTeamBlur" />
                <div v-if="cMerchantTeamOpen && filteredCMerchantTeams.length" class="absolute z-50 mt-1 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                  <button v-for="t in filteredCMerchantTeams" :key="t.code" type="button" class="flex w-full items-center gap-2 px-3 py-2 text-sm text-left hover:bg-indigo-50 dark:hover:bg-indigo-900/40" @mousedown.prevent="selectCMerchantTeam(t)">
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ t.name }}</span><span class="text-xs text-gray-400">{{ t.code }}</span>
                  </button>
                </div>
              </div>
              <InputError class="mt-1" :message="createForm.errors.merchant_team" />
            </div>

            <div>
              <InputLabel value="Yarn Count" />
              <div class="relative mt-1">
                <input v-model="cYarnCountSearch" type="text" placeholder="Search yarn count..." :class="inputCls + ' mt-0'" autocomplete="off" @focus="cYarnCountOpen = true" @blur="onCYarnCountBlur" />
                <div v-if="cYarnCountOpen && filteredCYarnCounts.length" class="absolute z-50 mt-1 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                  <button v-for="c in filteredCYarnCounts" :key="c.code" type="button" class="flex w-full items-center gap-2 px-3 py-2 text-sm text-left hover:bg-indigo-50 dark:hover:bg-indigo-900/40" @mousedown.prevent="selectCYarnCount(c)">
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ c.count_value }}</span><span class="text-xs text-gray-400">{{ c.code }}</span>
                  </button>
                </div>
              </div>
              <InputError class="mt-1" :message="createForm.errors.yarn_count" />
            </div>

            <div>
              <InputLabel value="Booking Type" />
              <select v-model="createForm.booking_type" :class="inputCls">
                <option value="">Select type</option>
                <option value="CC">CC</option>
                <option value="CF">CF</option>
              </select>
              <InputError class="mt-1" :message="createForm.errors.booking_type" />
            </div>

            <div>
              <InputLabel value="Fiber Description" />
              <div class="relative mt-1">
                <input v-model="cFiberSearch" type="text" placeholder="Search fiber description..." :class="inputCls + ' mt-0'" autocomplete="off" @focus="cFiberOpen = true" @blur="onCFiberBlur" />
                <div v-if="cFiberOpen && filteredCFiberTypes.length" class="absolute z-50 mt-1 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                  <button v-for="f in filteredCFiberTypes" :key="f.name" type="button" class="flex w-full flex-col px-3 py-2 text-sm text-left hover:bg-indigo-50 dark:hover:bg-indigo-900/40" @mousedown.prevent="selectCFiberType(f)">
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ f.fiber_description }}</span><span class="text-xs text-gray-400">{{ f.name }}</span>
                  </button>
                </div>
              </div>
              <InputError class="mt-1" :message="createForm.errors.fiber_description" />
            </div>

            <div>
              <InputLabel value="Fiber" />
              <TextInput v-model="createForm.fiber" :class="inputCls" />
              <InputError class="mt-1" :message="createForm.errors.fiber" />
            </div>

            <div>
              <InputLabel value="Order Qty (KG)" />
              <TextInput v-model="createForm.order_qty_kg" :class="inputCls" />
              <InputError class="mt-1" :message="createForm.errors.order_qty_kg" />
            </div>

            <div>
              <InputLabel value="PI Received Date" />
              <TextInput v-model="createForm.pi_received_date" type="date" :class="inputCls" />
              <InputError class="mt-1" :message="createForm.errors.pi_received_date" />
            </div>

            <div>
              <InputLabel value="LC #" />
              <TextInput v-model="createForm.lc_number" :class="inputCls" />
              <InputError class="mt-1" :message="createForm.errors.lc_number" />
            </div>

            <div>
              <InputLabel value="LC Issue Date" />
              <TextInput v-model="createForm.lc_issue_date" type="date" :class="inputCls" />
              <InputError class="mt-1" :message="createForm.errors.lc_issue_date" />
            </div>

            <div>
              <InputLabel value="LC Factory" />
              <TextInput v-model="createForm.lc_factory" :class="inputCls" />
              <InputError class="mt-1" :message="createForm.errors.lc_factory" />
            </div>

            <div>
              <InputLabel value="Commercial Invoice" />
              <TextInput v-model="createForm.commercial_invoice" :class="inputCls" />
              <InputError class="mt-1" :message="createForm.errors.commercial_invoice" />
            </div>

            <div>
              <InputLabel value="BL Qty (CTN)" />
              <TextInput v-model="createForm.bl_qty_ctn" :class="inputCls" />
              <InputError class="mt-1" :message="createForm.errors.bl_qty_ctn" />
            </div>

            <div>
              <InputLabel value="Org Doc Date" />
              <TextInput v-model="createForm.org_document_date" type="date" :class="inputCls" />
              <InputError class="mt-1" :message="createForm.errors.org_document_date" />
            </div>

            <div>
              <InputLabel value="Ex-Factory Date" />
              <TextInput v-model="createForm.ex_factory_date" type="date" :class="inputCls" />
              <InputError class="mt-1" :message="createForm.errors.ex_factory_date" />
            </div>

            <div>
              <InputLabel value="ETD" />
              <TextInput v-model="createForm.etd" type="date" :class="inputCls" />
              <InputError class="mt-1" :message="createForm.errors.etd" />
            </div>

            <div>
              <InputLabel value="ETA" />
              <TextInput v-model="createForm.eta" type="date" :class="inputCls" />
              <InputError class="mt-1" :message="createForm.errors.eta" />
            </div>

            <div>
              <InputLabel value="Received Qty" />
              <TextInput v-model="createForm.received_qty" :class="inputCls" />
              <InputError class="mt-1" :message="createForm.errors.received_qty" />
            </div>

            <div>
              <InputLabel value="Received Date" />
              <TextInput v-model="createForm.received_date" type="date" :class="inputCls" />
              <InputError class="mt-1" :message="createForm.errors.received_date" />
            </div>

            <div class="sm:col-span-2 lg:col-span-3">
              <InputLabel value="Remark" />
              <textarea v-model="createForm.remark" rows="3" :class="inputCls" />
              <InputError class="mt-1" :message="createForm.errors.remark" />
            </div>
          </div>
        </div>

        <div class="mt-6 flex justify-end gap-2">
          <SecondaryButton type="button" @click="closeCreate">Cancel</SecondaryButton>
          <PrimaryButton type="submit" :disabled="createForm.processing">Save</PrimaryButton>
        </div>
      </form>
    </Modal>
  </AuthenticatedLayout>
</template>

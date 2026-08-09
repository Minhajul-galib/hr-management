<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Bell } from 'lucide-vue-next';
import axios from 'axios';
import { useSweetAlert } from '@/composables/useSweetAlert';

const props = defineProps({
  notifications: Object, // Inertia paginator
  unreadCount: Number,
});

const { showSuccess, showError } = useSweetAlert();

const items = computed(() => props.notifications?.data || []);

const formatDate = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  const now = new Date();
  const diff = now - date;
  const minutes = Math.floor(diff / 60000);
  const hours = Math.floor(diff / 3600000);
  const days = Math.floor(diff / 86400000);
  if (minutes < 1) return 'Just now';
  if (minutes < 60) return `${minutes}m ago`;
  if (hours < 24) return `${hours}h ago`;
  if (days < 7) return `${days}d ago`;
  return date.toLocaleDateString();
};

const markAll = async () => {
  try {
    const response = await axios.post(route('notifications.read-all'), {}, {
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    });
    if (response.data.success) {
      showSuccess(response.data.message || 'All notifications marked as read.');
      // Reload to update the notifications list
      window.location.reload();
    } else {
      showError('Failed to mark all notifications as read.');
    }
  } catch (error) {
    console.error('Error marking all notifications as read:', error);
    showError('An error occurred while marking notifications as read.');
  }
};
const markOne = async (id) => {
  try {
    const response = await axios.post(route('notifications.read', id), {}, {
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    });
    if (response.data.success) {
      showSuccess(response.data.message || 'Notification marked as read.');
      // Optionally refresh the page or update the local state
      // For now, we'll reload to update the notifications list
      window.location.reload();
    } else {
      showError('Failed to mark notification as read.');
    }
  } catch (error) {
    console.error('Error marking notification as read:', error);
    showError('An error occurred while marking the notification as read.');
  }
};

// Format supplier notification with button-like highlights
const formatSupplierNotification = (body) => {
  if (!body) return '';
  
  // Example: "Supplier KBM Computer (CL00148) has used 133.3% of their limit. Due: ৳40,000 / Limit: ৳30,000"
  const match = body.match(/Supplier (.+?) \((.+?)\) has used (.+?)% of their limit\. Due: ৳(.+?) \/ Limit: ৳(.+?)$/);
  if (!match) return body;
  
  const [, supplierName, supplierCode, percentage, due, limit] = match;
  
  return `Supplier <span class="inline-block px-2 py-1 bg-blue-200 dark:bg-blue-800 rounded font-semibold text-blue-900 dark:text-blue-100">${supplierName}</span> (<span class="inline-block px-2 py-1 bg-green-200 dark:bg-green-800 rounded font-semibold text-green-900 dark:text-green-100">${supplierCode}</span>) has used <span class="inline-block px-2 py-1 bg-red-200 dark:bg-red-800 rounded font-bold text-red-900 dark:text-red-100">${percentage}%</span> of their limit. Due: <span class="inline-block px-2 py-1 bg-orange-200 dark:bg-orange-800 rounded font-semibold text-orange-900 dark:text-orange-100">৳${due}</span> / Limit: <span class="inline-block px-2 py-1 bg-purple-200 dark:bg-purple-800 rounded font-semibold text-purple-900 dark:text-purple-100">৳${limit}</span>`;
};

</script>

<template>
  <Head title="Notifications" />
  <AuthenticatedLayout>
    <template #header>
      <div class="flex items-center justify-between">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight flex items-center gap-2">
          <Bell :size="20" class="text-blue-600 dark:text-blue-400" /> All Notifications
        </h2>
        <button
          v-if="unreadCount > 0"
          @click="markAll"
          class="px-3 py-1.5 text-sm rounded-md bg-blue-600 text-white hover:bg-blue-700"
        >
          Mark all as read ({{ unreadCount }})
        </button>
      </div>
    </template>

    <div class="py-6">
      <div class="mx-auto max-w-7xl sm:px-4 lg:px-4 xl:max-w-[1440px] 2xl:max-w-[1600px]">
        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6 text-gray-900 dark:text-gray-100">
            <div v-if="items.length === 0" class="text-sm text-gray-500 dark:text-gray-400">
              No notifications
            </div>
            <ul v-else class="divide-y divide-gray-200 dark:divide-gray-700">
              <li 
                v-for="n in items" 
                :key="n.id" 
                class="py-4 px-4 flex items-start justify-between transition-colors duration-200"
                :class="{ 
                  'bg-blue-50/50 dark:bg-blue-900/10': !n.read_at && n.data?.priority !== 'high',
                  'bg-red-50 dark:bg-red-900/20 border-l-4 border-red-500': n.data?.priority === 'high'
                }"
              >
                <div class="flex-1">
                  <div class="font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                    <span v-if="n.data?.priority === 'high'" class="inline-flex items-center justify-center w-5 h-5 bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-300 rounded-full text-[10px] font-bold">!</span>
                    {{ n.data?.title || 'Notification' }}
                  </div>
                                    
                  <!-- Enhanced supplier notification with button-like highlights -->
                  <div v-if="n.data?.title?.includes('Supplier Credit Limit Alert')" class="text-sm text-gray-800 dark:text-gray-200 mt-2 px-3 py-2 bg-blue-50 dark:bg-blue-900/20 rounded-md border border-blue-200 dark:border-blue-800">
                    <span v-html="formatSupplierNotification(n.data?.body || n.data?.message || '')"></span>
                  </div>
                  <!-- Default notification format for other types -->
                  <div v-else class="text-sm text-gray-800 dark:text-gray-200 mt-2 px-3 py-2 bg-blue-50 dark:bg-blue-900/20 rounded-md border border-blue-200 dark:border-blue-800">
                    {{ n.data?.body || n.data?.message || '' }}
                  </div>
                  <div class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                    {{ formatDate(n.created_at) }}
                  </div>
                </div>
                <div class="flex items-center gap-2">
                  <span v-if="!n.read_at" class="w-2 h-2 bg-blue-600 rounded-full"></span>
                  <button
                    v-if="!n.read_at"
                    @click="markOne(n.id)"
                    class="px-2 py-1 text-xs rounded-md border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700"
                  >
                    Mark read
                  </button>
                </div>
              </li>
            </ul>

            <!-- Pagination -->
            <div v-if="props.notifications?.last_page > 1" class="mt-6 flex items-center justify-between">
              <div class="text-sm text-gray-700 dark:text-gray-300">
                Showing
                <span class="font-medium">{{ props.notifications.from || 0 }}</span>
                to
                <span class="font-medium">{{ props.notifications.to || 0 }}</span>
                of
                <span class="font-medium">{{ props.notifications.total || 0 }}</span>
                results
              </div>
              <nav class="inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
                <button
                  v-for="(link, idx) in props.notifications.links"
                  :key="idx"
                  type="button"
                  @click="link.url && router.visit(link.url, { preserveState: true, replace: true, only: ['notifications'] })"
                  :class="[
                    'px-3 py-2 border text-sm font-medium',
                    link.active ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600 border-gray-300 dark:border-gray-600',
                    !link.url ? 'pointer-events-none opacity-50' : ''
                  ]"
                  v-html="link.label"
                />
              </nav>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
  </template>


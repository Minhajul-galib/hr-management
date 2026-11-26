import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export const ratingClasses = {
    zero_tolerance: 'bg-red-100 text-red-800 border-red-200 dark:bg-red-900/40 dark:text-red-300 dark:border-red-800',
    critical: 'bg-orange-100 text-orange-800 border-orange-200 dark:bg-orange-900/40 dark:text-orange-300 dark:border-orange-800',
    major: 'bg-amber-100 text-amber-800 border-amber-200 dark:bg-amber-900/40 dark:text-amber-300 dark:border-amber-800',
    minor: 'bg-blue-100 text-blue-800 border-blue-200 dark:bg-blue-900/40 dark:text-blue-300 dark:border-blue-800',
    observation: 'bg-gray-100 text-gray-700 border-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600',
    compliant: 'bg-green-100 text-green-800 border-green-200 dark:bg-green-900/40 dark:text-green-300 dark:border-green-800',
};

export const ratingShort = {
    zero_tolerance: 'ZT',
    critical: 'Critical',
    major: 'Major',
    minor: 'Minor',
    observation: 'Obs.',
};

export const resultClasses = {
    excellent: 'bg-green-100 text-green-800 border-green-300 dark:bg-green-900/40 dark:text-green-300 dark:border-green-700',
    satisfactory: 'bg-yellow-100 text-yellow-800 border-yellow-300 dark:bg-yellow-900/40 dark:text-yellow-300 dark:border-yellow-700',
    need_improvement: 'bg-orange-100 text-orange-800 border-orange-300 dark:bg-orange-900/40 dark:text-orange-300 dark:border-orange-700',
    continuous_improvement: 'bg-sky-100 text-sky-800 border-sky-300 dark:bg-sky-900/40 dark:text-sky-300 dark:border-sky-700',
    zero_tolerance: 'bg-red-100 text-red-800 border-red-300 dark:bg-red-900/40 dark:text-red-300 dark:border-red-700',
};

export const resultSwatch = {
    excellent: 'bg-green-500',
    satisfactory: 'bg-yellow-400',
    need_improvement: 'bg-orange-500',
    continuous_improvement: 'bg-sky-500',
    zero_tolerance: 'bg-red-600',
};

export const statusClasses = {
    draft: 'bg-gray-100 text-gray-700 border-gray-300 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600',
    completed: 'bg-indigo-100 text-indigo-800 border-indigo-200 dark:bg-indigo-900/40 dark:text-indigo-300 dark:border-indigo-800',
};

export const inputClass =
    'block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';

// Plain Y-m-d dates are parsed as local dates so the day never shifts with the timezone;
// full timestamps are converted to the viewer's local day.
export function formatDate(value) {
    if (!value) return '—';
    const text = String(value);
    const date = text.length === 10
        ? new Date(...text.split('-').map((part, i) => Number(part) - (i === 1 ? 1 : 0)))
        : new Date(text);
    return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

export function useAuditPermissions() {
    const page = usePage();
    const permissions = computed(() => page.props.auth?.permissions || []);

    // Each action has its own permission; `manage audits` is full access and stands in for all.
    const may = (permission) => computed(() =>
        permissions.value.includes(permission) || permissions.value.includes('manage audits'));

    return {
        canCreate: may('create audits'),
        canEdit: may('edit audits'),
        canDelete: may('delete audits'),
        canComplete: may('complete audits'),
        canReopen: may('reopen audits'),
        canUpdateCap: may('update audit cap'),
    };
}

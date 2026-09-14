<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { useSweetAlert } from '@/composables/useSweetAlert';
import CliftoTalkDock from '@/Components/CliftoTalk/CliftoTalkDock.vue';
import { useCliftoTalk } from '@/composables/useCliftoTalk';
import {
    Bell,
    Menu,
    X,
    MessageCircle,
    LayoutDashboard,
    Calendar,
    CheckSquare,
    Users,
    Shield,
    Building,
    BellRing,
    Settings,
    ChevronLeft,
    ChevronRight,
    Sun,
    Moon,
    Monitor,
    Package,
    BookOpen,
    Info,
    CalendarDays,
} from 'lucide-vue-next';

const showingNavigationDropdown = ref(false);
const sidebarCollapsed = ref(false);
const darkMode = ref(false);
const themePreference = ref('system'); // 'light', 'dark', 'system'
const page = usePage();
const { showSuccess, showError, showWarning, showInfo } = useSweetAlert();

const user = computed(() => page.props.auth.user);
const userRoles = computed(() => page.props.auth.roles || []);
const userPermissions = computed(() => page.props.auth?.permissions || []);
const isImpersonating = computed(() => page.props.impersonate?.is_impersonating || false);

// Clifto Talk — the badge reads the live store once the dock has booted,
// and falls back to the server-rendered count on first paint.
const cliftoTalk = useCliftoTalk();
const cliftoTalkEnabled = computed(() => Boolean(page.props.cliftoTalk?.enabled));
const cliftoTalkBrand = computed(() => page.props.cliftoTalk?.brand || 'Clifto Talk');
const cliftoTalkUrl = computed(() => `/${page.props.cliftoTalk?.prefix || 'clifto-talk'}`);
const cliftoTalkUnread = computed(() =>
    cliftoTalk.state.booted
        ? cliftoTalk.unreadTotal.value
        : (page.props.cliftoTalk?.unread_total ?? 0),
);

// Primary role display name for the header
const primaryRoleLabel = computed(() => {
    const rolesWithMeta = page.props.auth.rolesWithMeta || [];
    if (rolesWithMeta.length === 0) return null;
    // Pick first role; prefer one with a display_name
    const withDisplay = rolesWithMeta.find(r => r.display_name);
    const role = withDisplay || rolesWithMeta[0];
    return role.display_name || role.name;
});

// Watch for flash messages
watch(() => page.props.flash, (flash) => {
    if (flash?.success) {
        showSuccess(flash.success);
    }
    if (flash?.error) {
        showError(flash.error);
    }
    if (flash?.warning) {
        showWarning(flash.warning);
    }
    if (flash?.info) {
        showInfo(flash.info);
    }
}, { deep: true });

// Notifications
const showNotifications = ref(false);
const notifications = computed(() => page.props.notifications?.items || []);
const unreadCount = ref(page.props.notifications?.unread_count ?? 0);

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

const getNotificationLink = () => route('notifications.index');

const markNotificationAsRead = async (notification) => {
    if (notification.read_at) return;
    
    try {
        await axios.post(route('notifications.read', notification.id));
        // Update the notification locally to mark as read
        notification.read_at = new Date().toISOString();
        // Update unread count
        if (unreadCount.value > 0) {
            unreadCount.value--;
        }
    } catch (error) {
        console.error('Failed to mark notification as read:', error);
    }
};

const showToast = ref(false);
const lastNotification = ref(null);

function playAlarm() {
    try {
        const audio = new Audio('/alarm.mp3');
        audio.play().catch(() => {});
    } catch (e) {}
}

onMounted(() => {
    // Load sidebar state from localStorage
    const stored = localStorage.getItem('sidebarCollapsed');
    if (stored !== null) {
        sidebarCollapsed.value = stored === 'true';
    }
    
    // Load theme preference
    const savedTheme = localStorage.getItem('theme') || 'system';
    themePreference.value = savedTheme;
    applyTheme(savedTheme);
    
    // Listen for system theme changes
    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
    const handleSystemThemeChange = () => {
        if (themePreference.value === 'system') {
            applyTheme('system');
        }
    };
    
    mediaQuery.addEventListener('change', handleSystemThemeChange);
    
    try {
        const uid = user.value?.id;
        if (window.Echo && uid) {
            window.Echo.private(`App.Models.User.${uid}`)
                .notification((notification) => {
                    lastNotification.value = notification;
                    showToast.value = true;
                    playAlarm();
                    setTimeout(() => { showToast.value = false; }, 5000);
                });
        }
    } catch (e) {
        console.warn('Realtime notifications not bound', e);
    }
});

// Define the menu structure with groups
const menuGroups = [
    {
        name: 'Overview',
        items: [
            { name: 'Dashboard', routeName: 'dashboard', permission: 'view dashboard', icon: LayoutDashboard },
        ]
    },
    // {
    //     name: 'Reports',
    //     items: [
    //         { name: 'Monthly Summary', routeName: 'dashboard', permission: 'view reports', icon: FileText },
    //         { name: 'Fiscal Year Report', routeName: 'dashboard', permission: 'view reports', icon: TrendingUp },
    //     ]
    // },
    {
        name: 'System',
        items: [
            { name: 'Clifto Talk', routeName: 'clifto-talk.index', permission: 'access clifto talk', icon: MessageCircle },
            { name: 'Calendar & Todo', routeName: 'calendar.index', permission: 'view calendar', icon: Calendar },
            { name: 'Todos', routeName: 'todos.index', permission: 'access todo', icon: CheckSquare },
            { name: 'Holidays', routeName: 'calendar.holidays.setup', permission: 'manage holidays', icon: CalendarDays },
            { name: 'Units', routeName: 'units.index', permission: ['manage units', 'view units'], icon: Package },
            { name: 'User Management', routeName: 'users.index', permission: 'manage users', icon: Users },
            { name: 'Role Management', routeName: 'roles.index', permission: 'manage roles', icon: Shield },
            { name: 'Departments', routeName: 'departments.index', permission: 'manage departments', icon: Building },
            { name: 'Notification Settings', routeName: 'notification-settings.index', permission: 'manage notification settings', icon: BellRing },
            { name: 'Settings', routeName: 'settings.index', permission: 'manage settings', icon: Settings },
            { name: 'Documentation', routeName: 'documentation', permission: 'view documentation', icon: BookOpen },
        ]
    }
];

// Helper to check if a group has any accessible items
const hasAccessToGroup = (group) => {
    return group.items.some(item => hasAccessTo(item));
};

// Toggle sidebar collapse
const toggleSidebar = () => {
    sidebarCollapsed.value = !sidebarCollapsed.value;
    // Store preference in localStorage
    localStorage.setItem('sidebarCollapsed', sidebarCollapsed.value.toString());
};

// Theme management
const applyTheme = (theme) => {
    const html = document.documentElement;
    
    if (theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        html.classList.add('dark');
        darkMode.value = true;
    } else {
        html.classList.remove('dark');
        darkMode.value = false;
    }
};

const setTheme = (theme) => {
    themePreference.value = theme;
    localStorage.setItem('theme', theme);
    applyTheme(theme);
};

const toggleTheme = () => {
    const themes = ['light', 'dark', 'system'];
    const currentIndex = themes.indexOf(themePreference.value);
    const nextTheme = themes[(currentIndex + 1) % themes.length];
    setTheme(nextTheme);
};

const getThemeIcon = () => {
    switch (themePreference.value) {
        case 'light': return Sun;
        case 'dark': return Moon;
        case 'system': return Monitor;
        default: return Monitor;
    }
};

const getThemeLabel = () => {
    switch (themePreference.value) {
        case 'light': return 'Light Mode';
        case 'dark': return 'Dark Mode';
        case 'system': return 'System Mode';
        default: return 'System Mode';
    }
};

// Load sidebar state from localStorage on mount
onMounted(() => {
    const stored = localStorage.getItem('sidebarCollapsed');
    if (stored !== null) {
        sidebarCollapsed.value = stored === 'true';
    }
});

// Workflow Activity Packages - Mapping workflow permissions to core permissions
const workflowPackages = {
    'workflow purchase draft': [
        'view dashboard', 'manage suppliers', 'manage units', 'manage particulars', 'manage documents'
    ],
    'workflow purchase approval': [
        'view dashboard', 'manage documents'
    ],
    'workflow accounts audit': [
        'view dashboard', 'manage documents', 'view ledger'
    ],
    'workflow dgm approved': [
        'view dashboard', 'manage documents'
    ],
    'workflow gm approved': [
        'view dashboard', 'manage documents'
    ],
    'workflow ceo approved': [
        'view dashboard', 'manage documents'
    ],
    'workflow voucher creation': [
        'view dashboard', 'manage documents', 'view ledger'
    ],
    'workflow voucher audit': [
        'view dashboard', 'manage documents'
    ],
    'workflow dgm voucher approval': [
        'view dashboard', 'manage documents'
    ],
    'workflow gm voucher approval': [
        'view dashboard', 'manage documents'
    ],
    'workflow approval minhaz sir': [
        'view dashboard', 'manage documents'
    ],
    'workflow check making': [
        'view dashboard', 'manage documents', 'view ledger'
    ],
};

// Helper to check if user has any permission related to a system
const hasAnySystemPermission = (systemName) => {
    if (userRoles.value.includes('Super System Admin')) return true;
    
    // Define system permission patterns
    const systemPermissions = {
        'supplier types': ['manage supplier types', 'view supplier types', 'create supplier type', 'edit supplier type', 'delete supplier type'],
        'units': ['manage units', 'view units', 'create unit', 'edit unit', 'delete unit'],
        'particulars': ['manage particulars', 'view particulars', 'create particular', 'edit particular', 'delete particular'],
        'documents': ['manage documents', 'view documents', 'create document', 'edit document', 'delete document'],
        'supplier ledger': ['workflow-accounts_audit'],
        'supplier tracking': ['track suppliers', 'view tracking'],
        'payment tracking': ['view ledger', 'manage payment tracking'],
        'user management': ['manage users', 'view users', 'create user', 'edit user', 'delete user'],
        'role management': ['manage roles', 'view roles', 'create role', 'edit role', 'delete role'],
        'departments': ['manage departments'],
        'notification settings': ['manage notification settings', 'view notification settings'],
        'settings': ['manage settings', 'view settings'],
        'status setup': ['manage document statuses', 'view document statuses'],
        'calendar & todo': ['view calendar', 'access todo'],
        'todos': ['access todo', 'manage todos'],
        'dashboard': ['view dashboard'],
        'all bill': ['view bills'],
    };
    
    // Normalize system name to lowercase
    const normalizedSystemName = systemName.toLowerCase();
    const requiredPerms = systemPermissions[normalizedSystemName] || [];
    
    if (requiredPerms.length === 0) return false; // No permissions mapped → hide by default, rely on item.permission
    
    // Check if user has any of the system permissions
    return userPermissions.value.some(userPerm => {
        const userPermNormalized = userPerm.replace(/-/g, ' ').toLowerCase();
        return requiredPerms.includes(userPermNormalized);
    });
};

// Helper to check if user has access to a menu item
// Super System Admin always sees everything; otherwise permission is required
const hasAccessTo = (item) => {
    if (userRoles.value.includes('Super System Admin')) return true;
    if (item.public) return true;
    
    // Check if user has any permission related to this system
    if (hasAnySystemPermission(item.name)) {
        return true;
    }
    
    if (item.permission) {
        // Normalize permissions to an array of strings
        const requiredPermissions = Array.isArray(item.permission) 
            ? item.permission.map(p => p.replace(/-/g, ' ').toLowerCase())
            : [item.permission.replace(/-/g, ' ').toLowerCase()];
        
        // 1. Check direct permissions (any of the required permissions will grant access)
        const hasDirectAccess = userPermissions.value.some(p => {
            const userPermNormalized = p.replace(/-/g, ' ').toLowerCase();
            return requiredPermissions.includes(userPermNormalized);
        });
        
        if (hasDirectAccess) return true;
        
        // 2. Check workflow packages
        // If user has a workflow permission, they get all permissions in that package
        return userPermissions.value.some(userPerm => {
            const userPermNormalized = userPerm.replace(/-/g, ' ').toLowerCase();
            const packagePerms = workflowPackages[userPermNormalized];
            
            if (packagePerms) {
                // Check if any of the required permissions are in this workflow package
                return requiredPermissions.some(rp => packagePerms.includes(rp));
            }
            return false;
        });
    }
    
    return false; // If no permission specified and no system access, hide it
};

// Helper to get route URL safely
const getRoute = (name) => {
    // Check if Ziggy's route() function has the route defined
    // Note: route().has() might not be available in all versions, fallback to try-catch or simple check
    try {
        if (route().has(name)) {
            return route(name);
        }
    } catch (e) {
        console.warn(`Route ${name} check failed`, e);
    }
    return '#';
};

const SIDEBAR_ROUTE_PREFIX_GROUPS = [];

const isActive = (name) => {
    try {
        if (!route().has(name)) {
            return false;
        }
        if (route().current(name)) {
            return true;
        }

        let currentRouteName = '';
        try {
            currentRouteName = typeof route().current === 'function' ? route().current() : '';
        } catch (_) {
            currentRouteName = '';
        }
        if (!currentRouteName || typeof currentRouteName !== 'string') {
            return false;
        }

        for (const [indexRoute, prefix] of SIDEBAR_ROUTE_PREFIX_GROUPS) {
            if (name === indexRoute && currentRouteName.startsWith(prefix)) {
                return true;
            }
        }

        return false;
    } catch (e) {
        return false;
    }
};

// Helper to format date
// Removed duplicate declarations; single definition kept above with notifications state
// Helper to get notification link
// Single definition kept above; use getNotificationLink(n) in template
</script>

<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 transition-colors duration-300">
        <div
            v-if="showToast && lastNotification"
            class="fixed top-4 right-4 z-50 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-lg rounded-md p-3 w-80"
            role="alert"
        >
            <div class="font-semibold text-gray-900 dark:text-gray-100">
                {{ lastNotification.title || lastNotification.data?.title || 'Notification' }}
            </div>
            <div class="text-sm text-gray-700 dark:text-gray-300 mt-1">
                {{ lastNotification.body || lastNotification.data?.body || '' }}
            </div>
        </div>
        <!-- Impersonation Banner -->
        <div v-if="isImpersonating" class="bg-amber-50 border-b border-amber-200 text-amber-900 py-2 px-4 z-50 relative">
            <div class="flex items-center justify-center gap-3 text-sm">
                <span class="flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse flex-shrink-0"></span>
                    <span>You are viewing as <strong class="font-semibold">{{ user.name }}</strong></span>
                </span>
                <span class="text-amber-300">|</span>
                <Link :href="route('impersonate.stop')" class="inline-flex items-center gap-1 text-xs font-semibold text-amber-700 dark:text-amber-300 border border-amber-300 dark:border-amber-700 bg-white dark:bg-gray-800 hover:bg-amber-50 dark:hover:bg-amber-900/30 px-2.5 py-1 rounded-md transition-colors duration-150">
                    Exit Session
                </Link>
            </div>
        </div>
 
        <div class="flex min-h-screen">
            <!-- Sidebar -->
            <aside 
                :class="[
                    'bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 hidden md:flex md:flex-col transition-all duration-300 ease-in-out',
                    sidebarCollapsed ? 'w-16' : 'w-64'
                ]"
            >
                <!-- Sidebar Header -->
                <div class="h-16 flex items-center justify-between border-b border-gray-200 dark:border-gray-700 px-4">
                    <Link 
                        :href="route('dashboard')" 
                        :class="[
                            'transition-opacity duration-300',
                            sidebarCollapsed ? 'opacity-0 w-0 overflow-hidden' : 'opacity-100'
                        ]"
                    >
                        <ApplicationLogo class="block h-9 w-auto fill-current text-gray-800 dark:text-gray-200" />
                    </Link>
                    
                    <!-- Sidebar Toggle Button -->
                    <button
                        @click="toggleSidebar"
                        class="p-2 rounded-md text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors duration-200 sidebar-toggle"
                        :title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                    >
                        <ChevronLeft v-if="!sidebarCollapsed" :size="20" />
                        <ChevronRight v-else :size="20" />
                    </button>
                </div>
                
                <!-- Navigation Menu -->
                <div class="flex-1 overflow-y-auto py-4">
                    <nav :class="sidebarCollapsed ? 'px-2' : 'px-2'">
                        <div class="space-y-1">
                                                        <template v-for="(group, gIndex) in menuGroups" :key="gIndex">
                                <div v-if="hasAccessToGroup(group)" :class="{'mt-4': gIndex > 0}">
                                    <h3 v-if="!sidebarCollapsed" class="px-3 text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest mb-2 flex items-center gap-2">
                                        <span class="h-px bg-gray-200 dark:bg-gray-700 flex-1"></span>
                                        {{ group.name }}
                                        <span class="h-px bg-gray-200 dark:bg-gray-700 flex-1"></span>
                                    </h3>
                                    <div class="space-y-1">
                                        <template v-for="(item, iIndex) in group.items" :key="iIndex">
                                            <div v-if="hasAccessTo(item)" class="relative group">
                                                <Link
                                                    :href="getRoute(item.routeName)"
                                                    :class="[
                                                        isActive(item.routeName)
                                                            ? 'bg-blue-50 dark:bg-blue-900/50 text-blue-700 dark:text-blue-300 border-r-2 border-blue-700 dark:border-blue-400'
                                                            : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 hover:text-gray-900 dark:hover:text-gray-100',
                                                        'group flex items-center py-2 text-sm font-medium rounded-l-md transition-colors duration-200 sidebar-menu-item',
                                                        sidebarCollapsed ? 'px-2 justify-center' : 'px-3'
                                                    ]"
                                                >
                                                    <component
                                                        :is="item.icon"
                                                        :size="20"
                                                        :class="[
                                                            'flex-shrink-0 sidebar-icon',
                                                            sidebarCollapsed ? '' : 'mr-3',
                                                            isActive(item.routeName) ? 'text-blue-700 dark:text-blue-300' : 'text-gray-400 dark:text-gray-500 group-hover:text-gray-500 dark:group-hover:text-gray-400'
                                                        ]"
                                                    />
                                                    <span
                                                        :class="[
                                                            'transition-all duration-300 sidebar-text',
                                                            sidebarCollapsed ? 'opacity-0 w-0 overflow-hidden' : 'opacity-100'
                                                        ]"
                                                    >
                                                        {{ item.name }}
                                                    </span>
                                                </Link>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </nav>
                </div>
            </aside>

            <!-- Main Content Wrapper -->
            <div 
                :class="[
                    'flex-1 flex flex-col transition-all duration-300 ease-in-out',
                    sidebarCollapsed ? 'md:ml-0' : 'md:ml-0'
                ]"
            >
                <!-- Top Header -->
                <nav class="bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700 transition-colors duration-300">
                    <div class="px-4 sm:px-6 lg:px-8">
                        <div class="flex h-16 justify-between items-center">
                        <div class="flex items-center">
                                <!-- Desktop Sidebar Toggle (hidden on mobile) -->
                                <div class="hidden md:flex items-center mr-4">
                                    <button
                                        @click="toggleSidebar"
                                        class="p-2 rounded-md text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors duration-200 sidebar-toggle"
                                        :title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                                    >
                                        <Menu :size="20" />
                                    </button>
                                </div>
                                
                                <!-- Mobile Menu Button -->
                                <div class="-me-2 flex items-center md:hidden">
                                    <button
                                        @click="showingNavigationDropdown = !showingNavigationDropdown"
                                        class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 dark:text-gray-500 transition duration-150 ease-in-out hover:bg-gray-100 dark:hover:bg-gray-700 hover:text-gray-500 dark:hover:text-gray-400 focus:bg-gray-100 dark:focus:bg-gray-700 focus:text-gray-500 dark:focus:text-gray-400 focus:outline-none"
                                    >
                                        <Menu v-if="!showingNavigationDropdown" :size="24" />
                                        <X v-else :size="24" />
                                    </button>
                                </div>
                                
                                <!-- Logo for Mobile -->
                                <div class="flex shrink-0 items-center md:hidden ml-4">
                                    <Link :href="route('dashboard')">
                                        <ApplicationLogo class="block h-9 w-auto fill-current text-gray-800 dark:text-gray-200" />
                                    </Link>
                                </div>
                            </div>

                            <!-- Center: Role Display Name Badge + Department -->
                            <div class="hidden md:flex flex-1 justify-center items-center gap-2">
                                <div v-if="primaryRoleLabel" class="flex items-center gap-2 px-3 py-1 rounded-full bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-700">
                                    <span class="w-1.5 h-1.5 rounded-full bg-blue-500 flex-shrink-0"></span>
                                    <span class="text-xs font-semibold text-blue-700 dark:text-blue-300 tracking-wide">{{ primaryRoleLabel }}</span>
                                </div>
                                <div v-if="user?.department?.name" class="flex items-center gap-1.5 px-3 py-1 rounded-full bg-gray-100 dark:bg-gray-700 border border-gray-200 dark:border-gray-600">
                                    <span class="w-1.5 h-1.5 rounded-full bg-gray-400 dark:bg-gray-400 flex-shrink-0"></span>
                                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300 tracking-wide">Department: </span>
                                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300 tracking-wide">{{ user.department.name }}</span>
                                </div>
                            </div>

                            <div class="flex items-center space-x-3">
                                <!-- Theme Toggle -->
                                <div class="relative">
                                    <button
                                        @click="toggleTheme"
                                        class="relative inline-flex items-center rounded-md bg-white dark:bg-gray-800 p-2 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none transition-colors duration-200"
                                        :title="getThemeLabel()"
                                    >
                                        <component :is="getThemeIcon()" :size="18" />
                                    </button>
                                </div>

                                <!-- Clifto Talk -->
                                <Link
                                    v-if="cliftoTalkEnabled"
                                    :href="cliftoTalkUrl"
                                    class="relative inline-flex items-center rounded-md bg-white dark:bg-gray-800 p-2 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none transition-colors duration-200"
                                    :title="`${cliftoTalkBrand}${cliftoTalkUnread ? ` — ${cliftoTalkUnread} unread` : ''}`"
                                >
                                    <MessageCircle :size="18" />
                                    <span
                                        v-if="cliftoTalkUnread > 0"
                                        class="absolute -top-1 -right-1 inline-flex items-center justify-center px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white bg-indigo-600 rounded-full"
                                    >
                                        {{ cliftoTalkUnread > 99 ? '99+' : cliftoTalkUnread }}
                                    </span>
                                </Link>

                                <!-- Notifications -->
                                <div class="relative">
                                    <button
                                        @click="showNotifications = !showNotifications"
                                        type="button"
                                        class="relative inline-flex items-center rounded-md bg-white dark:bg-gray-800 p-2 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none transition-colors duration-200"
                                        :aria-expanded="showNotifications.toString()"
                                    >
                                        <Bell :size="18" />
                                        <span v-if="unreadCount > 0" class="absolute -top-1 -right-1 inline-flex items-center justify-center px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white bg-red-600 rounded-full">{{ unreadCount }}</span>
                                    </button>

                                    <div v-if="showNotifications" class="absolute right-0 mt-2 w-80 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg z-50">
                                        <div class="p-2">
                                            <div class="flex justify-between items-center px-2 py-2 border-b border-gray-200 dark:border-gray-700">
                                                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Notifications</h3>
                                                <span v-if="unreadCount > 0" class="text-xs text-gray-500 dark:text-gray-400">{{ unreadCount }} unread</span>
                                            </div>
                                            <div class="max-h-96 overflow-auto">
                                                <div v-if="notifications.length === 0" class="text-sm text-gray-500 dark:text-gray-400 p-4 text-center">No notifications</div>

                                                <template v-else>
                                                    <Link 
                                                        v-for="(n, i) in notifications.slice(0,10)" 
                                                        :key="n.id || i" 
                                                        :href="getNotificationLink()"
                                                        @click="markNotificationAsRead(n)"
                                                        class="block px-3 py-3 text-sm hover:bg-gray-50 dark:hover:bg-gray-700 border-b border-gray-100 dark:border-gray-700 transition-colors duration-200"
                                                        :class="{ 
                                                            'bg-blue-50 dark:bg-blue-900/20': !n.read_at && n.data?.priority !== 'high',
                                                            'bg-red-50 dark:bg-red-900/20 border-l-4 border-red-500': n.data?.priority === 'high'
                                                        }"
                                                    >
                                                        <div class="flex items-start gap-2">
                                                            <div class="flex-1">
                                                                <div class="font-medium text-gray-900 dark:text-gray-100">{{ n.data?.title || 'Notification' }}</div>
                                                                <div class="text-xs text-gray-600 dark:text-gray-400 mt-1">{{ n.data?.body || n.data?.message || '' }}</div>
                                                                <div class="text-xs text-gray-400 dark:text-gray-500 mt-1">{{ formatDate(n.created_at) }}</div>
                                                            </div>
                                                            <span v-if="!n.read_at" class="w-2 h-2 bg-blue-600 rounded-full mt-1"></span>
                                                        </div>
                                                    </Link>

                                                    <div class="border-t border-gray-200 dark:border-gray-700 mt-2 pt-2 text-center">
                                                        <Link :href="route('notifications.index')" class="text-sm text-blue-600 dark:text-blue-400 hover:underline">View all notifications</Link>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Settings Dropdown -->
                                <div class="relative">
                                    <Dropdown align="right" width="48">
                                        <template #trigger>
                                            <span class="inline-flex rounded-md">
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center gap-2.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 pl-2 pr-3 py-1.5 transition duration-150 ease-in-out hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none"
                                                >
                                                    <!-- Avatar circle -->
                                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-blue-600 flex items-center justify-center text-white text-xs font-bold select-none">
                                                        {{ user.name?.charAt(0)?.toUpperCase() }}
                                                    </span>
                                                    <!-- Name + role -->
                                                    <span class="flex flex-col items-start leading-tight">
                                                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ user.name }}</span>
                                                        <span v-if="primaryRoleLabel" class="text-[10px] text-blue-500 dark:text-blue-400 font-medium">{{ primaryRoleLabel }}</span>
                                                    </span>
                                                    <!-- Chevron -->
                                                    <svg class="h-3.5 w-3.5 text-gray-400 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                                    </svg>
                                                </button>
                                            </span>
                                        </template>

                                        <template #content>
                                            <DropdownLink :href="route('profile.edit')"> Profile </DropdownLink>
                                            <DropdownLink :href="route('logout')" method="post" as="button">
                                                Log Out
                                            </DropdownLink>
                                        </template>
                                    </Dropdown>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Responsive Navigation Menu (Mobile Sidebar equivalent) -->
                    <div
                        :class="{ block: showingNavigationDropdown, hidden: !showingNavigationDropdown }"
                        class="md:hidden border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 transition-colors duration-300"
                    >
                        <div class="space-y-1 pb-3 pt-2">
                                                          <template v-for="(group, gIndex) in menuGroups" :key="gIndex">
                                <div v-if="hasAccessToGroup(group)" class="pt-2 pb-1">
                                    <div class="px-4 text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest mb-1">
                                        {{ group.name }}
                                    </div>
                                    <template v-for="(item, iIndex) in group.items" :key="iIndex">
                                        <ResponsiveNavLink
                                            v-if="hasAccessTo(item)"
                                            :href="getRoute(item.routeName)"
                                            :active="isActive(item.routeName)"
                                            class="flex items-center text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100"
                                        >
                                            <component
                                                :is="item.icon"
                                                :size="20"
                                                class="mr-3 text-gray-400 dark:text-gray-500"
                                            />
                                            {{ item.name }}
                                        </ResponsiveNavLink>
                                    </template>
                                </div>
                            </template>
                        </div>

                        <div class="border-t border-gray-200 dark:border-gray-700 pb-1 pt-4">
                            <div class="px-4">
                                <div class="text-base font-medium text-gray-800 dark:text-gray-200">
                                    {{ user.name }}
                                </div>
                                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                    {{ user.email }}
                                </div>
                            </div>

                            <div class="mt-3 space-y-1">
                                <ResponsiveNavLink :href="route('profile.edit')" class="text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100"> Profile </ResponsiveNavLink>
                                <ResponsiveNavLink :href="route('logout')" method="post" as="button" class="text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100">
                                    Log Out
                                </ResponsiveNavLink>
                            </div>
                        </div>
                    </div>
                </nav>

                <!-- Page Heading -->
                <header class="bg-white dark:bg-gray-800 shadow transition-colors duration-300" v-if="$slots.header">
                    <div class="mx-auto px-4 py-5 sm:px-6 lg:px-8 text-gray-900 dark:text-gray-100 [&_h1]:!text-xl sm:[&_h1]:!text-2xl [&_h1]:!font-semibold [&_h1]:!leading-tight [&_h1]:!text-gray-900 dark:[&_h1]:!text-gray-100 [&_h2]:!text-lg sm:[&_h2]:!text-xl [&_h2]:!font-semibold [&_h2]:!leading-tight [&_h2]:!text-gray-900 dark:[&_h2]:!text-gray-100 [&_p]:!text-sm [&_p]:!text-gray-600 dark:[&_p]:!text-gray-300">
                        <slot name="header" />
                    </div>
                </header>

                <!-- Page Content -->
                <main>
                    <slot />
                </main>


                <!-- Page footer -->
                <footer class="mt-auto border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 transition-colors duration-300">
                    <div class="px-4 sm:px-6 lg:px-8 py-3">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-sm text-gray-600 dark:text-gray-300">
                            <p>HRIS by the Clifton Group Team.</p>
                            <p class="sm:text-right flex items-center gap-1.5">
                                Developed by Clifton Group ERP
                                <Link :href="route('credits')" title="Credits & Acknowledgements" class="inline-flex items-center text-blue-500 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 transition-colors duration-200">
                                    <Info :size="15" />
                                </Link>
                            </p>
                        </div>
                    </div>
                </footer>

            </div>
        </div>

        <!-- Chat dock: mounted once here so it survives Inertia navigation
             and keeps its websocket subscriptions. -->
        <CliftoTalkDock />
    </div>
</template>

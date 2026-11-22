<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useSweetAlert } from '@/composables/useSweetAlert';
import draggable from 'vuedraggable';
import {
    Calendar,
    Plus,
    Clock,
    CheckCircle,
    Circle,
    Trash2,
    AlertCircle,
    Flag,
    Pencil,
    ChevronUp,
    ChevronDown
} from 'lucide-vue-next';

const props = defineProps({
    events: Array,
    todos: Array,
    upcomingEvents: Array,
    holidaySettings: {
        type: Object,
        default: () => ({ all_fridays: false, weekday_off: [] })
    },
    canAccessTodo: {
        type: Boolean,
        default: false
    }
});

const canAccessTodo = computed(() => !!props.canAccessTodo);

// Every calendar day (YYYY-MM-DD) a holiday covers. The server lists them all, so a
// 3-day Eid is marked on three days; older payloads fall back to the start date.
// Read from the string, not a Date, to avoid timezone shifts.
function holidayDays(event) {
    const dates = event.extendedProps && event.extendedProps.dates;
    if (Array.isArray(dates) && dates.length) return dates;

    const m = String(event.start || event.startStr || '').match(/\d{4}-\d{2}-\d{2}/);
    return m ? [m[0]] : [];
}

const holidayEvents = computed(() => (props.events || []).filter(e => e.extendedProps && e.extendedProps.isHoliday));

// Compute holiday dates set for highlighting day cells (reactive)
const holidayDates = computed(() => new Set(holidayEvents.value.flatMap(holidayDays)));

// Map of date (YYYY-MM-DD) -> array of holiday titles for that date
const holidayMap = computed(() => {
    const map = {};
    holidayEvents.value.forEach(e => {
        // Prefer explicit title, fall back to extendedProps.title
        const title = e.title || (e.extendedProps && e.extendedProps.title) || '';
        if (!title) return;
        holidayDays(e).forEach(key => {
            if (!map[key]) map[key] = [];
            map[key].push(title);
        });
    });
    return map;
});

const weeklyOff = computed(() => Array.isArray(props.holidaySettings?.weekday_off) ? props.holidaySettings.weekday_off : []);
const allFridaysOff = computed(() => !!props.holidaySettings?.all_fridays);

function isWeeklyHoliday(date) {
    const dow = date.getDay(); // 0-6 (Sun-Sat)
    if (allFridaysOff.value && dow === 5) return true;
    return weeklyOff.value.includes(dow);
}

// Reactive data
const showEventModal = ref(false);
const showTodoModal = ref(false);
const selectedEvent = ref(null);
const selectedTodo = ref(null);
const pendingTodos = ref((props.todos || []).filter(todo => todo.status === 'Assigned'));
const completedTodos = ref((props.todos || []).filter(todo => todo.status === 'Completed'));
const calendarEl = ref(null);
let calendar = null;
const page = usePage();
const userPermissions = computed(() => page.props.auth?.permissions || []);
const userRoles = computed(() => page.props.auth?.roles || []);
// Holidays have per-action permissions; `manage holidays` is full access.
const mayDoWithHolidays = (...actions) => userRoles.value.includes('Super System Admin')
    || ['manage', ...actions].some((action) => userPermissions.value.includes(`${action} holidays`));
// Opens Holidays Setup: any holiday permission will do (the page then shows what the role may do).
const canOpenHolidaySetup = computed(() => mayDoWithHolidays('view', 'create', 'edit', 'delete', 'import'));
// Ticks "Mark as Holiday" on a new event.
const canAddHolidays = computed(() => mayDoWithHolidays('create'));

// Event form data
const eventForm = ref({
    title: '',
    description: '',
    start: '',
    end: '',
    all_day: false,
    color: '#3788d8',
    reminder_at: '',
    is_holiday: false
});

// Todo form data
const todoForm = ref({
    task: '',
    description: '',
    priority: 'medium',
    due_date: '',
    reminder_at: '',
    category: ''
});

// Load FullCalendar from CDN
const loadFullCalendar = () => {
    return new Promise((resolve, reject) => {
        if (window.FullCalendar) {
            resolve(window.FullCalendar);
            return;
        }

        // Load CSS
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css';
        document.head.appendChild(link);

        // Load JS
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js';
        script.onload = () => {
            resolve(window.FullCalendar);
        };
        script.onerror = reject;
        document.head.appendChild(script);
    });
};

// Initialize calendar
const initializeCalendar = async () => {
    try {
        const FullCalendar = await loadFullCalendar();
        
        if (calendar) {
            calendar.destroy();
        }

        calendar = new FullCalendar.Calendar(calendarEl.value, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            },
            editable: true,
            selectable: true,
            selectMirror: true,
            dayMaxEvents: true,
            weekends: true,
            events: [],

            dayCellDidMount: (info) => {
                // Build YYYY-MM-DD using local date components to match the calendar's displayed date
                const dt = info.date;
                const key = `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`;

                const isHolidayDay = (holidayDates.value && holidayDates.value.has(key)) || isWeeklyHoliday(info.date);
                if (isHolidayDay) {
                    info.el.classList.add('bg-red-50', 'border', 'border-red-200');
                    info.el.setAttribute('title', 'Holiday');
                }

                // Remove existing holiday labels to avoid duplicates on re-render
                const existingLabels = info.el.querySelectorAll('.fc-holiday-label');
                existingLabels.forEach(n => n.remove());

                // If there are holiday titles for this date, add them as plain text (no background)
                const titles = (holidayMap.value && holidayMap.value[key]) || [];
                if (titles.length) {
                    // Use the day events container where possible
                    const container = info.el.querySelector('.fc-daygrid-day-events') || info.el;
                    const wrap = document.createElement('div');
                    wrap.className = 'mt-1 space-y-1 fc-holiday-list';
                    titles.forEach(t => {
                        const span = document.createElement('div');
                        span.className = 'fc-holiday-label text-xs text-red-700 font-medium';
                        span.style.lineHeight = '1';
                        span.style.marginTop = '2px';
                        span.style.marginLeft = '12px';
                        span.textContent = t;
                        wrap.appendChild(span);
                    });
                    container.appendChild(wrap);
                }
            },

            
            // Event handlers
            dateClick: (info) => {
                openEventModal(info.dateStr);
            },
            
            eventClick: (info) => {
                selectedEvent.value = info.event;
                eventForm.value = {
                    title: info.event.title,
                    description: info.event.extendedProps.description || '',
                    start: info.event.startStr,
                    end: info.event.endStr || '',
                    all_day: info.event.allDay,
                    color: info.event.color || '#3788d8',
                    reminder_at: info.event.extendedProps.reminderAt || '',
                    is_holiday: !!info.event.extendedProps.isHoliday
                };
                showEventModal.value = true;
            },

            
            eventDrop: (info) => {
                updateEventDate(info.event);
            },
            
            eventResize: (info) => {
                updateEventDate(info.event);
            },
            
            select: (info) => {
                openEventModal(info.startStr, info.endStr);
            }
        });

        calendar.render();

        // Add initial events as a source (deduplicated by id). Exclude holiday events from display — holidays are shown via day cell highlight only.
        const initialEvents = (props.events || []).filter((v, i, a) => a.findIndex(x => x.id === v.id) === i);
        const eventsToShow = initialEvents.filter(e => !(e.extendedProps && e.extendedProps.isHoliday));
        if (eventsToShow.length) {
            calendar.addEventSource(eventsToShow);
        }

    } catch (error) {
        console.error('Failed to load FullCalendar:', error);
        // Fallback: Show a simple calendar message
        if (calendarEl.value) {
            calendarEl.value.innerHTML = `
                <div class="p-8 text-center border-2 border-dashed border-gray-300 rounded-lg">
                    <div class="text-gray-500 mb-4">
                        <svg class="mx-auto h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Calendar Loading...</h3>
                    <p class="text-gray-500">Please refresh the page if the calendar doesn't load.</p>
                    <button onclick="window.location.reload()" class="mt-4 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                        Refresh Page
                    </button>
                </div>
            `;
        }
    }
};

// Methods
function openEventModal(start, end = null) {
    selectedEvent.value = null;
    eventForm.value = {
        title: '',
        description: '',
        start: start,
        end: end || '',
        all_day: false,
        color: '#3788d8',
        reminder_at: '',
        is_holiday: false
    };
    showEventModal.value = true;
}

function openTodoModal() {
    let todo = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : null;
    selectedTodo.value = todo;
    if (todo) {
        todoForm.value = {
            task: todo.task,
            description: todo.description || '',
            priority: todo.priority || 'medium',
            due_date: todo.due_date || '',
            reminder_at: todo.reminder_at || '',
            category: todo.category || ''
        };
    } else {
        todoForm.value = {
            task: '',
            description: '',
            priority: 'medium',
            due_date: '',
            reminder_at: '',
            category: ''
        };
    }
    showTodoModal.value = true;
}

function saveEvent() {
    if (selectedEvent.value) {
        router.patch(`/calendar/events/${selectedEvent.value.id}`, eventForm.value, {
            preserveScroll: true,
            onSuccess: () => {
                showEventModal.value = false;
                selectedEvent.value = null;
            }
        });
    } else {
        const isHolidayCreate = canAddHolidays.value && eventForm.value.is_holiday;
        const url = isHolidayCreate ? route('calendar.holidays.store') : '/calendar/events';
        router.post(url, eventForm.value, {
            preserveScroll: true,
            onSuccess: () => {
                showEventModal.value = false;
            },
            // e.g. "Victory Day is already a holiday on 16 Dec 2026" — say so instead of failing silently.
            onError: (errors) => {
                const message = Object.values(errors)[0];
                if (message) useSweetAlert().showError(String(message), "Couldn't save");
            }
        });
    }
}

function deleteEvent() {
    if (selectedEvent.value && confirm('Are you sure you want to delete this event?')) {
        router.delete(`/calendar/events/${selectedEvent.value.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                showEventModal.value = false;
                selectedEvent.value = null;
            }
        });
    }
}

function saveTodo() {
    if (selectedTodo.value) {
        router.patch(`/calendar/todos/${selectedTodo.value.id}`, todoForm.value, {
            preserveScroll: true,
            onSuccess: () => {
                showTodoModal.value = false;
                selectedTodo.value = null;
            }
        });
    } else {
        router.post('/calendar/todos', todoForm.value, {
            preserveScroll: true,
            onSuccess: () => {
                showTodoModal.value = false;
            }
        });
    }
}


function toggleTodoComplete(todo) {
    router.patch(`/calendar/todos/${todo.id}`, {
        status: todo.status === 'Completed' ? 'Assigned' : 'Completed'
    }, {
        preserveScroll: true
    });
}

function deleteTodo(todo) {
    if (confirm('Are you sure you want to delete this todo?')) {
        router.delete(`/calendar/todos/${todo.id}`, {
            preserveScroll: true
        });
    }
}

function updateEventDate(event) {
    router.patch(`/calendar/events/${event.id}`, {
        start: event.startStr,
        end: event.endStr
    }, { 
        preserveScroll: true 
    });
}

function updateTodoOrder() {
    const ordered = [...pendingTodos.value, ...completedTodos.value].map((todo, index) => ({
        id: todo.id,
        order: index + 1
    }));

    // Use axios for a plain JSON request so Inertia doesn't expect an Inertia response
    axios.post(route('calendar.todos.order.post'), { todos: ordered })
        .then(() => {
            // success (no UI change required)
        })
        .catch((err) => {
            console.error('Failed to update todo order', err);
        });
}

function moveTodoUp(todo) {
    const list = todo.status === 'Completed' ? completedTodos.value : pendingTodos.value;
    const idx = list.findIndex(t => t.id === todo.id);
    if (idx > 0) {
        const tmp = list[idx - 1];
        list[idx - 1] = list[idx];
        list[idx] = tmp;
        updateTodoOrder();
    }
}

function moveTodoDown(todo) {
    const list = todo.status === 'Completed' ? completedTodos.value : pendingTodos.value;
    const idx = list.findIndex(t => t.id === todo.id);
    if (idx !== -1 && idx < list.length - 1) {
        const tmp = list[idx + 1];
        list[idx + 1] = list[idx];
        list[idx] = tmp;
        updateTodoOrder();
    }
}

const priorityIcon = (priority) => {
    switch(priority) {
        case 'high': return AlertCircle;
        case 'medium': return Flag;
        case 'low': return Circle;
        default: return Circle;
    }
};

const priorityColor = (priority) => {
    switch(priority) {
        case 'high': return 'text-red-500';
        case 'medium': return 'text-yellow-500';
        case 'low': return 'text-green-500';
        default: return 'text-gray-500';
    }
};

const todoPriorityClasses = (priority) => {
    switch(priority) {
        case 'high':
            return 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 hover:bg-red-100 dark:hover:bg-red-900/30';
        case 'medium':
            return 'bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 hover:bg-yellow-100 dark:hover:bg-yellow-900/30';
        case 'low':
            return 'bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 hover:bg-green-100 dark:hover:bg-green-900/30';
        default:
            return 'bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-600';
    }
};

// Lifecycle
onMounted(async () => {
    await nextTick();
    initializeCalendar();
});

watch(() => props.todos, (newTodos) => {
    const todos = newTodos || [];
    pendingTodos.value = todos.filter(todo => todo.status === 'Assigned');
    completedTodos.value = todos.filter(todo => todo.status === 'Completed');
}, { deep: true });

// Keep FullCalendar in sync when server events change (e.g., add/update/delete)
watch(() => props.events, (newEvents) => {
    if (!calendar) return;

    // Deduplicate by id before applying
    const uniqueEvents = (newEvents || []).filter((v, i, a) => a.findIndex(x => x.id === v.id) === i);

    // Exclude holiday events from the displayed event list; holidays are highlighted via day cells
    const eventsToShow = uniqueEvents.filter(e => !(e.extendedProps && e.extendedProps.isHoliday));

    // Remove all existing events and add the fresh, filtered list
    calendar.removeAllEvents();
    if (eventsToShow.length) {
        calendar.addEventSource(eventsToShow);
    }

    // Re-render so dayCellDidMount is re-applied for holidays
    calendar.render();
}, { deep: true });
</script>

<template>
    <Head title="Calendar & Todo" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 flex items-center gap-2">
                    <Calendar :size="24" class="text-blue-600" />
                    Calendar & Todo Management
                </h2>
                <div class="flex gap-2">
                    <button
                        @click="openEventModal(new Date().toISOString().split('T')[0])"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors inline-flex items-center gap-2"
                    >
                        <Plus :size="16" />
                        Add Event
                    </button>
                    <button
                        v-if="canOpenHolidaySetup"
                        @click="router.visit(route('calendar.holidays.setup'))"
                        class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors inline-flex items-center gap-2"
                    >
                        <AlertCircle :size="16" />
                        Holidays Setup
                    </button>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-4 lg:px-4 xl:max-w-[1440px] 2xl:max-w-[1600px]">
                <div class="flex gap-6">
                    <!-- Calendar Section (Left) -->
                    <div class="flex-1 rounded-lg shadow-sm">
                    <div class="flex-1 bg-white dark:bg-gray-800 h-fit rounded-lg shadow-sm p-6">
                        <div ref="calendarEl" class="calendar-container min-h-[500px]"></div>
                    </div>
                    </div>

                    <!-- Sidebar (Right) -->
                    <div class="w-80 space-y-6">
                       
                        <!-- Upcoming Events -->
                        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center gap-2">
                                <Clock :size="20" class="text-blue-600" />
                                Upcoming Events
                            </h3>
                            <div class="space-y-3">
                                <div v-for="event in upcomingEvents" :key="event.id" class="p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                    <h4 class="font-medium text-gray-900 dark:text-gray-100">{{ event.title }}</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ new Date(event.start).toLocaleDateString() }}</p>
                                </div>
                                <div v-if="upcomingEvents.length === 0" class="text-gray-500 dark:text-gray-400 text-sm text-center py-4">
                                    No upcoming events
                                </div>
                            </div>
                        </div>
                        
                        
                        
                        <!-- Todo List -->
                        <div v-if="canAccessTodo" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center gap-2">
                                <CheckCircle :size="20" class="text-green-600" />
                                Todo List
                                <button
                                    @click="openTodoModal()"
                                    class="ml-auto bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-md text-xs font-medium transition-colors"
                                >
                                    Add Todo
                                </button>
                            </h3>
                            
                            <!-- Pending Todos -->
                            <div class="mb-4">
                                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Pending ({{ pendingTodos.length }})</h4>
                                <draggable
                                    v-model="pendingTodos"
                                    item-key="id"
                                    @end="updateTodoOrder"
                                    class="space-y-2"
                                >
                                    <template #item="{element}">
                                        <div :class="['p-3 rounded-lg cursor-move transition-colors', todoPriorityClasses(element.priority)]">
                                            <div class="flex items-center justify-between">
                                                <div class="flex items-center gap-2 flex-1">
                                                    <button
                                                        @click="toggleTodoComplete(element)"
                                                        class="text-gray-400 hover:text-green-600 transition-colors"
                                                    >
                                                        <Circle :size="16" />
                                                    </button>
                                                    <div class="flex-1">
                                                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ element.task }}</p>
                                                        <div class="flex items-center gap-2 mt-1">
                                                            <component
                                                                :is="priorityIcon(element.priority)"
                                                                :size="12"
                                                                :class="priorityColor(element.priority)"
                                                            />
                                                            <span class="text-xs text-gray-500 dark:text-gray-400 capitalize">{{ element.priority }}</span>
                                                            <span v-if="element.due_date" class="text-xs text-gray-500 dark:text-gray-400">
                                                                Due: {{ new Date(element.due_date).toLocaleDateString() }}
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            <div class="flex items-center">
                                                    <button
                                                        @click="moveTodoUp(element)"
                                                        class="text-gray-400 hover:text-gray-700 transition-colors"
                                                        title="Move Up"
                                                    >
                                                        <ChevronUp :size="14" />
                                                    </button>
                                                    <button
                                                        @click="moveTodoDown(element)"
                                                        class="text-gray-400 hover:text-gray-700 transition-colors ml-1"
                                                        title="Move Down"
                                                    >
                                                        <ChevronDown :size="14" />
                                                    </button>
                                                    <button
                                                        @click="openTodoModal(element)"
                                                        class="text-gray-400 hover:text-blue-600 transition-colors ml-2"
                                                    >
                                                        <Pencil :size="14" />
                                                    </button>
                                                    <button
                                                        @click="deleteTodo(element)"
                                                        class="text-gray-400 hover:text-red-600 transition-colors ml-2"
                                                    >
                                                        <Trash2 :size="14" />
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </draggable>
                            </div>

                         
                            <div v-if="pendingTodos.length === 0 && completedTodos.length === 0" class="text-gray-500 text-sm text-center py-4">
                                No todos yet. Create your first todo!
                            </div>
                        </div>

                        <div v-else class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6 text-center text-gray-600 dark:text-gray-300">
                            <p class="text-sm">The Todo system is not available for your account. Please ask an administrator to grant access via the <a class="text-indigo-600 dark:text-indigo-400 underline" href="/roles">Role & Permission Management</a>.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Event Modal -->
        <div v-if="showEventModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md mx-4">
                <h3 class="text-lg font-semibold mb-4 text-gray-900 dark:text-gray-100">
                    {{ selectedEvent ? 'Edit Event' : 'Create Event' }}
                </h3>

                <form @submit.prevent="saveEvent" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Title</label>
                        <input
                            v-model="eventForm.title"
                            type="text"
                            required
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                        <textarea
                            v-model="eventForm.description"
                            rows="3"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        ></textarea>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Start Date</label>
                            <input
                                v-model="eventForm.start"
                                type="datetime-local"
                                required
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">End Date</label>
                            <input
                                v-model="eventForm.end"
                                type="datetime-local"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <label class="flex items-center">
                            <input
                                v-model="eventForm.all_day"
                                type="checkbox"
                                class="rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500"
                            />
                            <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">All Day</span>
                        </label>

                        <label v-if="canAddHolidays" class="flex items-center">
                            <input
                                v-model="eventForm.is_holiday"
                                type="checkbox"
                                class="rounded border-gray-300 dark:border-gray-600 text-red-600 focus:ring-red-500"
                            />
                            <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Mark as Holiday</span>
                        </label>

                        <div class="flex items-center gap-2">
                            <label class="text-sm text-gray-700 dark:text-gray-300">Color:</label>
                            <input
                                v-model="eventForm.color"
                                type="color"
                                class="w-8 h-8 rounded border border-gray-300 dark:border-gray-600"
                            />
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Reminder</label>
                        <input
                            v-model="eventForm.reminder_at"
                            type="datetime-local"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                    </div>

                    <div class="flex justify-between pt-4">
                        <div>
                            <button
                                v-if="selectedEvent"
                                @click="deleteEvent"
                                type="button"
                                class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-md text-sm font-medium transition-colors"
                            >
                                Delete
                            </button>
                        </div>
                        <div class="flex gap-2">
                            <button
                                @click="showEventModal = false"
                                type="button"
                                class="bg-gray-300 dark:bg-gray-600 hover:bg-gray-400 dark:hover:bg-gray-500 text-gray-700 dark:text-gray-200 px-4 py-2 rounded-md text-sm font-medium transition-colors"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm font-medium transition-colors"
                            >
                                {{ selectedEvent ? 'Update' : 'Create' }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Todo Modal -->
        <div v-if="showTodoModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md mx-4">
                <h3 class="text-lg font-semibold mb-4 text-gray-900 dark:text-gray-100">
                    {{ selectedTodo ? 'Edit Todo' : 'Create Todo' }}
                </h3>

                <form @submit.prevent="saveTodo" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Task</label>
                        <input
                            v-model="todoForm.task"
                            type="text"
                            required
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                        <textarea
                            v-model="todoForm.description"
                            rows="3"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        ></textarea>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Priority</label>
                            <select
                                v-model="todoForm.priority"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Due Date</label>
                            <input
                                v-model="todoForm.due_date"
                                type="date"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Category</label>
                        <input
                            v-model="todoForm.category"
                            type="text"
                            placeholder="e.g., Work, Personal, Shopping"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Reminder</label>
                        <input
                            v-model="todoForm.reminder_at"
                            type="datetime-local"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                    </div>

                    <div class="flex justify-end gap-2 pt-4">
                        <button
                            @click="showTodoModal = false; selectedTodo = null"
                            type="button"
                            class="bg-gray-300 dark:bg-gray-600 hover:bg-gray-400 dark:hover:bg-gray-500 text-gray-700 dark:text-gray-200 px-4 py-2 rounded-md text-sm font-medium transition-colors"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md text-sm font-medium transition-colors"
                        >
                            {{ selectedTodo ? 'Update' : 'Create' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.calendar-container {
    font-family: Arial, sans-serif;
}

.fc-event {
    cursor: pointer;
}

.fc-event:hover {
    opacity: 0.8;
}
</style>

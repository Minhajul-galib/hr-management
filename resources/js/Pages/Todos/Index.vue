<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import draggable from 'vuedraggable';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Clock, User, Calendar, AlertTriangle, CheckCircle, ChevronDown, ChevronUp, Search, Filter, Trash2 } from 'lucide-vue-next';

// Import Summernote CSS and JS
import 'summernote/dist/summernote-lite.css';
import 'summernote/dist/summernote-lite.js';

const props = defineProps({
    todos: Array,
    users: Array,
    canAccessTodo: Boolean,
});

const todos = ref(props.todos || []);
const createForm = useForm({
    task: '',
    description: '',
    type: 'self',
    user_id: '',
    status: 'Assigned',
    priority: 'medium',
    due_date: '',
    reminder_at: '',
    category: ''
});

const showCreateModal = ref(false);
const showDetailsModal = ref(false);
const selectedTodo = ref(null);
const currentTime = ref(new Date());
const summernoteInstance = ref(null);

// Completed tasks section
const showCompletedSection = ref(false);
const completedTodos = ref([]);
const searchQuery = ref('');
const filterPriority = ref('');
const filterCategory = ref('');

// Timer for countdown updates
let timeInterval = null;

onMounted(() => {
    timeInterval = setInterval(() => {
        currentTime.value = new Date();
    }, 1000);
    
    // Load completed todos
    loadCompletedTodos();

    // Initialize Summernote
    nextTick(() => {
        initializeSummernote();
    });
});

// Watch for modal opening to reinitialize Summernote
watch(showCreateModal, (newValue) => {
    if (newValue) {
        nextTick(() => {
            initializeSummernote();
        });
    }
});

onUnmounted(() => {
    if (timeInterval) {
        clearInterval(timeInterval);
    }
    // Destroy Summernote instance
    if (summernoteInstance.value) {
        summernoteInstance.value.summernote('destroy');
    }
});

// Load completed todos from server
function loadCompletedTodos() {
    // For now, we'll simulate completed todos. In production, this would be an API call
    // You can replace this with an actual API call to fetch completed todos
    completedTodos.value = todos.value.filter(todo => todo.status === 'Completed');
}

// Initialize Summernote editor
function initializeSummernote() {
    const summernoteElement = document.getElementById('summernote');
    if (summernoteElement && window.$ && window.$.fn.summernote) {
        summernoteInstance.value = window.$(summernoteElement).summernote({
            height: 200,
            placeholder: 'Enter task description...',
            toolbar: [
                ['style', ['style']],
                ['font', ['bold', 'underline', 'clear']],
                ['fontname', ['fontname']],
                ['color', ['color']],
                ['para', ['ul', 'ol', 'paragraph']],
                ['table', ['table']],
                ['insert', ['link', 'picture']],
                ['view', ['fullscreen', 'help']]
            ],
            callbacks: {
                onChange: function(contents, $editable) {
                    createForm.description = contents;
                }
            }
        });
    }
}

// Filtered completed todos based on search and filters
const filteredCompletedTodos = computed(() => {
    let filtered = completedTodos.value;
    
    // Search filter
    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        filtered = filtered.filter(todo => 
            todo.task.toLowerCase().includes(query) ||
            todo.description?.toLowerCase().includes(query) ||
            todo.category?.toLowerCase().includes(query)
        );
    }
    
    // Priority filter
    if (filterPriority.value) {
        filtered = filtered.filter(todo => todo.priority === filterPriority.value);
    }
    
    // Category filter
    if (filterCategory.value) {
        filtered = filtered.filter(todo => todo.category === filterCategory.value);
    }
    
    return filtered;
});

// Get unique categories for filter dropdown
const availableCategories = computed(() => {
    const categories = completedTodos.value
        .map(todo => todo.category)
        .filter(category => category && category.trim() !== '');
    return [...new Set(categories)];
});

// Clear all filters
function clearFilters() {
    searchQuery.value = '';
    filterPriority.value = '';
    filterCategory.value = '';
}

// Delete completed todo
function deleteCompletedTodo(id) {
    if (!confirm('Are you sure you want to permanently delete this completed task?')) return;
    
    router.delete(`/calendar/todos/${id}`, {
        onSuccess: () => {
            completedTodos.value = completedTodos.value.filter(todo => todo.id !== id);
        }
    });
}

// Mark task as completed
function markAsCompleted(id) {
    if (!confirm('Mark this task as completed?')) return;
    
    router.patch(`/calendar/todos/${id}`, { status: 'Completed' }, {
        preserveScroll: true,
        onSuccess: () => {
            // Remove from approved todos and add to completed
            const todoIndex = approvedTodos.value.findIndex(todo => todo.id === id);
            if (todoIndex !== -1) {
                const completedTodo = approvedTodos.value[todoIndex];
                completedTodo.status = 'Completed';
                approvedTodos.value.splice(todoIndex, 1);
                completedTodos.value.unshift(completedTodo);
            }
            updateTodoArrays();
        }
    });
}

// Format date for display
function formatDate(dateString) {
    if (!dateString) return 'No due date';
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

const assignedTodos = ref([]);
const processingTodos = ref([]);
const testingTodos = ref([]);
const submittedTodos = ref([]);
const approvedTodos = ref([]);

// Update the arrays when todos change (excluding completed todos)
function updateTodoArrays() {
    assignedTodos.value = todos.value.filter(t => t.status === 'Assigned');
    processingTodos.value = todos.value.filter(t => t.status === 'Processing');
    testingTodos.value = todos.value.filter(t => t.status === 'Testing');
    submittedTodos.value = todos.value.filter(t => t.status === 'Submitted');
    approvedTodos.value = todos.value.filter(t => t.status === 'Approved');
    completedTodos.value = todos.value.filter(t => t.status === 'Completed');
}

// Initialize Summernote
const summernote = ref(null);

// Initialize Summernote editor
const initSummernote = () => {
    nextTick(() => {
        if (summernote.value) {
            $(summernote.value).summernote({
                height: 200,
                toolbar: [
                    ['style', ['style']],
                    ['font', ['bold', 'underline', 'clear']],
                    ['color', ['color']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['table', ['table']],
                    ['insert', ['link', 'picture', 'video']],
                    ['view', ['fullscreen', 'codeview', 'help']]
                ],
                callbacks: {
                    onChange: function(contents, $editable) {
                        createForm.description = contents;
                    },
                    onImageUpload: function(files) {
                        // Handle image upload here
                        const file = files[0];
                        const formData = new FormData();
                        formData.append('image', file);
                        
                        // Example upload endpoint - replace with your actual endpoint
                        axios.post('/api/upload-image', formData, {
                            headers: {
                                'Content-Type': 'multipart/form-data'
                            }
                        }).then(response => {
                            // Insert the image into the editor
                            const imageUrl = response.data.url;
                            $(summernote.value).summernote('insertImage', imageUrl, function($image) {
                                $image.css('max-width', '100%');
                            });
                        }).catch(error => {
                            console.error('Image upload failed:', error);
                        });
                    }
                }
            });
            
            // Set initial content if editing
            if (createForm.description) {
                $(summernote.value).summernote('code', createForm.description);
            }
        }
    });
};

// Initialize Summernote when component is mounted
onMounted(() => {
    initSummernote();
});

// Clean up Summernote when component is unmounted
onUnmounted(() => {
    if (summernote.value) {
        $(summernote.value).summernote('destroy');
    }
});

// Initial update
updateTodoArrays();

// Enhanced countdown timer function for details modal
function getDetailedTimeRemaining(dueDate) {
    if (!dueDate) return null;
    
    const due = new Date(dueDate);
    const now = currentTime.value;
    const diff = due.getTime() - now.getTime();
    
    if (diff <= 0) {
        return { 
            expired: true, 
            text: 'Overdue', 
            class: 'text-red-600 font-semibold',
            days: 0,
            hours: 0,
            minutes: 0,
            seconds: 0
        };
    }
    
    const days = Math.floor(diff / (1000 * 60 * 60 * 24));
    const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
    const seconds = Math.floor((diff % (1000 * 60)) / 1000);
    
    let timeClass = 'text-gray-600';
    
    if (days <= 0 && hours <= 2) {
        timeClass = 'text-red-600 font-semibold';
    } else if (days <= 1) {
        timeClass = 'text-orange-600 font-medium';
    } else {
        timeClass = 'text-gray-600';
    }
    
    return { 
        expired: false, 
        class: timeClass,
        days,
        hours,
        minutes,
        seconds
    };
}

// Countdown timer function for kanban cards
function getTimeRemaining(dueDate) {
    if (!dueDate) return null;
    
    const due = new Date(dueDate);
    const now = currentTime.value;
    const diff = due.getTime() - now.getTime();
    
    if (diff <= 0) {
        return { expired: true, text: 'Overdue', class: 'text-red-600 font-semibold' };
    }
    
    const days = Math.floor(diff / (1000 * 60 * 60 * 24));
    const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
    
    let timeText = '';
    let timeClass = 'text-gray-600';
    
    if (days > 0) {
        timeText = `${days}d ${hours}h`;
        timeClass = days <= 1 ? 'text-orange-600 font-medium' : 'text-gray-600';
    } else if (hours > 0) {
        timeText = `${hours}h ${minutes}m`;
        timeClass = hours <= 2 ? 'text-red-600 font-medium' : 'text-orange-600 font-medium';
    } else {
        timeText = `${minutes}m`;
        timeClass = 'text-red-600 font-semibold animate-pulse';
    }
    
    return { expired: false, text: timeText, class: timeClass };
}

// Get allocated duration between created and due date
function getAllocatedDuration(dueDate, createdDate) {
    if (!dueDate || !createdDate) return null;
    
    const due = new Date(dueDate);
    const created = new Date(createdDate);
    const diff = due.getTime() - created.getTime();
    
    if (diff <= 0) return { text: 'Invalid duration', class: 'text-red-600' };
    
    const days = Math.floor(diff / (1000 * 60 * 60 * 24));
    const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
    
    let text = '';
    if (days > 0) {
        text = `${days} day${days > 1 ? 's' : ''}`;
        if (hours > 0) text += `, ${hours} hour${hours > 1 ? 's' : ''}`;
    } else if (hours > 0) {
        text = `${hours} hour${hours > 1 ? 's' : ''}`;
        if (minutes > 0) text += `, ${minutes} minute${minutes > 1 ? 's' : ''}`;
    } else {
        text = `${minutes} minute${minutes > 1 ? 's' : ''}`;
    }
    
    return { 
        text, 
        class: 'text-blue-600 font-medium',
        totalDays: days,
        totalHours: hours,
        totalMinutes: minutes
    };
}

function getAllocatedTime(dueDate, createdAt) {
    if (!dueDate || !createdAt) return null;
    
    const due = new Date(dueDate);
    const created = new Date(createdAt);
    const diff = due.getTime() - created.getTime();
    
    if (diff <= 0) {
        return { text: 'Overdue', class: 'text-red-600 font-semibold' };
    }
    
    const days = Math.floor(diff / (1000 * 60 * 60 * 24));
    const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
    const seconds = Math.floor((diff % (1000 * 60)) / 1000);
    
    let timeText = '';
    if (days > 0) {
        timeText = `${days}d ${hours}h`;
    } else if (hours > 0) {
        timeText = `${hours}h ${minutes}m`;
    } else if (minutes > 0) {
        timeText = `${minutes}m ${seconds}s`;
    } else {
        timeText = `${seconds}s`;
    }
    
    return { text: timeText, class: 'text-blue-600' };
}

function getPriorityColor(priority) {
    switch (priority) {
        case 'high': return 'bg-red-100 text-red-800 border-red-200';
        case 'medium': return 'bg-yellow-100 text-yellow-800 border-yellow-200';
        case 'low': return 'bg-green-100 text-green-800 border-green-200';
        default: return 'bg-gray-100 text-gray-800 border-gray-200';
    }
}

function createTodo() {
    // Get content from Summernote
    if (summernoteInstance.value) {
        createForm.description = summernoteInstance.value.summernote('code');
    }

    createForm.post(route('calendar.todos.store'), {
        onSuccess: (page) => {
            todos.value = page.props.todos || [];
            updateTodoArrays();
            createForm.reset();
            showCreateModal.value = false;
            // Reset Summernote
            if (summernoteInstance.value) {
                summernoteInstance.value.summernote('code', '');
            }
        },
        onError: (errors) => {
            console.error('Todo creation failed:', JSON.stringify(errors, null, 2));
        }
    });
}

function onStatusChange(evt, newStatus) {
    if (evt.added) {
        const todo = evt.added.element;
        todo.status = newStatus;
        
        // If moving to completed, add to completed todos list
        if (newStatus === 'Completed') {
            completedTodos.value.push(todo);
        }
        
        router.patch(`/calendar/todos/${todo.id}`, { status: newStatus }, {
            preserveScroll: true,
            onSuccess: () => {
                // Refresh completed todos if status changed to completed
                if (newStatus === 'Completed') {
                    loadCompletedTodos();
                }
            }
        });
    }
}

function deleteTodo(id) {
    if (!confirm('Are you sure you want to delete this todo?')) return;
    router.delete(`/calendar/todos/${id}`, {
        onSuccess: (page) => {
            todos.value = page.props.todos || [];
            updateTodoArrays();
        }
    });
}

function markCompleted(id) {
    router.patch(`/calendar/todos/${id}`, { status: 'Completed' }, {
        preserveScroll: true,
        onSuccess: (page) => {
            todos.value = page.props.todos || [];
            updateTodoArrays();
        }
    });
}

function openDetails(todo) {
    selectedTodo.value = todo;
    showDetailsModal.value = true;
}
// Format time since a given date
const getTimeSince = (dateString) => {
    if (!dateString) return 'N/A';
    
    const date = new Date(dateString);
    const now = new Date();
    const seconds = Math.floor((now - date) / 1000);
    
    const intervals = {
        year: 31536000,
        month: 2592000,
        week: 604800,
        day: 86400,
        hour: 3600,
        minute: 60,
        second: 1
    };
    
    for (const [unit, secondsInUnit] of Object.entries(intervals)) {
        const interval = Math.floor(seconds / secondsInUnit);
        if (interval >= 1) {
            return interval === 1 ? `1 ${unit} ago` : `${interval} ${unit}s ago`;
        }
    }
    
    return 'just now';
};

// Status configurations
const statusConfig = {
    'Assigned': {
        title: 'Assigned',
        icon: User,
        bgColor: 'bg-slate-50',
        borderColor: 'border-slate-200',
        headerColor: 'bg-slate-100',
        textColor: 'text-slate-700'
    },
    'Processing': {
        title: 'Processing',
        icon: Clock,
        bgColor: 'bg-blue-50',
        borderColor: 'border-blue-200',
        headerColor: 'bg-blue-100',
        textColor: 'text-blue-700'
    },
    'Testing': {
        title: 'Testing',
        icon: AlertTriangle,
        bgColor: 'bg-amber-50',
        borderColor: 'border-amber-200',
        headerColor: 'bg-amber-100',
        textColor: 'text-amber-700'
    },
    'Submitted': {
        title: 'Submitted',
        icon: CheckCircle,
        bgColor: 'bg-purple-50',
        borderColor: 'border-purple-200',
        headerColor: 'bg-purple-100',
        textColor: 'text-purple-700'
    },
    'Approved': {
        title: 'Approved',
        icon: CheckCircle,
        bgColor: 'bg-green-50',
        borderColor: 'border-green-200',
        headerColor: 'bg-green-100',
        textColor: 'text-green-700'
    }
};
</script>

<template>
    <Head title="Task Board" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="font-semibold text-xl text-gray-800 leading-tight">Task Board</h2>
                    <p class="text-sm text-gray-600 mt-1">Manage and track your tasks through different stages</p>
                </div>
                <div class="flex items-center gap-3">
                    <PrimaryButton v-if="canAccessTodo" @click="showCreateModal = true">
                        <span class="flex items-center">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                            </svg>
                            Create Task
                        </span>
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl sm:px-4 lg:px-4 xl:max-w-[1440px] 2xl:max-w-[1600px]">
                <!-- Task Board -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden dark:bg-gray-800 dark:border-gray-700">
                    <div class="p-6">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-6">
                            <!-- Assigned Column -->
                            <div class="flex flex-col">
                                <div class="flex items-center justify-between p-4 bg-slate-100 rounded-t-lg border-b border-slate-200 dark:bg-gray-900/50 dark:border-gray-700">
                                    <div class="flex items-center">
                                        <User class="w-5 h-5 text-slate-600 mr-2 dark:text-slate-300" />
                                        <h3 class="font-semibold text-slate-700 dark:text-gray-100">Assigned</h3>
                                    </div>
                                    <span class="bg-slate-200 text-slate-700 text-xs font-medium px-2 py-1 rounded-full dark:bg-gray-700 dark:text-gray-100">
                                        {{ assignedTodos.length }}
                                    </span>
                                </div>
                                <div class="bg-slate-50 border-l border-r border-b border-slate-200 rounded-b-lg p-4 min-h-[500px] dark:bg-gray-900/30 dark:border-gray-700">
                                    <draggable 
                                        v-model="assignedTodos" 
                                        group="todos" 
                                        item-key="id" 
                                        @change="(evt) => onStatusChange(evt, 'Assigned')" 
                                        class="space-y-3 min-h-[400px]"
                                    >
                                        <template #item="{element}">
                                            <div class="bg-white border border-gray-200 rounded-lg p-2 shadow-sm hover:shadow-md transition-shadow duration-200 cursor-move dark:bg-gray-800 dark:border-gray-700">
                                                <div class="flex items-start justify-between mb-3">
                                                    <h4 class="font-medium text-gray-900 text-sm leading-tight dark:text-gray-100">{{ element.task }}</h4>
                                                    <span :class="getPriorityColor(element.priority)" class="text-xs px-2 py-1 rounded-md  border font-medium">
                                                        {{ element.priority }}
                                                    </span>
                                                </div>
                                                
                                                <button @click="openDetails(element)" class="w-full text-left px-2 py-1 text-xs bg-blue-50 text-blue-600 hover:bg-blue-100 rounded mb-3 transition-colors duration-200 font-medium">
                                                    View Details →
                                                </button>
                                                
                                                <div class="space-y-2">
                                                   
                                                    <div v-if="element.due_date && element.created_at" class="flex items-center text-xs">
                                                        <Calendar class="w-3 h-3 mr-1" />
                                                        <span :class="getAllocatedTime(element.due_date, element.created_at)?.class">
                                                            Duration: {{ getAllocatedTime(element.due_date, element.created_at)?.text }}
                                                        </span>
                                                    </div>
                                                  
                                                    <div v-if="element.assigned_by" class="flex items-center text-xs text-gray-500 dark:text-gray-400">
                                                        <User class="w-3 h-3 mr-1" />
                                                        <span>By: {{ element.assigned_by.name }}</span>
                                                    </div>
                                                   
                                                </div>
                                                
                                                <div class="flex justify-between items-center mt-1 pt-1 border-t border-gray-100 dark:border-gray-700">
                                                    <span v-if="element.category" class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded dark:text-gray-300 dark:bg-gray-700">
                                                        {{ element.category }}
                                                    </span>
                                                    <div class="flex items-center gap-3">
                                                        <button @click="markCompleted(element.id)" class="text-emerald-600 hover:text-emerald-800 text-xs font-medium">
                                                            Complete
                                                        </button>
                                                        <button @click="deleteTodo(element.id)" class="text-red-500 hover:text-red-700 text-xs font-medium">
                                                            Delete
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </draggable>
                                    <div v-if="assignedTodos.length === 0" class="flex flex-col items-center justify-center py-12 text-gray-400 dark:text-gray-500">
                                        <User class="w-8 h-8 mb-2" />
                                        <p class="text-sm">No assigned tasks</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Processing Column -->
                            <div class="flex flex-col">
                                <div class="flex items-center justify-between p-4 bg-blue-100 rounded-t-lg border-b border-blue-200 dark:bg-blue-900/25 dark:border-blue-800/40">
                                    <div class="flex items-center">
                                        <Clock class="w-5 h-5 text-blue-600 mr-2 dark:text-blue-300" />
                                        <h3 class="font-semibold text-blue-700 dark:text-blue-100">Processing</h3>
                                    </div>
                                    <span class="bg-blue-200 text-blue-700 text-xs font-medium px-2 py-1 rounded-full dark:bg-blue-800/30 dark:text-blue-100">
                                        {{ processingTodos.length }}
                                    </span>
                                </div>
                                <div class="bg-blue-50 border-l border-r border-b border-blue-200 rounded-b-lg p-4 min-h-[500px] dark:bg-blue-900/10 dark:border-blue-800/40">
                                    <draggable 
                                        v-model="processingTodos" 
                                        group="todos" 
                                        item-key="id" 
                                        @change="(evt) => onStatusChange(evt, 'Processing')" 
                                        class="space-y-3 min-h-[400px]"
                                    >
                                        <template #item="{element}">
                                            <div class="bg-white border border-gray-200 rounded-lg p-2 shadow-sm hover:shadow-md transition-shadow duration-200 cursor-move dark:bg-gray-800 dark:border-gray-700">
                                                <div class="flex items-start justify-between mb-3">
                                                    <h4 class="font-medium text-gray-900 text-sm leading-tight dark:text-gray-100">{{ element.task }}</h4>
                                                    <span :class="getPriorityColor(element.priority)" class="text-xs px-2 py-1 rounded-mdborder font-medium">
                                                        {{ element.priority }}
                                                    </span>
                                                </div>
                                                
                                                <button @click="openDetails(element)" class="w-full text-left px-2 py-1 text-xs bg-blue-50 text-blue-600 hover:bg-blue-100 rounded mb-3 transition-colors duration-200 font-medium">
                                                    View Details →
                                                </button>
                                                
                                                <div class="space-y-2">
                                                   
                                                    <div v-if="element.due_date && element.created_at" class="flex items-center text-xs">
                                                        <Calendar class="w-3 h-3 mr-1" />
                                                        <span :class="getAllocatedTime(element.due_date, element.created_at)?.class">
                                                            Duration: {{ getAllocatedTime(element.due_date, element.created_at)?.text }}
                                                        </span>
                                                    </div>
                                                   
                                                    
                                                    <div v-if="element.assigned_by" class="flex items-center text-xs text-gray-500 dark:text-gray-400">
                                                        <User class="w-3 h-3 mr-1" />
                                                        <span>By: {{ element.assigned_by.name }}</span>
                                                    </div>
                                                    
                                                    <div v-if="element.user && element.user.id !== element.assigned_by?.id" class="flex items-center text-xs text-gray-500 dark:text-gray-400">
                                                        <User class="w-3 h-3 mr-1" />
                                                        <span>To: {{ element.user.name }}</span>
                                                    </div>
                                                </div>
                                                
                                                <div class="flex justify-between items-center mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
                                                    <span v-if="element.category" class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded dark:text-gray-300 dark:bg-gray-700">
                                                        {{ element.category }}
                                                    </span>
                                                    <div class="flex items-center gap-3">
                                                        <button @click="markCompleted(element.id)" class="text-emerald-600 hover:text-emerald-800 text-xs font-medium">
                                                            Complete
                                                        </button>
                                                        <button @click="deleteTodo(element.id)" class="text-red-500 hover:text-red-700 text-xs font-medium">
                                                            Delete
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </draggable>
                                    <div v-if="processingTodos.length === 0" class="flex flex-col items-center justify-center py-12 text-gray-400 dark:text-gray-500">
                                        <Clock class="w-8 h-8 mb-2" />
                                        <p class="text-sm">No processing tasks</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Testing Column -->
                            <div class="flex flex-col">
                                <div class="flex items-center justify-between p-4 bg-amber-100 rounded-t-lg border-b border-amber-200 dark:bg-amber-900/20 dark:border-amber-800/40">
                                    <div class="flex items-center">
                                        <AlertTriangle class="w-5 h-5 text-amber-600 mr-2 dark:text-amber-300" />
                                        <h3 class="font-semibold text-amber-700 dark:text-amber-100">Testing</h3>
                                    </div>
                                    <span class="bg-amber-200 text-amber-700 text-xs font-medium px-2 py-1 rounded-full dark:bg-amber-800/30 dark:text-amber-100">
                                        {{ testingTodos.length }}
                                    </span>
                                </div>
                                <div class="bg-amber-50 border-l border-r border-b border-amber-200 rounded-b-lg p-4 min-h-[500px] dark:bg-amber-900/10 dark:border-amber-800/40">
                                    <draggable 
                                        v-model="testingTodos" 
                                        group="todos" 
                                        item-key="id" 
                                        @change="(evt) => onStatusChange(evt, 'Testing')" 
                                        class="space-y-3 min-h-[400px]"
                                    >
                                        <template #item="{element}">
                                            <div class="bg-white border border-gray-200 rounded-lg p-2 shadow-sm hover:shadow-md transition-shadow duration-200 cursor-move dark:bg-gray-800 dark:border-gray-700">
                                                <div class="flex items-start justify-between mb-3">
                                                    <h4 class="font-medium text-gray-900 text-sm leading-tight dark:text-gray-100">{{ element.task }}</h4>
                                                    <span :class="getPriorityColor(element.priority)" class="text-xs px-2 py-1 rounded-mdborder font-medium">
                                                        {{ element.priority }}
                                                    </span>
                                                </div>
                                                
                                                <button @click="openDetails(element)" class="w-full text-left px-2 py-1 text-xs bg-blue-50 text-blue-600 hover:bg-blue-100 rounded mb-3 transition-colors duration-200 font-medium">
                                                    View Details →
                                                </button>
                                                
                                                <div class="space-y-2">
                                                    <div v-if="element.due_date && element.created_at" class="flex items-center text-xs">
                                                        <Calendar class="w-3 h-3 mr-1" />
                                                        <span :class="getAllocatedTime(element.due_date, element.created_at)?.class">
                                                            Duration: {{ getAllocatedTime(element.due_date, element.created_at)?.text }}
                                                        </span>
                                                    </div>
                                                                                                      
                                                    <div v-if="element.assigned_by" class="flex items-center text-xs text-gray-500 dark:text-gray-400">
                                                        <User class="w-3 h-3 mr-1" />
                                                        <span>By: {{ element.assigned_by.name }}</span>
                                                    </div>
                                                    
                                                    <div v-if="element.user && element.user.id !== element.assigned_by?.id" class="flex items-center text-xs text-gray-500 dark:text-gray-400">
                                                        <User class="w-3 h-3 mr-1" />
                                                        <span>To: {{ element.user.name }}</span>
                                                    </div>
                                                </div>
                                                
                                                <div class="flex justify-between items-center mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
                                                    <span v-if="element.category" class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded dark:text-gray-300 dark:bg-gray-700">
                                                        {{ element.category }}
                                                    </span>
                                                    <div class="flex items-center gap-3">
                                                        <button @click="markCompleted(element.id)" class="text-emerald-600 hover:text-emerald-800 text-xs font-medium">
                                                            Complete
                                                        </button>
                                                        <button @click="deleteTodo(element.id)" class="text-red-500 hover:text-red-700 text-xs font-medium">
                                                            Delete
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </draggable>
                                    <div v-if="testingTodos.length === 0" class="flex flex-col items-center justify-center py-12 text-gray-400 dark:text-gray-500">
                                        <AlertTriangle class="w-8 h-8 mb-2" />
                                        <p class="text-sm">No testing tasks</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Submitted Column -->
                            <div class="flex flex-col">
                                <div class="flex items-center justify-between p-4 bg-purple-100 rounded-t-lg border-b border-purple-200 dark:bg-purple-900/20 dark:border-purple-800/40">
                                    <div class="flex items-center">
                                        <CheckCircle class="w-5 h-5 text-purple-600 mr-2 dark:text-purple-300" />
                                        <h3 class="font-semibold text-purple-700 dark:text-purple-100">Submitted</h3>
                                    </div>
                                    <span class="bg-purple-200 text-purple-700 text-xs font-medium px-2 py-1 rounded-full dark:bg-purple-800/30 dark:text-purple-100">
                                        {{ submittedTodos.length }}
                                    </span>
                                </div>
                                <div class="bg-purple-50 border-l border-r border-b border-purple-200 rounded-b-lg p-4 min-h-[500px] dark:bg-purple-900/10 dark:border-purple-800/40">
                                    <draggable 
                                        v-model="submittedTodos" 
                                        group="todos" 
                                        item-key="id" 
                                        @change="(evt) => onStatusChange(evt, 'Submitted')" 
                                        class="space-y-3 min-h-[400px]"
                                    >
                                        <template #item="{element}">
                                            <div class="bg-white border border-gray-200 rounded-lg p-2 shadow-sm hover:shadow-md transition-shadow duration-200 cursor-move dark:bg-gray-800 dark:border-gray-700">
                                                <div class="flex items-start justify-between mb-3">
                                                    <h4 class="font-medium text-gray-900 text-sm leading-tight dark:text-gray-100">{{ element.task }}</h4>
                                                    <span :class="getPriorityColor(element.priority)" class="text-xs px-2 py-1 rounded-mdborder font-medium">
                                                        {{ element.priority }}
                                                    </span>
                                                </div>
                                                
                                                <button @click="openDetails(element)" class="w-full text-left px-2 py-1 text-xs bg-blue-50 text-blue-600 hover:bg-blue-100 rounded mb-3 transition-colors duration-200 font-medium">
                                                    View Details →
                                                </button>
                                                
                                                <div class="space-y-2">
                                                   
                                                    <div v-if="element.due_date && element.created_at" class="flex items-center text-xs">
                                                        <Calendar class="w-3 h-3 mr-1" />
                                                        <span :class="getAllocatedTime(element.due_date, element.created_at)?.class">
                                                            Duration: {{ getAllocatedTime(element.due_date, element.created_at)?.text }}
                                                        </span>
                                                    </div>
                                                    
                                                    
                                                    <div v-if="element.assigned_by" class="flex items-center text-xs text-gray-500 dark:text-gray-400">
                                                        <User class="w-3 h-3 mr-1" />
                                                        <span>By: {{ element.assigned_by.name }}</span>
                                                    </div>
                                                    
                                                    <div v-if="element.user && element.user.id !== element.assigned_by?.id" class="flex items-center text-xs text-gray-500 dark:text-gray-400">
                                                        <User class="w-3 h-3 mr-1" />
                                                        <span>To: {{ element.user.name }}</span>
                                                    </div>
                                                </div>
                                                
                                                <div class="flex justify-between items-center mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
                                                    <span v-if="element.category" class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded dark:text-gray-300 dark:bg-gray-700">
                                                        {{ element.category }}
                                                    </span>
                                                    <div class="flex items-center gap-3">
                                                        <button @click="markCompleted(element.id)" class="text-emerald-600 hover:text-emerald-800 text-xs font-medium">
                                                            Complete
                                                        </button>
                                                        <button @click="deleteTodo(element.id)" class="text-red-500 hover:text-red-700 text-xs font-medium">
                                                            Delete
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </draggable>
                                    <div v-if="submittedTodos.length === 0" class="flex flex-col items-center justify-center py-12 text-gray-400 dark:text-gray-500">
                                        <CheckCircle class="w-8 h-8 mb-2" />
                                        <p class="text-sm">No submitted tasks</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Approved Column -->
                            <div class="flex flex-col">
                                <div class="flex items-center justify-between p-4 bg-green-100 rounded-t-lg border-b border-green-200 dark:bg-green-900/20 dark:border-green-800/40">
                                    <div class="flex items-center">
                                        <CheckCircle class="w-5 h-5 text-green-600 mr-2 dark:text-green-300" />
                                        <h3 class="font-semibold text-green-700 dark:text-green-100">Approved</h3>
                                    </div>
                                    <span class="bg-green-200 text-green-700 text-xs font-medium px-2 py-1 rounded-full dark:bg-green-800/30 dark:text-green-100">
                                        {{ approvedTodos.length }}
                                    </span>
                                </div>
                                <div class="bg-green-50 border-l border-r border-b border-green-200 rounded-b-lg p-4 min-h-[500px] dark:bg-green-900/10 dark:border-green-800/40">
                                    <draggable 
                                        v-model="approvedTodos" 
                                        group="todos" 
                                        item-key="id" 
                                        @change="(evt) => onStatusChange(evt, 'Approved')" 
                                        class="space-y-3 min-h-[400px]"
                                    >
                                        <template #item="{element}">
                                            <div class="bg-white border border-gray-200 rounded-lg p-2 shadow-sm hover:shadow-md transition-shadow duration-200 cursor-move dark:bg-gray-800 dark:border-gray-700">
                                                <div class="flex items-start justify-between mb-3">
                                                    <h4 class="font-medium text-gray-900 text-sm leading-tight dark:text-gray-100">{{ element.task }}</h4>
                                                    <span :class="getPriorityColor(element.priority)" class="text-xs px-2 py-1 rounded-mdborder font-medium">
                                                        {{ element.priority }}
                                                    </span>
                                                </div>
                                                
                                                <button @click="openDetails(element)" class="w-full text-left px-2 py-1 text-xs bg-blue-50 text-blue-600 hover:bg-blue-100 rounded mb-3 transition-colors duration-200 font-medium">
                                                    View Details →
                                                </button>
                                                
                                                <div class="space-y-2">
                                                   
                                                    <div v-if="element.due_date && element.created_at" class="flex items-center text-xs">
                                                        <Calendar class="w-3 h-3 mr-1" />
                                                        <span :class="getAllocatedTime(element.due_date, element.created_at)?.class">
                                                            Duration: {{ getAllocatedTime(element.due_date, element.created_at)?.text }}
                                                        </span>
                                                    </div>
                                                   
                                                    <div v-if="element.assigned_by" class="flex items-center text-xs text-gray-500 dark:text-gray-400">
                                                        <User class="w-3 h-3 mr-1" />
                                                        <span>By: {{ element.assigned_by.name }}</span>
                                                    </div>
                                                    
                                                    <div v-if="element.user && element.user.id !== element.assigned_by?.id" class="flex items-center text-xs text-gray-500 dark:text-gray-400">
                                                        <User class="w-3 h-3 mr-1" />
                                                        <span>To: {{ element.user.name }}</span>
                                                    </div>
                                                </div>
                                                
                                                <div class="flex justify-between items-center mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
                                                    <span v-if="element.category" class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded dark:text-gray-300 dark:bg-gray-700">
                                                        {{ element.category }}
                                                    </span>
                                                    <div class="flex gap-2">
                                                        <button 
                                                            @click="markAsCompleted(element.id)" 
                                                            class="text-green-600 hover:text-green-800 text-xs font-medium bg-green-50 hover:bg-green-100 px-2 py-1 rounded transition-colors duration-200"
                                                        >
                                                            ✓ Complete
                                                        </button>
                                                        <button @click="deleteTodo(element.id)" class="text-red-500 hover:text-red-700 text-xs font-medium">
                                                            Delete
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </draggable>
                                    <div v-if="approvedTodos.length === 0" class="flex flex-col items-center justify-center py-12 text-gray-400 dark:text-gray-500">
                                        <CheckCircle class="w-8 h-8 mb-2" />
                                        <p class="text-sm">No approved tasks</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Completed Tasks Section -->
                <div class="mt-8 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden dark:bg-gray-800 dark:border-gray-700">
                    <!-- Section Header -->
                    <div
                        class="p-4 bg-gray-50 border-b border-gray-200 cursor-pointer hover:bg-gray-100 transition-colors duration-200 dark:bg-gray-900 dark:border-gray-700 dark:hover:bg-gray-700"
                        @click="showCompletedSection = !showCompletedSection"
                    >
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <CheckCircle class="w-5 h-5 text-gray-600 mr-3 dark:text-gray-400" />
                                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Completed Tasks</h3>
                                <span class="ml-3 bg-gray-200 text-gray-700 text-sm font-medium px-3 py-1 rounded-full dark:bg-gray-700 dark:text-gray-300">
                                    {{ completedTodos.length }}
                                </span>
                            </div>
                            <div class="flex items-center">
                                <span class="text-sm text-gray-500 mr-2 dark:text-gray-400">
                                    {{ showCompletedSection ? 'Hide' : 'Show' }} completed tasks
                                </span>
                                <ChevronDown v-if="!showCompletedSection" class="w-5 h-5 text-gray-500 dark:text-gray-400" />
                                <ChevronUp v-else class="w-5 h-5 text-gray-500 dark:text-gray-400" />
                            </div>
                        </div>
                    </div>

                    <!-- Collapsible Content -->
                    <div v-show="showCompletedSection" class="transition-all duration-300">
                        <!-- Search and Filter Bar -->
                        <div class="p-4 bg-gray-50 border-b border-gray-200 dark:bg-gray-900 dark:border-gray-700">
                            <div class="flex flex-col sm:flex-row gap-4">
                                <!-- Search Input -->
                                <div class="flex-1">
                                    <div class="relative">
                                        <Search class="absolute left-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400 dark:text-gray-500" />
                                        <input
                                            v-model="searchQuery"
                                            type="text"
                                            placeholder="Search completed tasks..."
                                            class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100"
                                        />
                                    </div>
                                </div>

                                <!-- Priority Filter -->
                                <div class="sm:w-40">
                                    <select
                                        v-model="filterPriority"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100"
                                    >
                                        <option value="">All Priorities</option>
                                        <option value="high">High</option>
                                        <option value="medium">Medium</option>
                                        <option value="low">Low</option>
                                    </select>
                                </div>

                                <!-- Category Filter -->
                                <div class="sm:w-40">
                                    <select
                                        v-model="filterCategory"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100"
                                    >
                                        <option value="">All Categories</option>
                                        <option v-for="category in availableCategories" :key="category" :value="category">
                                            {{ category }}
                                        </option>
                                    </select>
                                </div>

                                <!-- Clear Filters Button -->
                                <button
                                    @click="clearFilters"
                                    class="px-4 py-2 text-gray-600 hover:text-gray-800 border border-gray-300 rounded-lg hover:bg-gray-100 transition-colors duration-200 dark:text-gray-300 dark:hover:text-gray-100 dark:border-gray-600 dark:hover:bg-gray-700"
                                >
                                    <Filter class="w-4 h-4 inline mr-2" />
                                    Clear
                                </button>
                            </div>
                        </div>

                        <!-- Completed Tasks Table -->
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="bg-gray-100 border-b border-gray-200 dark:bg-gray-900 dark:border-gray-700">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Task</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Priority</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Category</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Assigned By</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Due Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Completed</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200 dark:bg-gray-800 dark:divide-gray-700">
                                    <tr v-if="filteredCompletedTodos.length === 0">
                                        <td colspan="7" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                                            <CheckCircle class="w-12 h-12 mx-auto mb-4 text-gray-300 dark:text-gray-600" />
                                            <p class="text-lg font-medium">No completed tasks found</p>
                                            <p class="text-sm">{{ searchQuery || filterPriority || filterCategory ? 'Try adjusting your filters' : 'Complete some tasks to see them here' }}</p>
                                        </td>
                                    </tr>
                                    <tr
                                        v-for="todo in filteredCompletedTodos"
                                        :key="todo.id"
                                        class="hover:bg-gray-50 transition-colors duration-150 dark:hover:bg-gray-700/50"
                                    >
                                        <!-- Task Column -->
                                        <td class="px-6 py-4">
                                            <div class="flex flex-col">
                                                <div class="font-medium text-gray-900 line-through dark:text-gray-100">{{ todo.task }}</div>
                                                <div v-if="todo.description" class="text-sm text-gray-500 mt-1 line-clamp-2 dark:text-gray-400">
                                                    {{ todo.description }}
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Priority Column -->
                                        <td class="px-6 py-4">
                                            <span :class="getPriorityColor(todo.priority)" class="inline-flex px-2 py-1 text-xs font-semibold rounded-mdborder">
                                                {{ todo.priority }}
                                            </span>
                                        </td>

                                        <!-- Category Column -->
                                        <td class="px-6 py-4">
                                            <span v-if="todo.category" class="text-sm text-gray-600 bg-gray-100 px-2 py-1 rounded dark:text-gray-300 dark:bg-gray-700">
                                                {{ todo.category }}
                                            </span>
                                            <span v-else class="text-sm text-gray-400 dark:text-gray-500">-</span>
                                        </td>

                                        <!-- Assigned By Column -->
                                        <td class="px-6 py-4">
                                            <div v-if="todo.assigned_by" class="flex items-center">
                                                <User class="w-4 h-4 mr-2 text-gray-400 dark:text-gray-500" />
                                                <span class="text-sm text-gray-600 dark:text-gray-300">{{ todo.assigned_by.name }}</span>
                                            </div>
                                            <span v-else class="text-sm text-gray-400 dark:text-gray-500">Self-assigned</span>
                                        </td>

                                        <!-- Due Date Column -->
                                        <td class="px-6 py-4">
                                            <div class="flex items-center text-sm text-gray-600 dark:text-gray-300">
                                                <Calendar class="w-4 h-4 mr-2 text-gray-400 dark:text-gray-500" />
                                                {{ formatDate(todo.due_date) }}
                                            </div>
                                        </td>

                                        <!-- Completed Date Column -->
                                        <td class="px-6 py-4">
                                            <div class="flex items-center text-sm text-gray-600 dark:text-gray-300">
                                                <CheckCircle class="w-4 h-4 mr-2 text-green-500" />
                                                {{ formatDate(todo.updated_at) }}
                                            </div>
                                        </td>

                                        <!-- Actions Column -->
                                        <td class="px-6 py-4">
                                            <button
                                                @click="deleteCompletedTodo(todo.id)"
                                                class="text-red-600 hover:text-red-800 transition-colors duration-150 dark:text-red-400 dark:hover:text-red-300"
                                                title="Delete permanently"
                                            >
                                                <Trash2 class="w-4 h-4" />
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Results Summary -->
                        <div v-if="filteredCompletedTodos.length > 0" class="px-6 py-3 bg-gray-50 border-t border-gray-200 dark:bg-gray-900 dark:border-gray-700">
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                Showing {{ filteredCompletedTodos.length }} of {{ completedTodos.length }} completed tasks
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Task Details Modal -->
        <Modal :show="showDetailsModal" @close="showDetailsModal = false">
            <div v-if="selectedTodo" class="p-6 dark:bg-gray-800">
                <div class="flex items-start justify-between mb-6">
                    <div class="bg-sky-400">
                        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ selectedTodo.task }}</h2>
                        <span v-if="selectedTodo.category" class="text-xs text-gray-500 dark:text-gray-400 bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded mt-2 inline-block">
                            {{ selectedTodo.category }}
                        </span>
                    </div>

                </div>

                <div class="grid grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Status</label>
                        <p class="text-sm text-gray-900 dark:text-gray-100 mt-1">{{ selectedTodo.status }}</p>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Priority</label>
                        <p class="text-sm text-gray-900 dark:text-gray-100 mt-1">{{ selectedTodo.priority }}</p>
                    </div>
                </div>

                <div v-if="selectedTodo.due_date && selectedTodo.created_at" class="mb-6">
                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase block mb-3">Allocated Duration</label>
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-4 rounded-xl border border-blue-200">
                        <div class="flex items-center justify-center">
                            <div class="text-center">
                                <div class="text-2xl font-bold text-blue-600 mb-1">
                                    {{ getAllocatedDuration(selectedTodo.due_date, selectedTodo.created_at)?.text || 'N/A' }}
                                </div>
                              <div class="text-xs text-blue-500 font-medium">Total Time Allocated</div>
                                <div class="text-xl mt-2 font-bold text-blue-600 mb-1">
                                    {{ selectedTodo.due_date ? new Date(selectedTodo.due_date).toLocaleString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true }).replace(',', '') : 'Not Given' }}

                                </div>
                                <div class="text-xs text-blue-500 font-medium">Deadline</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="selectedTodo.due_date" class="mb-6">
                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase block mb-3">Time Remaining</label>
                    <div v-if="getDetailedTimeRemaining(selectedTodo.due_date)?.expired" 
                         class="bg-gradient-to-r from-red-50 to-pink-50 p-6 rounded-xl border border-red-200">
                        <div class="text-center">
                            <div class="text-3xl font-bold text-red-600 mb-2">⚠️ OVERDUE</div>
                            <div class="text-sm text-red-500 font-medium">This task has passed its deadline</div>
                        </div>
                    </div>
                    <div v-else class="bg-gradient-to-r from-gray-50 to-slate-50 p-6 rounded-xl border border-gray-200">
                        <div class="grid grid-cols-4 gap-4 text-center">
                            <!-- Days -->
                            <div class="bg-white p-4 rounded-lg shadow-sm border dark:bg-gray-800 dark:border-gray-700">
                                <div class="text-2xl font-bold text-gray-800 mb-1 dark:text-gray-100">
                                    {{ getDetailedTimeRemaining(selectedTodo.due_date)?.days || 0 }}
                                </div>
                                <div class="text-xs text-gray-500 font-medium uppercase dark:text-gray-400">Days</div>
                            </div>
                            
                            <!-- Hours -->
                            <div class="bg-white p-4 rounded-lg shadow-sm border dark:bg-gray-800 dark:border-gray-700">
                                <div class="text-2xl font-bold text-gray-800 mb-1 dark:text-gray-100">
                                    {{ getDetailedTimeRemaining(selectedTodo.due_date)?.hours || 0 }}
                                </div>
                                <div class="text-xs text-gray-500 font-medium uppercase dark:text-gray-400">Hours</div>
                            </div>
                            
                            <!-- Minutes -->
                            <div class="bg-white p-4 rounded-lg shadow-sm border dark:bg-gray-800 dark:border-gray-700">
                                <div class="text-2xl font-bold text-gray-800 mb-1 dark:text-gray-100">
                                    {{ getDetailedTimeRemaining(selectedTodo.due_date)?.minutes || 0 }}
                                </div>
                                <div class="text-xs text-gray-500 font-medium uppercase dark:text-gray-400">Minutes</div>
                            </div>
                            
                            <!-- Seconds -->
                            <div class="bg-white p-4 rounded-lg shadow-sm border dark:bg-gray-800 dark:border-gray-700">
                                <div class="text-2xl font-bold text-red-600 mb-1 transition-all duration-300">
                                    {{ getDetailedTimeRemaining(selectedTodo.due_date)?.seconds || 0 }}
                                </div>
                                <div class="text-xs text-gray-500 font-medium uppercase dark:text-gray-400">Seconds</div>
                            </div>
                        </div>
                        
                        <!-- Progress Bar -->
                        <div class="mt-4">
                            <div class="flex justify-between text-xs text-gray-500 dark:text-gray-400 mb-2">
                                <span>Progress</span>
                                <span :class="getDetailedTimeRemaining(selectedTodo.due_date)?.class">
                                    {{ getDetailedTimeRemaining(selectedTodo.due_date)?.days <= 1 ? 'Urgent' : 'On Track' }}
                                </span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                <div 
                                    class="h-2 rounded-full transition-all duration-500"
                                    :class="{
                                        'bg-red-500': getDetailedTimeRemaining(selectedTodo.due_date)?.days <= 0,
                                        'bg-orange-500': getDetailedTimeRemaining(selectedTodo.due_date)?.days <= 1,
                                        'bg-green-500': getDetailedTimeRemaining(selectedTodo.due_date)?.days > 1
                                    }"
                                    :style="{
                                        width: Math.max(10, Math.min(100, 
                                            getAllocatedDuration(selectedTodo.due_date, selectedTodo.created_at) ? 
                                            ((getAllocatedDuration(selectedTodo.due_date, selectedTodo.created_at).totalDays * 24 * 60 * 60 + 
                                              getAllocatedDuration(selectedTodo.due_date, selectedTodo.created_at).totalHours * 60 * 60 + 
                                              getAllocatedDuration(selectedTodo.due_date, selectedTodo.created_at).totalMinutes * 60) - 
                                             (getDetailedTimeRemaining(selectedTodo.due_date).days * 24 * 60 * 60 + 
                                              getDetailedTimeRemaining(selectedTodo.due_date).hours * 60 * 60 + 
                                              getDetailedTimeRemaining(selectedTodo.due_date).minutes * 60 + 
                                              getDetailedTimeRemaining(selectedTodo.due_date).seconds)) / 
                                            (getAllocatedDuration(selectedTodo.due_date, selectedTodo.created_at).totalDays * 24 * 60 * 60 + 
                                             getAllocatedDuration(selectedTodo.due_date, selectedTodo.created_at).totalHours * 60 * 60 + 
                                             getAllocatedDuration(selectedTodo.due_date, selectedTodo.created_at).totalMinutes * 60) * 100 : 50
                                        )) + '%'
                                    }"
                                ></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Assigned By</label>
                        <p class="text-sm text-gray-900 dark:text-gray-100 mt-1">{{ selectedTodo.assigned_by?.name || 'N/A' }}</p>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Assigned To</label>
                        <p class="text-sm text-gray-900 dark:text-gray-100 mt-1">{{ selectedTodo.user?.name || 'Self' }}</p>
                    </div>
                </div>

                <div class="mb-6">
                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase block mb-2">Description</label>
                    <div class="bg-gray-50 dark:bg-gray-900/50 p-4 rounded-lg border border-gray-200 dark:border-gray-700">
                        <div v-if="selectedTodo.description" class="text-sm text-gray-900 dark:text-gray-100 prose prose-sm max-w-none dark:prose-invert" v-html="selectedTodo.description"></div>
                        <p v-else class="text-sm text-gray-500 dark:text-gray-400 italic">No description provided</p>
                    </div>
                </div>

                <div class="flex justify-end gap-3">
                    <SecondaryButton @click="showDetailsModal = false">
                        Close
                    </SecondaryButton>
                </div>
            </div>
        </Modal>

        <!-- Create Task Modal -->
        <Modal :show="showCreateModal" @close="showCreateModal = false">
            <div class="p-6 dark:bg-gray-800">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-6">Create New Task</h2>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Task Type</label>
                        <div class="flex gap-6">
                            <label class="flex items-center text-gray-700 dark:text-gray-300">
                                <input type="radio" v-model="createForm.type" value="self" class="mr-2 text-blue-600" />
                                <span>Personal Task</span>
                            </label>
                            <label class="flex items-center text-gray-700 dark:text-gray-300">
                                <input type="radio" v-model="createForm.type" value="assign" class="mr-2 text-blue-600" />
                                <span>Assign to User</span>
                            </label>
                        </div>
                    </div>
                    
                    <div>
                        <InputLabel value="Task Title" />
                        <TextInput v-model="createForm.task" placeholder="Enter task title" class="w-full" />
                        <InputError :message="createForm.errors.task" />
                    </div>
                    
                    <div v-if="createForm.type === 'assign'">
                        <InputLabel value="Assign to User" />
                        <select v-model="createForm.user_id" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg px-3 py-2 w-full focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="">Select a user</option>
                            <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
                        </select>
                        <InputError :message="createForm.errors.user_id" />
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="Priority" />
                            <select v-model="createForm.priority" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg px-3 py-2 w-full focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>
                        
                        <div>
                            <InputLabel value="Category" />
                            <TextInput v-model="createForm.category" placeholder="Optional" class="w-full" />
                        </div>
                    </div>
                    
                    <div>
                        <InputLabel value="Due Date & Time" />
                        <input 
                            type="datetime-local"
                            v-model="createForm.due_date"
                            class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg px-3 py-2 w-full focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        />
                        <InputError :message="createForm.errors.due_date" />
                    </div>
                    
                    <div>
                        <InputLabel value="Description" />
                        <div class="summernote-wrapper">
                            <div id="summernote" ref="summernote"></div>
                        </div>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton @click="showCreateModal = false">Cancel</SecondaryButton>
                    <PrimaryButton :disabled="createForm.processing" @click="createTodo">
                        <span v-if="createForm.processing">Creating...</span>
                        <span v-else>Create Task</span>
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
     
    </AuthenticatedLayout>
</template>

<style scoped>
/* Summernote overrides */
:deep(.note-editor) {
    border: 1px solid #d1d5db !important;
    border-radius: 0.5rem;
}

:deep(.note-editor.note-frame) {
    border-color: #d1d5db !important;
}

:deep(.note-toolbar) {
    background-color: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
    border-radius: 0.5rem 0.5rem 0 0;
}

:deep(.note-btn) {
    background: white;
    border: 1px solid #e5e7eb;
    padding: 0.25rem 0.5rem;
    margin: 0 2px;
    border-radius: 0.25rem;
}

:deep(.note-btn:hover) {
    background-color: #f3f4f6;
}

:deep(.note-editable) {
    min-height: 150px;
    padding: 1rem;
}

.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>

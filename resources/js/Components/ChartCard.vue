<script setup>
const props = defineProps({
    title: String,
    icon: [Object, Function], // Allow both Object and Function
    iconColor: {
        type: String,
        default: 'text-blue-600'
    },
    height: {
        type: [String, Number],
        default: 300
    }
});
</script>

<template>
    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                <component v-if="icon" :is="icon" :size="20" :class="iconColor" />
                {{ title }}
            </h3>
        </div>
        <div class="p-6">
            <slot></slot>
        </div>
    </div>
</template>
<script setup>
import { computed } from 'vue';

const props = defineProps({
    title: String,
    value: [String, Number],
    subtitle: String,
    icon: [Object, Function], // Allow both Object and Function
    iconColor: {
        type: String,
        default: 'text-blue-500'
    },
    borderColor: {
        type: String,
        default: 'border-blue-500'
    },
    subtitleColor: {
        type: String,
        default: 'text-gray-600'
    },
    trend: {
        type: Object,
        default: null
    }
});

const trendColor = computed(() => {
    if (!props.trend) return '';
    return props.trend.direction === 'up' ? 'text-green-600' : 'text-red-600';
});
</script>

<template>
    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 border-l-4" :class="borderColor">
        <div class="flex items-center justify-between">
            <div class="flex-1">
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">{{ title }}</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ value }}</p>
                <div class="flex items-center gap-2 mt-1">
                    <p v-if="subtitle" class="text-sm flex items-center gap-1" :class="subtitleColor">
                        <slot name="subtitle-icon"></slot>
                        {{ subtitle }}
                    </p>
                    <p v-if="trend" class="text-sm flex items-center gap-1" :class="trendColor">
                        <slot name="trend-icon"></slot>
                        {{ trend.value }}{{ trend.unit || '' }} {{ trend.label || '' }}
                    </p>
                </div>
            </div>
            <component v-if="icon" :is="icon" :size="40" :class="iconColor" />
        </div>
    </div>
</template>
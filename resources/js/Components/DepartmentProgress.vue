<script setup>
import { computed } from 'vue';

const props = defineProps({
    departments: {
        type: Array,
        required: false,
        default: () => []
    },
    suppliers: {
        type: Array,
        required: false,
        default: () => []
    }
});

const utilizationOf = (s) => {
    const limit = Number(s.threshold_limit) || 0;
    const due = Number(s.current_due ?? s.total_owing ?? 0);
    if (limit <= 0) return 0;
    return Math.min(100, Number(((due / limit) * 100).toFixed(2)));
};

const colorOf = (u) => {
    if (u >= 90) return 'bg-red-600';
    if (u >= 75) return 'bg-orange-500';
    if (u >= 50) return 'bg-yellow-500';
    return 'bg-green-500';
};

const topSuppliers = computed(() => {
    const items = (props.suppliers || [])
        .map(s => ({
            ...s,
            utilization: typeof s.utilization === 'number' ? s.utilization : utilizationOf(s)
        }))
        .filter(s => (Number(s.threshold_limit) || 0) > 0)
        .sort((a, b) => b.utilization - a.utilization)
        .slice(0, 5);
    return items;
});
</script>

<template>
    <div class="space-y-4">
        <template v-if="(suppliers && suppliers.length)">
            <div v-for="s in topSuppliers" :key="s.id || s.code" class="p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-medium text-gray-900">
                        {{ s.name }}
                        <span class="text-xs text-gray-500 ml-2">{{ s.code }}</span>
                    </span>
                    <span class="text-sm text-gray-600">
                        ৳{{ new Intl.NumberFormat('en-BD').format(Number(s.current_due ?? s.total_owing ?? 0)) }} due
                    </span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2 mb-2">
                    <div
                        class="h-2 rounded-full transition-all duration-500 ease-out"
                        :class="colorOf(s.utilization)"
                        :style="{ width: (s.utilization ?? 0) + '%' }"
                    ></div>
                </div>
                <div class="flex justify-between text-xs text-gray-600">
                    <span class="flex items-center gap-1">
                        <div class="w-2 h-2 rounded-full" :class="colorOf(s.utilization)"></div>
                        Limit Usage {{ s.utilization }}%
                    </span>
                    <span>Limit: ৳{{ new Intl.NumberFormat('en-BD').format(Number(s.threshold_limit || 0)) }}</span>
                </div>
            </div>
        </template>

        <template v-else>
            <div v-for="dept in departments" :key="dept.name" class="p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-medium text-gray-900">{{ dept.name }}</span>
                    <span class="text-sm text-gray-600">{{ dept.employees }} employees</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2 mb-2">
                    <div 
                        class="h-2 rounded-full transition-all duration-500 ease-out"
                        :class="dept.performance >= dept.target ? 'bg-green-500' : dept.performance >= dept.target * 0.8 ? 'bg-yellow-500' : 'bg-red-500'"
                        :style="`width: ${Math.min(dept.performance, 100)}%`"
                    ></div>
                </div>
                <div class="flex justify-between text-xs text-gray-600">
                    <span class="flex items-center gap-1">
                        <div class="w-2 h-2 rounded-full" :class="dept.performance >= dept.target ? 'bg-green-500' : dept.performance >= dept.target * 0.8 ? 'bg-yellow-500' : 'bg-red-500'"></div>
                        {{ dept.performance }}% achieved
                    </span>
                    <span>Target: {{ dept.target }}%</span>
                </div>
            </div>
        </template>
    </div>
</template>

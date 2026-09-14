<script setup>
import { computed } from 'vue';

const props = defineProps({
    name: { type: String, default: '' },
    initials: { type: String, default: '' },
    src: { type: String, default: null },
    size: { type: String, default: 'md' }, // xs | sm | md | lg
    online: { type: Boolean, default: false },
    showPresence: { type: Boolean, default: false },
    group: { type: Boolean, default: false },
});

const sizeClasses = {
    xs: 'h-6 w-6 text-[10px]',
    sm: 'h-8 w-8 text-xs',
    md: 'h-10 w-10 text-sm',
    lg: 'h-14 w-14 text-lg',
};

const dotClasses = {
    xs: 'h-1.5 w-1.5',
    sm: 'h-2 w-2',
    md: 'h-2.5 w-2.5',
    lg: 'h-3.5 w-3.5',
};

/**
 * Deterministic colour per person so the same colleague keeps the same avatar
 * tint everywhere in the app.
 */
const palette = [
    'bg-rose-500', 'bg-orange-500', 'bg-amber-500', 'bg-lime-600',
    'bg-emerald-500', 'bg-teal-500', 'bg-cyan-600', 'bg-sky-500',
    'bg-indigo-500', 'bg-violet-500', 'bg-fuchsia-500', 'bg-pink-500',
];

const tint = computed(() => {
    if (props.group) return 'bg-gray-500 dark:bg-gray-600';

    const key = props.name || props.initials || '?';
    let hash = 0;
    for (let i = 0; i < key.length; i += 1) {
        hash = (hash * 31 + key.charCodeAt(i)) % 997;
    }
    return palette[hash % palette.length];
});

const label = computed(() => (props.initials || props.name?.charAt(0) || '?').toUpperCase());
</script>

<template>
    <span class="relative inline-flex shrink-0">
        <img
            v-if="src"
            :src="src"
            :alt="name"
            class="rounded-full object-cover ring-1 ring-black/5 dark:ring-white/10"
            :class="sizeClasses[size]"
        />
        <span
            v-else
            class="inline-flex items-center justify-center rounded-full font-semibold text-white ring-1 ring-black/5 dark:ring-white/10"
            :class="[sizeClasses[size], tint]"
            :title="name"
        >
            {{ label }}
        </span>

        <span
            v-if="showPresence"
            class="absolute bottom-0 right-0 rounded-full ring-2 ring-white dark:ring-gray-800"
            :class="[dotClasses[size], online ? 'bg-green-500' : 'bg-gray-300 dark:bg-gray-600']"
            :title="online ? 'Online' : 'Offline'"
        />
    </span>
</template>

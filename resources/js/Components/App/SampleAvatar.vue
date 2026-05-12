<script setup>
import { computed } from 'vue';

const props = defineProps({
    photoUrl: { type: String, default: '' },
    size: { type: String, default: 'sm' },     // sm | md
    alt: { type: String, default: '' },
    gender: { type: String, default: '' },
});

const sizeClass = {
    sm: 'h-6 w-6',
    md: 'h-9 w-9',
};

const fallbackSrc = computed(() => {
    const g = (props.gender || '').toUpperCase();
    if (g === 'F') return '/avatar-female.png';
    if (g === 'M') return '/avatar-male.png';
    return '/avatar-unknown.png';
});
</script>

<template>
    <img
        v-if="photoUrl"
        :src="photoUrl"
        :alt="alt"
        loading="lazy"
        referrerpolicy="no-referrer"
        :class="['shrink-0 rounded-full object-cover ring-1 ring-paper-300', sizeClass[size] || sizeClass.sm]"
    />
    <img
        v-else
        :src="fallbackSrc"
        :alt="alt"
        loading="lazy"
        :class="['shrink-0 rounded-full object-cover ring-1 ring-paper-300 opacity-70', sizeClass[size] || sizeClass.sm]"
    />
</template>

<script setup>
defineProps({
    photoUrl: { type: String, default: '' },
    size: { type: String, default: 'sm' },     // sm | md
    alt: { type: String, default: '' },
});

const sizeClass = {
    sm: 'h-6 w-6',
    md: 'h-9 w-9',
};
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
</template>

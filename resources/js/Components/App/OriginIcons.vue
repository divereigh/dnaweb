<script setup>
// Icons for the marked origin regions a sample holds any share of.
// Rendered at a fixed height with the width left to the image, because
// the set is a mix of flags (wide) and symbols (near-square) and forcing
// them all into a square box distorts them.
defineProps({
    icons: { type: Array, default: () => [] },
});
</script>

<template>
    <img
        v-for="icon in icons"
        :key="icon.src"
        :src="icon.src"
        :alt="icon.label"
        :title="icon.label"
        class="h-5 w-auto max-w-[2.25rem] shrink-0"
    />
</template>

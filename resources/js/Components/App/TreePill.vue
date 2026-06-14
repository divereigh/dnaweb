<script setup>
import { computed } from 'vue';
import { contrastText } from '@/lib/colour';

const props = defineProps({
    // { id, name, letter, colour }
    tree: { type: Object, required: true },
});

const emit = defineEmits(['edit']);

// A null/blank colour renders a white pill with a border so it stays
// visible against the page. A set colour paints the background and we
// pick black or white text by luminance for contrast.
const bg = computed(() => props.tree.colour || '#ffffff');
const textColour = computed(() => contrastText(props.tree.colour));
</script>

<template>
    <button
        type="button"
        class="inline-flex h-5 w-5 items-center justify-center rounded text-[11px] font-semibold ring-1 ring-inset ring-black/15 transition hover:ring-2 hover:ring-wine-500 focus:outline-none focus:ring-2 focus:ring-wine-500"
        :style="{ backgroundColor: bg, color: textColour }"
        :title="tree.name"
        @click="emit('edit', tree)"
    >
        {{ tree.letter }}
        <span class="sr-only">Edit tree {{ tree.name }}</span>
    </button>
</template>

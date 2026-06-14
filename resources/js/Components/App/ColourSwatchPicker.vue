<script setup>
import { computed } from 'vue';
import { contrastText as contrast } from '@/lib/colour';

const props = defineProps({
    // v-model: the selected #rrggbb hex, or null for "white / no colour"
    modelValue: { type: String, default: null },
    // letter shown in the live-preview swatch
    letter: { type: String, default: '?' },
});

const emit = defineEmits(['update:modelValue']);

// 6 × 5 swatch grid: yellow / orange / red / pink / purple / blue /
// teal / green across light → dark, plus a greyscale bottom row.
const SWATCHES = [
    '#fde68a', '#fdba74', '#fca5a5', '#f9a8d4', '#c4b5fd', '#a5b4fc',
    '#bae6fd', '#a7f3d0', '#facc15', '#fb923c', '#f87171', '#ec4899',
    '#8b5cf6', '#6366f1', '#0ea5e9', '#34d399', '#ca8a04', '#c2410c',
    '#b91c1c', '#9d174d', '#6d28d9', '#3730a3', '#0369a1', '#15803d',
    '#000000', '#404040', '#737373', '#a3a3a3', '#d4d4d4', '#ffffff',
];

const previewBg = computed(() => props.modelValue || '#ffffff');
const previewText = computed(() => contrast(props.modelValue));

function pick(hex) {
    emit('update:modelValue', props.modelValue === hex ? null : hex);
}
</script>

<template>
    <div class="flex items-start gap-4">
        <!-- Live preview swatch -->
        <div
            class="flex h-20 w-20 shrink-0 items-center justify-center rounded-lg text-2xl font-bold ring-1 ring-inset ring-black/15"
            :style="{ backgroundColor: previewBg, color: previewText }"
        >
            {{ letter }}
        </div>
        <div class="border-l border-paper-300 pl-4">
            <div class="grid grid-cols-6 gap-2">
                <button
                    v-for="hex in SWATCHES"
                    :key="hex"
                    type="button"
                    class="flex h-7 w-7 items-center justify-center rounded ring-1 ring-inset ring-black/15 transition hover:scale-110 focus:outline-none focus:ring-2 focus:ring-wine-500"
                    :style="{ backgroundColor: hex }"
                    :title="hex"
                    @click="pick(hex)"
                >
                    <svg
                        v-if="modelValue === hex"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                        class="h-4 w-4"
                        :style="{ color: contrast(hex) }"
                    >
                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143z" clip-rule="evenodd" />
                    </svg>
                </button>
            </div>
            <button
                type="button"
                class="mt-3 text-xs text-sepia-500 underline hover:text-wine-500"
                @click="emit('update:modelValue', null)"
            >
                Clear colour (white)
            </button>
        </div>
    </div>
</template>

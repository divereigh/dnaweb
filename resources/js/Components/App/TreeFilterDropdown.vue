<script setup>
import { computed, ref } from 'vue';
import { contrastText } from '@/lib/colour';

const props = defineProps({
    // selected tree id, or '' for All
    modelValue: { type: [Number, String], default: '' },
    // [{ id, name, letter, colour }]
    options: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue']);

const open = ref(false);

const selected = computed(
    () => props.options.find((t) => Number(t.id) === Number(props.modelValue)) || null,
);

function choose(val) {
    emit('update:modelValue', val);
    open.value = false;
}
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="flex items-center gap-1.5 rounded-md border border-paper-300 bg-paper-50 px-2 py-1 text-sm text-ink-500 focus:border-wine-500 focus:outline-none focus:ring-1 focus:ring-wine-500"
            @click="open = !open"
        >
            <span
                v-if="selected"
                class="inline-flex h-4 w-4 items-center justify-center rounded text-[10px] font-semibold ring-1 ring-inset ring-black/15"
                :style="{ backgroundColor: selected.colour || '#ffffff', color: contrastText(selected.colour) }"
            >{{ selected.letter }}</span>
            <span>{{ selected ? selected.name : 'All' }}</span>
            <svg class="h-3.5 w-3.5 text-sepia-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06z" clip-rule="evenodd" />
            </svg>
        </button>

        <template v-if="open">
            <div class="fixed inset-0 z-10" @click="open = false" />
            <ul class="absolute left-0 z-20 mt-1 max-h-64 min-w-[12rem] overflow-auto rounded-md border border-paper-300 bg-paper-50 py-1 shadow-lg">
                <li>
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm hover:bg-paper-100"
                        :class="!modelValue ? 'font-medium text-ink-600' : 'text-sepia-700'"
                        @click="choose('')"
                    >All</button>
                </li>
                <li v-for="t in options" :key="t.id">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm hover:bg-paper-100"
                        :class="Number(modelValue) === Number(t.id) ? 'bg-paper-100 font-medium text-ink-600' : 'text-sepia-700'"
                        @click="choose(t.id)"
                    >
                        <span
                            class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded text-[11px] font-semibold ring-1 ring-inset ring-black/15"
                            :style="{ backgroundColor: t.colour || '#ffffff', color: contrastText(t.colour) }"
                        >{{ t.letter }}</span>
                        {{ t.name }}
                    </button>
                </li>
            </ul>
        </template>
    </div>
</template>

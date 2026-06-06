<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    show: { type: Boolean, default: false },
    // { id, name, letter, colour }
    tree: { type: Object, default: null },
});

const emit = defineEmits(['close']);

// 6 × 4 swatch grid (light → dark per column family, greyscale row).
// Mirrors the reference palette: yellow / orange / red / pink / purple
// / blue / teal / green, plus a greyscale bottom row.
const SWATCHES = [
    '#fde68a', '#fdba74', '#fca5a5', '#f9a8d4', '#c4b5fd', '#a5b4fc',
    '#bae6fd', '#a7f3d0', '#facc15', '#fb923c', '#f87171', '#ec4899',
    '#8b5cf6', '#6366f1', '#0ea5e9', '#34d399', '#ca8a04', '#c2410c',
    '#b91c1c', '#9d174d', '#6d28d9', '#3730a3', '#0369a1', '#15803d',
    '#000000', '#404040', '#737373', '#a3a3a3', '#d4d4d4', '#ffffff',
];

const form = useForm({ name: '', colour: null });
const nameInput = ref(null);

watch(
    () => [props.show, props.tree?.id],
    () => {
        if (props.show && props.tree) {
            form.clearErrors();
            form.name = props.tree.name || '';
            form.colour = props.tree.colour || null;
            nextTick(() => nameInput.value?.focus());
        }
    },
    { immediate: true },
);

const letter = computed(() =>
    (form.name || props.tree?.letter || '?').trim().charAt(0).toUpperCase() || '?',
);

const previewBg = computed(() => form.colour || '#ffffff');
const previewText = computed(() => {
    const hex = (form.colour || '#ffffff').replace('#', '');
    if (hex.length !== 6) return '#1c1917';
    const r = parseInt(hex.slice(0, 2), 16);
    const g = parseInt(hex.slice(2, 4), 16);
    const b = parseInt(hex.slice(4, 6), 16);
    const luma = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    return luma > 0.6 ? '#1c1917' : '#ffffff';
});

function pick(hex) {
    form.colour = form.colour === hex ? null : hex;
}

function submit() {
    if (!props.tree?.id) return;
    form.put(route('dna.trees.update', props.tree.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('close'),
    });
}

function close() {
    if (form.processing) return;
    emit('close');
}
</script>

<template>
    <Teleport to="body">
        <transition
            enter-active-class="duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="show" class="fixed inset-0 z-40 bg-black/30" @click="close" />
        </transition>

        <transition
            enter-active-class="duration-200 ease-out"
            enter-from-class="translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="duration-150 ease-in"
            leave-from-class="translate-x-0"
            leave-to-class="translate-x-full"
        >
            <aside
                v-if="show"
                class="fixed right-0 top-0 z-50 flex h-full w-full max-w-md flex-col bg-paper-50 shadow-xl"
                role="dialog"
                aria-modal="true"
                @keydown.esc="close"
            >
                <header class="flex items-start justify-between border-b border-paper-300 px-5 py-4">
                    <h2 class="truncate text-base font-semibold text-ink-600">
                        Edit Tree
                    </h2>
                    <button
                        type="button"
                        class="rounded p-1 text-sepia-400 hover:bg-paper-100 hover:text-wine-500 focus:outline-none focus:ring-1 focus:ring-wine-500"
                        :disabled="form.processing"
                        @click="close"
                        aria-label="Close"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22z" />
                        </svg>
                    </button>
                </header>

                <form @submit.prevent="submit" class="flex flex-1 flex-col overflow-hidden">
                    <div class="flex-1 overflow-y-auto px-5 py-4">
                        <label class="mb-2 block text-sm font-medium text-ink-600">Tree name</label>
                        <input
                            ref="nameInput"
                            v-model="form.name"
                            type="text"
                            maxlength="100"
                            class="w-full rounded-md border-paper-300 text-sm focus:border-wine-500 focus:ring-wine-500"
                            placeholder="Tree name"
                        />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>

                        <label class="mb-2 mt-5 block text-sm font-medium text-ink-600">Assign a colour</label>
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
                                            v-if="form.colour === hex"
                                            xmlns="http://www.w3.org/2000/svg"
                                            viewBox="0 0 20 20"
                                            fill="currentColor"
                                            class="h-4 w-4"
                                            :style="{ color: hex === '#ffffff' || hex === '#d4d4d4' || hex === '#a3a3a3' ? '#1c1917' : '#ffffff' }"
                                        >
                                            <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143z" clip-rule="evenodd" />
                                        </svg>
                                    </button>
                                </div>
                                <button
                                    type="button"
                                    class="mt-3 text-xs text-sepia-500 underline hover:text-wine-500"
                                    @click="form.colour = null"
                                >
                                    Clear colour (white)
                                </button>
                            </div>
                        </div>
                        <p v-if="form.errors.colour" class="mt-1 text-xs text-red-600">{{ form.errors.colour }}</p>
                    </div>

                    <footer class="flex items-center justify-end gap-2 border-t border-paper-300 px-5 py-4">
                        <button type="button" class="btn-ghost" :disabled="form.processing" @click="close">
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="inline-flex items-center rounded-md bg-ink-500 px-3 py-1.5 text-sm font-medium text-paper-50 transition hover:bg-ink-600 disabled:opacity-50"
                            :disabled="form.processing"
                        >
                            {{ form.processing ? 'Saving…' : 'Save' }}
                        </button>
                    </footer>
                </form>
            </aside>
        </transition>
    </Teleport>
</template>

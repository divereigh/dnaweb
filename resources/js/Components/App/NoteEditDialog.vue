<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    show:        { type: Boolean, default: false },
    sampleId:    { type: Number, required: true },
    sampleLabel: { type: String, default: '' },
    eyeId:       { type: Number, required: true },
    eyeLabel:    { type: String, default: '' },
    initial:     { type: String, default: '' },
});

const emit = defineEmits(['close']);

const MAX = 1000;
const form = useForm({ notes: props.initial || '' });
const textarea = ref(null);

watch(
    () => [props.show, props.sampleId, props.eyeId, props.initial],
    () => {
        if (props.show) {
            form.clearErrors();
            form.notes = props.initial || '';
            nextTick(() => textarea.value?.focus());
        }
    },
);

const remaining = computed(() => MAX - (form.notes?.length || 0));

function submit() {
    if (!props.sampleId || !props.eyeId) return;
    form.put(route('dna.notes.update', [props.sampleId, props.eyeId]), {
        preserveScroll: true,
        preserveState:  true,
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
            <div
                v-if="show"
                class="fixed inset-0 z-40 bg-black/30"
                @click="close"
            />
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
                    <div class="min-w-0">
                        <h2 class="truncate text-base font-semibold text-ink-600">
                            Update Notes {{ sampleLabel }}
                        </h2>
                        <p class="mt-0.5 truncate text-xs text-sepia-500">
                            via Eye {{ eyeLabel }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded p-1 text-sepia-400 hover:bg-paper-100 hover:text-wine-500 focus:outline-none focus:ring-1 focus:ring-wine-500"
                        :disabled="form.processing"
                        @click="close"
                        aria-label="Close"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 20 20"
                            fill="currentColor"
                            class="h-4 w-4"
                            aria-hidden="true"
                        >
                            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22z" />
                        </svg>
                    </button>
                </header>

                <form @submit.prevent="submit" class="flex flex-1 flex-col overflow-hidden">
                    <div class="flex-1 overflow-y-auto px-5 py-4">
                        <label class="mb-2 block text-sm font-medium text-ink-600">
                            Notes
                        </label>
                        <textarea
                            ref="textarea"
                            v-model="form.notes"
                            :maxlength="MAX"
                            rows="12"
                            class="w-full rounded-md border-paper-300 font-mono text-xs leading-snug focus:border-wine-500 focus:ring-wine-500"
                            placeholder="Add or update notes about this match…"
                        />
                        <p class="mt-1 text-xs text-sepia-500">
                            {{ remaining }} characters remaining
                        </p>
                        <p v-if="form.errors.notes" class="mt-1 text-xs text-red-600">
                            {{ form.errors.notes }}
                        </p>
                    </div>

                    <footer class="flex items-center justify-end gap-2 border-t border-paper-300 px-5 py-4">
                        <button
                            type="button"
                            class="btn-ghost"
                            :disabled="form.processing"
                            @click="close"
                        >
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

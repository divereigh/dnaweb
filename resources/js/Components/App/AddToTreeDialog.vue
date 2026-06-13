<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ColourSwatchPicker from '@/Components/App/ColourSwatchPicker.vue';

const props = defineProps({
    show:        { type: Boolean, default: false },
    personId:    { type: Number, default: null },
    personLabel: { type: String, default: '' },
    // The trees displayed elsewhere on the matches page:
    // [{ id, name, letter, colour }]
    trees:       { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);

const form = useForm({ tree_id: null, colour: null });
const query = ref('');
const selected = ref(null); // the chosen tree object
const searchInput = ref(null);

watch(
    () => [props.show, props.personId],
    () => {
        if (props.show) {
            form.clearErrors();
            form.tree_id = null;
            form.colour = null;
            query.value = '';
            selected.value = null;
            nextTick(() => searchInput.value?.focus());
        }
    },
    { immediate: true },
);

// Distinct trees sorted by name, filtered by the search box.
const options = computed(() => {
    const seen = new Set();
    const list = [];
    for (const t of props.trees) {
        if (!seen.has(t.id)) {
            seen.add(t.id);
            list.push(t);
        }
    }
    list.sort((a, b) => a.name.localeCompare(b.name));
    const q = query.value.trim().toLowerCase();
    return q ? list.filter((t) => t.name.toLowerCase().includes(q)) : list;
});

const letter = computed(() => selected.value?.letter || '?');

function choose(tree) {
    selected.value = tree;
    form.tree_id = tree.id;
    form.colour = tree.colour || null;
    query.value = tree.name;
}

function submit() {
    if (!form.tree_id || !props.personId) return;
    form.transform((data) => ({ ...data, person_id: props.personId }))
        .post(route('dna.trees.add-person'), {
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
                    <div class="min-w-0">
                        <h2 class="truncate text-base font-semibold text-ink-600">Add to tree</h2>
                        <p v-if="personLabel" class="mt-0.5 truncate text-xs text-sepia-500">{{ personLabel }}</p>
                    </div>
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
                        <label class="mb-2 block text-sm font-medium text-ink-600">Tree</label>
                        <input
                            ref="searchInput"
                            v-model="query"
                            type="search"
                            class="w-full rounded-md border-paper-300 text-sm focus:border-wine-500 focus:ring-wine-500"
                            placeholder="Search trees…"
                            autocomplete="off"
                            @input="selected = null; form.tree_id = null"
                        />
                        <div class="mt-1 max-h-48 overflow-y-auto rounded-md border border-paper-300">
                            <button
                                v-for="t in options"
                                :key="t.id"
                                type="button"
                                class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm hover:bg-paper-100"
                                :class="selected?.id === t.id ? 'bg-paper-100 font-medium text-ink-600' : 'text-sepia-700'"
                                @click="choose(t)"
                            >
                                <span
                                    class="inline-flex h-5 w-5 items-center justify-center rounded text-[11px] font-semibold ring-1 ring-inset ring-black/15"
                                    :style="{ backgroundColor: t.colour || '#ffffff' }"
                                >{{ t.letter }}</span>
                                {{ t.name }}
                            </button>
                            <p v-if="!options.length" class="px-3 py-2 text-xs text-sepia-400">
                                No matching trees on this page.
                            </p>
                        </div>
                        <p v-if="form.errors.tree_id" class="mt-1 text-xs text-red-600">{{ form.errors.tree_id }}</p>

                        <label class="mb-2 mt-5 block text-sm font-medium text-ink-600">Assign a colour</label>
                        <ColourSwatchPicker v-model="form.colour" :letter="letter" />
                        <p v-if="form.errors.colour" class="mt-1 text-xs text-red-600">{{ form.errors.colour }}</p>
                    </div>

                    <footer class="flex items-center justify-end gap-2 border-t border-paper-300 px-5 py-4">
                        <button type="button" class="btn-ghost" :disabled="form.processing" @click="close">
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="inline-flex items-center rounded-md bg-ink-500 px-3 py-1.5 text-sm font-medium text-paper-50 transition hover:bg-ink-600 disabled:opacity-50"
                            :disabled="form.processing || !form.tree_id"
                        >
                            {{ form.processing ? 'Adding…' : 'Add to tree' }}
                        </button>
                    </footer>
                </form>
            </aside>
        </transition>
    </Teleport>
</template>

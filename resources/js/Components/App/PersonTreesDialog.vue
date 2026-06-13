<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import ColourSwatchPicker from '@/Components/App/ColourSwatchPicker.vue';

const props = defineProps({
    show:        { type: Boolean, default: false },
    personId:    { type: Number, default: null },
    personLabel: { type: String, default: '' },
    // The person's current trees: [{ id, name, letter, colour }]
    trees:       { type: Array, default: () => [] },
    // Trees shown elsewhere on the page, for the add-picker suggestions.
    pageTrees:   { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'edit-tree']);

// --- Add-to-tree sub-form -------------------------------------------
const adding = ref(false);
const query = ref('');
const selected = ref(null);  // chosen existing tree object, or null
const addForm = useForm({ tree_id: null, tree_name: null, colour: null });
const searchInput = ref(null);

watch(
    () => [props.show, props.personId],
    () => {
        if (props.show) {
            // Open straight into the add form when the person is in no
            // trees yet — the only thing to do is add the first one.
            resetAdd();
            adding.value = props.trees.length === 0;
        }
    },
    { immediate: true },
);

function resetAdd() {
    addForm.clearErrors();
    addForm.tree_id = null;
    addForm.tree_name = null;
    addForm.colour = null;
    query.value = '';
    selected.value = null;
}

function startAdding() {
    resetAdd();
    adding.value = true;
    nextTick(() => searchInput.value?.focus());
}

// Suggestions: distinct page trees the person isn't already in,
// filtered by the search box.
const options = computed(() => {
    const have = new Set(props.trees.map((t) => t.id));
    const seen = new Set();
    const list = [];
    for (const t of props.pageTrees) {
        if (!seen.has(t.id) && !have.has(t.id)) {
            seen.add(t.id);
            list.push(t);
        }
    }
    list.sort((a, b) => a.name.localeCompare(b.name));
    const q = query.value.trim().toLowerCase();
    return q ? list.filter((t) => t.name.toLowerCase().includes(q)) : list;
});

// Offer "create new" when the typed name doesn't exactly match an
// existing option (case-insensitive) and isn't blank.
const canCreate = computed(() => {
    const q = query.value.trim();
    if (!q) return false;
    const lower = q.toLowerCase();
    return !props.pageTrees.some((t) => t.name.toLowerCase() === lower);
});

const addLetter = computed(() => {
    const src = selected.value?.name || query.value || '?';
    return src.trim().charAt(0).toUpperCase() || '?';
});

function chooseExisting(tree) {
    selected.value = tree;
    addForm.tree_id = tree.id;
    addForm.tree_name = null;
    addForm.colour = tree.colour || null;
    query.value = tree.name;
}

function chooseCreate() {
    selected.value = null;
    addForm.tree_id = null;
    addForm.tree_name = query.value.trim();
    // leave colour as-is (user may have picked one already)
}

function onSearchInput() {
    // Typing invalidates a prior selection; treat it as a pending
    // new-name until the user clicks an option.
    selected.value = null;
    addForm.tree_id = null;
    addForm.tree_name = query.value.trim() || null;
}

const canSubmitAdd = computed(() => !!addForm.tree_id || !!(addForm.tree_name && addForm.tree_name.length));

function submitAdd() {
    if (!props.personId || !canSubmitAdd.value) return;
    addForm.transform((data) => ({ ...data, person_id: props.personId }))
        .post(route('dna.trees.add-person'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => { resetAdd(); adding.value = false; },
        });
}

// --- Remove ---------------------------------------------------------
function removeTree(tree) {
    if (!props.personId) return;
    router.post(route('dna.trees.remove-person'), {
        tree_id: tree.id,
        person_id: props.personId,
    }, { preserveScroll: true, preserveState: true });
}

function close() {
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
                        <h2 class="truncate text-base font-semibold text-ink-600">Trees</h2>
                        <p v-if="personLabel" class="mt-0.5 truncate text-xs text-sepia-500">{{ personLabel }}</p>
                    </div>
                    <button
                        type="button"
                        class="rounded p-1 text-sepia-400 hover:bg-paper-100 hover:text-wine-500 focus:outline-none focus:ring-1 focus:ring-wine-500"
                        @click="close"
                        aria-label="Close"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22z" />
                        </svg>
                    </button>
                </header>

                <div class="flex-1 overflow-y-auto px-5 py-4">
                    <!-- Current memberships -->
                    <ul v-if="trees.length" class="divide-y divide-paper-200">
                        <li v-for="t in trees" :key="t.id" class="flex items-center gap-2 py-2">
                            <span
                                class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded text-[11px] font-semibold ring-1 ring-inset ring-black/15"
                                :style="{ backgroundColor: t.colour || '#ffffff' }"
                            >{{ t.letter }}</span>
                            <span class="min-w-0 flex-1 truncate text-sm text-ink-600">{{ t.name }}</span>
                            <button
                                type="button"
                                class="rounded p-1 text-sepia-400 hover:bg-paper-100 hover:text-wine-500 focus:outline-none focus:ring-1 focus:ring-wine-500"
                                title="Edit tree name / colour"
                                @click="emit('edit-tree', t)"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                                    <path d="M13.586 3.586a2 2 0 1 1 2.828 2.828l-.793.793-2.828-2.828.793-.793zM12.379 4.793 3 14.172V17h2.828l9.379-9.379-2.828-2.828z" />
                                </svg>
                                <span class="sr-only">Edit tree</span>
                            </button>
                            <button
                                type="button"
                                class="rounded p-1 text-sepia-400 hover:bg-paper-100 hover:text-red-600 focus:outline-none focus:ring-1 focus:ring-red-500"
                                title="Remove from this tree"
                                @click="removeTree(t)"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                                    <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22z" />
                                </svg>
                                <span class="sr-only">Remove from tree</span>
                            </button>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-sepia-400">Not in any tree yet.</p>

                    <!-- Add to tree -->
                    <div class="mt-4 border-t border-paper-300 pt-4">
                        <button
                            v-if="!adding"
                            type="button"
                            class="inline-flex items-center gap-1.5 text-sm font-medium text-wine-600 hover:text-wine-700"
                            @click="startAdding"
                        >
                            <span class="text-base leading-none">+</span> Add to tree
                        </button>

                        <div v-else>
                            <label class="mb-2 block text-sm font-medium text-ink-600">Add to tree</label>
                            <input
                                ref="searchInput"
                                v-model="query"
                                type="search"
                                class="w-full rounded-md border-paper-300 text-sm focus:border-wine-500 focus:ring-wine-500"
                                placeholder="Search or type a new tree name…"
                                autocomplete="off"
                                @input="onSearchInput"
                            />
                            <div class="mt-1 max-h-48 overflow-y-auto rounded-md border border-paper-300">
                                <button
                                    v-for="t in options"
                                    :key="t.id"
                                    type="button"
                                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm hover:bg-paper-100"
                                    :class="selected?.id === t.id ? 'bg-paper-100 font-medium text-ink-600' : 'text-sepia-700'"
                                    @click="chooseExisting(t)"
                                >
                                    <span
                                        class="inline-flex h-5 w-5 items-center justify-center rounded text-[11px] font-semibold ring-1 ring-inset ring-black/15"
                                        :style="{ backgroundColor: t.colour || '#ffffff' }"
                                    >{{ t.letter }}</span>
                                    {{ t.name }}
                                </button>
                                <button
                                    v-if="canCreate"
                                    type="button"
                                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-wine-600 hover:bg-paper-100"
                                    :class="!selected && addForm.tree_name ? 'bg-paper-100 font-medium' : ''"
                                    @click="chooseCreate"
                                >
                                    <span class="text-base leading-none">+</span>
                                    Create new tree “{{ query.trim() }}”
                                </button>
                                <p v-if="!options.length && !canCreate" class="px-3 py-2 text-xs text-sepia-400">
                                    Type a name to create a new tree.
                                </p>
                            </div>
                            <p v-if="addForm.errors.tree_name" class="mt-1 text-xs text-red-600">{{ addForm.errors.tree_name }}</p>

                            <label class="mb-2 mt-4 block text-sm font-medium text-ink-600">Colour</label>
                            <ColourSwatchPicker v-model="addForm.colour" :letter="addLetter" />

                            <div class="mt-4 flex items-center gap-2">
                                <button
                                    type="button"
                                    class="inline-flex items-center rounded-md bg-ink-500 px-3 py-1.5 text-sm font-medium text-paper-50 transition hover:bg-ink-600 disabled:opacity-50"
                                    :disabled="addForm.processing || !canSubmitAdd"
                                    @click="submitAdd"
                                >
                                    {{ addForm.processing ? 'Adding…' : 'Add' }}
                                </button>
                                <button
                                    v-if="trees.length"
                                    type="button"
                                    class="btn-ghost"
                                    :disabled="addForm.processing"
                                    @click="adding = false"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>
        </transition>
    </Teleport>
</template>

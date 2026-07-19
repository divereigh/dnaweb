<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { contrastText } from '@/lib/colour';

const props = defineProps({
    // tree ids the person must be in ANY of
    include: { type: Array, default: () => [] },
    // tree ids the person must be in NONE of
    exclude: { type: Array, default: () => [] },
    // [{ id, name, letter, colour }]
    options: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:include', 'update:exclude']);

const open = ref(false);
const trigger = ref(null);
const menu = ref(null);
const pos = ref({ top: 0, left: 0, minWidth: 0 });

const includeSet = computed(() => new Set(props.include.map(Number)));
const excludeSet = computed(() => new Set(props.exclude.map(Number)));
const anyActive = computed(() => props.include.length > 0 || props.exclude.length > 0);

function place() {
    if (!trigger.value) return;
    const r = trigger.value.getBoundingClientRect();
    pos.value = { top: r.bottom + 4, left: r.left, minWidth: r.width };
}
function toggle() {
    if (open.value) { open.value = false; return; }
    place();
    open.value = true;
}

// Include and exclude are mutually exclusive per tree. Toggling one on
// clears the other for that tree; toggling the active one off returns
// the tree to neutral.
function toggleInclude(id) {
    id = Number(id);
    if (includeSet.value.has(id)) {
        emit('update:include', props.include.filter((x) => Number(x) !== id));
    } else {
        emit('update:include', [...props.include.filter((x) => Number(x) !== id), id]);
        if (excludeSet.value.has(id)) emit('update:exclude', props.exclude.filter((x) => Number(x) !== id));
    }
}
function toggleExclude(id) {
    id = Number(id);
    if (excludeSet.value.has(id)) {
        emit('update:exclude', props.exclude.filter((x) => Number(x) !== id));
    } else {
        emit('update:exclude', [...props.exclude.filter((x) => Number(x) !== id), id]);
        if (includeSet.value.has(id)) emit('update:include', props.include.filter((x) => Number(x) !== id));
    }
}
function clearAll() {
    if (props.include.length) emit('update:include', []);
    if (props.exclude.length) emit('update:exclude', []);
}

function onDocClick(e) {
    if (!open.value) return;
    if (trigger.value?.contains(e.target)) return;
    if (menu.value?.contains(e.target)) return;
    open.value = false;
}
function onKey(e) {
    if (e.key === 'Escape') open.value = false;
}
function onScrollResize(e) {
    if (!open.value) return;
    if (e && e.target && menu.value && (menu.value === e.target || menu.value.contains(e.target))) return;
    open.value = false;
}
onMounted(() => {
    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onKey);
    window.addEventListener('scroll', onScrollResize, true);
    window.addEventListener('resize', onScrollResize);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocClick);
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('scroll', onScrollResize, true);
    window.removeEventListener('resize', onScrollResize);
});
</script>

<template>
    <div class="relative">
        <button
            ref="trigger"
            type="button"
            class="flex items-center gap-1.5 rounded-md border border-paper-300 bg-paper-50 px-2 py-1 text-sm text-ink-500 focus:border-wine-500 focus:outline-none focus:ring-1 focus:ring-wine-500"
            @click="toggle"
        >
            <template v-if="anyActive">
                <span v-if="include.length" class="font-medium text-emerald-600">+{{ include.length }}</span>
                <span v-if="exclude.length" class="font-medium text-red-600">−{{ exclude.length }}</span>
            </template>
            <span v-else>All</span>
            <svg class="h-3.5 w-3.5 text-sepia-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06z" clip-rule="evenodd" />
            </svg>
        </button>

        <Teleport to="body">
            <div
                v-if="open"
                ref="menu"
                class="fixed z-50 max-h-72 min-w-[16rem] overflow-auto rounded-md border border-paper-300 bg-paper-50 py-1 shadow-lg"
                :style="{ top: pos.top + 'px', left: pos.left + 'px', minWidth: pos.minWidth + 'px' }"
            >
                <div class="flex items-center justify-between px-3 py-1.5 text-[11px] text-sepia-400">
                    <span>✓ include · ✗ exclude</span>
                    <button
                        v-if="anyActive"
                        type="button"
                        class="text-wine-600 hover:text-wine-700"
                        @click="clearAll"
                    >Clear</button>
                </div>
                <ul>
                    <li
                        v-for="t in options"
                        :key="t.id"
                        class="flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-paper-100"
                    >
                        <span
                            class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded text-[11px] font-semibold ring-1 ring-inset ring-black/15"
                            :style="{ backgroundColor: t.colour || '#ffffff', color: contrastText(t.colour) }"
                        >{{ t.letter }}</span>
                        <span
                            class="min-w-0 flex-1 truncate"
                            :class="excludeSet.has(Number(t.id)) ? 'text-sepia-400 line-through' : 'text-ink-600'"
                        >{{ t.name }}</span>
                        <button
                            type="button"
                            class="flex h-6 w-6 shrink-0 items-center justify-center rounded ring-1 ring-inset transition"
                            :class="includeSet.has(Number(t.id))
                                ? 'bg-emerald-100 text-emerald-700 ring-emerald-300'
                                : 'text-sepia-400 ring-paper-300 hover:text-emerald-600 hover:ring-emerald-300'"
                            title="Include (must be in this tree)"
                            @click="toggleInclude(t.id)"
                        >
                            <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 10.7a1 1 0 1 1 1.4-1.4l3.1 3.1 6.8-6.8a1 1 0 0 1 1.4 0z" clip-rule="evenodd" /></svg>
                        </button>
                        <button
                            type="button"
                            class="flex h-6 w-6 shrink-0 items-center justify-center rounded ring-1 ring-inset transition"
                            :class="excludeSet.has(Number(t.id))
                                ? 'bg-red-100 text-red-700 ring-red-300'
                                : 'text-sepia-400 ring-paper-300 hover:text-red-600 hover:ring-red-300'"
                            title="Exclude (must not be in this tree)"
                            @click="toggleExclude(t.id)"
                        >
                            <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4"><path fill-rule="evenodd" d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22z" clip-rule="evenodd" /></svg>
                        </button>
                    </li>
                </ul>
            </div>
        </Teleport>
    </div>
</template>

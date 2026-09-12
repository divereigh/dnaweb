<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { copyText } from '@/lib/clipboard';

const props = defineProps({
    photoUrl: { type: String, default: '' },
    size: { type: String, default: 'sm' },     // sm | md
    alt: { type: String, default: '' },
    gender: { type: String, default: '' },
    // What clicking the avatar puts on the clipboard. `null` means "use
    // alt", which every call site already sets to the name rendered
    // beside it — so the feature arrives everywhere without touching a
    // single one. Pass '' to opt a particular avatar out.
    copy: { type: String, default: null },
});

const sizeClass = {
    sm: 'h-6 w-6',
    md: 'h-9 w-9',
};

const fallbackSrc = computed(() => {
    const g = (props.gender || '').toUpperCase();
    if (g === 'F') return '/avatar-female.png';
    if (g === 'M') return '/avatar-male.png';
    return '/avatar-unknown.png';
});

const src = computed(() => props.photoUrl || fallbackSrc.value);

const imgClass = computed(() => [
    'block shrink-0 rounded-full object-cover ring-1 ring-paper-300',
    sizeClass[props.size] || sizeClass.sm,
    props.photoUrl ? '' : 'opacity-70',
]);

// Trimmed because these come straight from Ancestry display names, which
// carry stray whitespace often enough to be worth not pasting.
const text = computed(() => (props.copy === null ? props.alt : props.copy).trim());
const copyable = computed(() => text.value.length > 0);

// A tick over the avatar for a moment, rather than a toast: the click
// target is the only thing the eye is on, and match lists are dense
// enough that anything page-level would be noise.
const copied = ref(false);
let timer = null;

async function onCopy() {
    if (! copyable.value) return;
    if (! await copyText(text.value)) return;

    copied.value = true;
    clearTimeout(timer);
    timer = setTimeout(() => { copied.value = false; }, 1200);
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <!-- Click is stopped as well as prevented: the avatar usually sits
         beside a Link in the same flex row, and a future call site that
         nests it inside one shouldn't navigate on a copy. -->
    <button
        v-if="copyable"
        type="button"
        class="relative shrink-0 cursor-pointer rounded-full ring-wine-400 transition hover:ring-2 focus:outline-none focus:ring-2 focus:ring-wine-500"
        :title="copied ? 'Copied' : `Copy “${text}”`"
        @click.stop.prevent="onCopy"
    >
        <img
            :src="src"
            :alt="alt"
            loading="lazy"
            :referrerpolicy="photoUrl ? 'no-referrer' : undefined"
            :class="imgClass"
        />
        <span
            v-if="copied"
            class="absolute inset-0 flex items-center justify-center rounded-full bg-ink-500/80 text-paper-50"
            aria-hidden="true"
        >
            <svg viewBox="0 0 20 20" fill="currentColor" class="h-1/2 w-1/2">
                <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 10.7a1 1 0 1 1 1.4-1.4l3.1 3.1 6.8-6.8a1 1 0 0 1 1.4 0z" clip-rule="evenodd" />
            </svg>
        </span>
        <span class="sr-only">{{ copied ? 'Copied' : `Copy ${text}` }}</span>
    </button>

    <img
        v-else
        :src="src"
        :alt="alt"
        loading="lazy"
        :referrerpolicy="photoUrl ? 'no-referrer' : undefined"
        :class="imgClass"
    />
</template>

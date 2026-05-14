<script setup>
import { computed } from 'vue';

const props = defineProps({
    code: { type: String, default: '' },
    paternalCluster: { type: String, default: '' },
});

const PAIR = /^p[12]$/i;

const view = computed(() => {
    const code = (props.code || '').trim();
    if (!code) return null;

    const pat = (props.paternalCluster || '').trim().toLowerCase();
    if (PAIR.test(code) && PAIR.test(pat)) {
        const isPaternal = code.toLowerCase() === pat;
        return isPaternal
            ? { label: 'PATERNAL', tone: 'paternal' }
            : { label: 'MATERNAL', tone: 'maternal' };
    }
    return { label: code, tone: 'plain' };
});

const toneClass = {
    paternal: 'bg-blue-100 text-blue-800 ring-blue-200',
    maternal: 'bg-pink-100 text-pink-800 ring-pink-200',
    plain:    'bg-orange-100 text-orange-800 ring-orange-200',
};
</script>

<template>
    <span
        v-if="view"
        class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold tracking-wide ring-1"
        :class="toneClass[view.tone]"
    >
        {{ view.label }}
    </span>
</template>

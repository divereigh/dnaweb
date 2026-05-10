<script setup>
import { computed } from 'vue';
import { clusterStyle } from '@/lib/clusterPalette.js';

const props = defineProps({
    code: { type: String, default: '' },
    cls: { type: String, default: '' },
});

const style = computed(() => {
    const s = clusterStyle(props.cls);
    if (!s) {
        return 'background:var(--cluster-default-bg);color:var(--cluster-default-fg);';
    }
    return `background:${s.bg};color:${s.fg};`;
});
</script>

<template>
    <span v-if="code" class="stamp" :style="style">
        {{ code }}
    </span>
</template>

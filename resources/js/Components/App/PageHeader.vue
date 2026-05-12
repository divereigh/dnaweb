<script setup>
import { computed, useSlots } from 'vue';

const props = defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    eyebrow: { type: String, default: '' },
    compact: { type: Boolean, default: false },
});

const slots = useSlots();

const titleClasses = computed(() => [
    'font-semibold tracking-tight text-ink-600',
    props.compact ? 'text-lg sm:text-xl' : 'text-xl sm:text-2xl',
    // When a custom #title slot is used (multiple inline children),
    // switch to flex-wrap so nothing gets ellipsis-clipped.
    slots.title ? 'flex flex-wrap items-center gap-2' : 'truncate',
]);
</script>

<template>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <p v-if="eyebrow" class="eyebrow mb-1">{{ eyebrow }}</p>
            <div class="flex min-w-0 items-center gap-2">
                <slot name="titleBefore" />
                <h1 :class="titleClasses">
                    <slot name="title">{{ title }}</slot>
                </h1>
                <slot name="titleAfter" />
            </div>
            <div
                v-if="subtitle || $slots.subtitle"
                :class="[
                    'flex flex-wrap items-center gap-2 text-sepia-500',
                    compact ? 'mt-0.5 text-sm' : 'mt-1 text-sm',
                ]"
            >
                <slot name="subtitle">{{ subtitle }}</slot>
            </div>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2 sm:justify-end">
            <slot name="actions" />
        </div>
    </div>
</template>

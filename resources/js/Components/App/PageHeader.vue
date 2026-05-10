<script setup>
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    eyebrow: { type: String, default: '' },
    // compact: smaller title + tighter spacing for detail pages
    // where the data below is the focus.
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <p v-if="eyebrow" :class="compact ? 'eyebrow mb-1' : 'eyebrow mb-1.5'">
                {{ eyebrow }}
            </p>
            <h1
                :class="[
                    'truncate font-display font-medium leading-[1.05] tracking-tight text-ink-600',
                    compact
                        ? 'text-xl sm:text-2xl'
                        : 'text-3xl sm:text-4xl',
                ]"
            >
                {{ title }}
            </h1>
            <p
                v-if="subtitle"
                :class="[
                    'font-serif italic text-sepia-500',
                    compact ? 'mt-1 text-sm' : 'mt-1.5 text-[0.95rem]',
                ]"
            >
                {{ subtitle }}
            </p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2 sm:justify-end">
            <slot name="actions" />
        </div>
    </div>
</template>

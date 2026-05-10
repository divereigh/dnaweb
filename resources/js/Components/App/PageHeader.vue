<script setup>
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    eyebrow: { type: String, default: '' },
});
</script>

<template>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <p v-if="eyebrow" class="eyebrow mb-1.5">{{ eyebrow }}</p>
            <h1
                class="truncate font-display text-3xl font-medium leading-[1.05] tracking-tight text-ink-600 sm:text-4xl"
            >
                {{ title }}
            </h1>
            <p
                v-if="subtitle"
                class="mt-1.5 font-serif text-[0.95rem] italic text-sepia-500"
            >
                {{ subtitle }}
            </p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2 sm:justify-end">
            <slot name="actions" />
        </div>
    </div>
</template>

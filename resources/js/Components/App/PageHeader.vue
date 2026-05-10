<script setup>
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    eyebrow: { type: String, default: '' },
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <p v-if="eyebrow" class="eyebrow mb-1">{{ eyebrow }}</p>
            <div class="flex min-w-0 items-center gap-1.5">
                <h1
                    :class="[
                        'truncate font-semibold tracking-tight text-ink-600',
                        compact ? 'text-lg sm:text-xl' : 'text-xl sm:text-2xl',
                    ]"
                >
                    {{ title }}
                </h1>
                <slot name="titleAfter" />
            </div>
            <p
                v-if="subtitle"
                :class="['text-sepia-500', compact ? 'mt-0.5 text-sm' : 'mt-1 text-sm']"
            >
                {{ subtitle }}
            </p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2 sm:justify-end">
            <slot name="actions" />
        </div>
    </div>
</template>

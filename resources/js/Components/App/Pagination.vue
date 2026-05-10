<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    page: { type: Number, required: true },
    pages: { type: Number, required: true },
    total: { type: Number, default: null },
    perPage: { type: Number, default: null },
    perPageOptions: { type: Array, default: () => [] },
    only: { type: Array, default: () => [] },
});

function go(page) {
    if (page < 1 || page > props.pages || page === props.page) return;
    const opts = { preserveScroll: true, preserveState: true, replace: true, data: { page } };
    if (props.only.length) opts.only = props.only;
    router.reload(opts);
}

function setPerPage(perPage) {
    if (Number(perPage) === Number(props.perPage)) return;
    const opts = {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        data: { per_page: Number(perPage), page: 1 },
    };
    if (props.only.length) opts.only = props.only;
    router.reload(opts);
}

const range = computed(() => {
    const max = 7;
    if (props.pages <= max) {
        return Array.from({ length: props.pages }, (_, i) => i + 1);
    }
    const set = new Set([1, props.pages, props.page, props.page - 1, props.page + 1]);
    if (props.page <= 3) [2, 3, 4].forEach((n) => set.add(n));
    if (props.page >= props.pages - 2)
        [props.pages - 1, props.pages - 2, props.pages - 3].forEach((n) => set.add(n));
    return [...set].filter((n) => n >= 1 && n <= props.pages).sort((a, b) => a - b);
});
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div
            class="flex flex-wrap items-baseline gap-x-3 gap-y-1 font-sans text-xs uppercase tracking-eyebrow text-sepia-500"
        >
            <template v-if="total !== null">
                <span class="font-mono text-sm tracking-normal text-ink-400">
                    {{ total.toLocaleString() }}
                </span>
                <span class="not-italic">{{ total === 1 ? 'entry' : 'entries' }}</span>
            </template>
            <span
                v-if="total !== null && perPage !== null && perPageOptions.length"
                aria-hidden="true"
                class="text-sepia-400"
                >·</span
            >
            <label
                v-if="perPage !== null && perPageOptions.length"
                class="inline-flex items-center gap-2"
            >
                <span class="not-italic text-sepia-500">per folio</span>
                <select
                    :value="perPage"
                    @change="setPerPage($event.target.value)"
                    class="rounded-sm border border-paper-300 bg-paper-50 py-0.5 pl-2 pr-7 font-mono text-xs tracking-normal text-ink-500 focus:border-wine-500 focus:ring-wine-500"
                >
                    <option v-for="n in perPageOptions" :key="n" :value="n">
                        {{ n }}
                    </option>
                </select>
            </label>
        </div>

        <nav v-if="pages > 1" class="flex items-center gap-1">
            <button
                @click="go(page - 1)"
                :class="['pg-tile', page <= 1 ? 'is-disabled' : '']"
                :disabled="page <= 1"
                aria-label="Previous"
            >
                ‹
            </button>
            <template v-for="(n, idx) in range" :key="idx">
                <span
                    v-if="idx > 0 && n !== range[idx - 1] + 1"
                    class="font-mono text-xs text-sepia-400"
                    >…</span
                >
                <button
                    @click="go(n)"
                    :class="['pg-tile', n === page ? 'is-current' : '']"
                >
                    {{ n }}
                </button>
            </template>
            <button
                @click="go(page + 1)"
                :class="['pg-tile', page >= pages ? 'is-disabled' : '']"
                :disabled="page >= pages"
                aria-label="Next"
            >
                ›
            </button>
        </nav>
    </div>
</template>

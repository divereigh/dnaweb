<script setup>
import { computed, ref, watch, onUnmounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import SampleAvatar from '@/Components/App/SampleAvatar.vue';

const props = defineProps({
    sample: { type: Object, required: true },
    status: { type: Object, required: true },
    regions: { type: Array, default: () => [] },
});

// Regions grouped under their macro region, each group ordered by share
// and the groups themselves ordered by their combined share. The macro
// name is a heading rather than a colour: Ancestry has 27 of them, far
// more than any categorical palette can tell apart, so identity is
// carried by the label and magnitude by the bar length.
const groups = computed(() => {
    const by = new Map();
    for (const r of props.regions) {
        const key = r.macro_region_key || '_';
        if (!by.has(key)) {
            by.set(key, { key, name: r.macro_region || 'Other', total: 0, rows: [] });
        }
        const g = by.get(key);
        g.total += r.percentage;
        g.rows.push(r);
    }
    return [...by.values()].sort((a, b) => b.total - a.total);
});

// Bars are scaled against the largest single region, not against 100, so
// a kit whose biggest share is 12% still produces a readable chart.
const maxPercent = computed(() =>
    props.regions.reduce((m, r) => Math.max(m, r.percentage), 0) || 100,
);

const hiddenPercent = computed(() => Math.max(0, 100 - props.status.stored_percent));

// The four states the page can be in. Kept as one computed so the
// template never has to re-derive the precedence between them.
const state = computed(() => {
    if (!props.status.loadable) return 'unloadable';
    if (props.status.queued) return 'loading';
    if (props.status.stored_percent >= 100) return 'complete';
    return 'restricted';
});

function reloadNow() {
    router.reload({ only: ['status', 'regions'], preserveScroll: true, preserveState: true });
}

// Poll while the queue has this sample. Regions come along on every tick
// so a restricted kit visibly fills in as each eye is walked — that walk
// is the whole reason the page can sit at 62% for a few seconds.
let pollTimer = null;
function startPoll() {
    if (pollTimer) return;
    pollTimer = setInterval(reloadNow, 5000);
}
function stopPoll() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}
watch(
    () => props.status.queued,
    (queued) => (queued ? startPoll() : stopPoll()),
    { immediate: true },
);
onUnmounted(stopPoll);

// Re-check from scratch: forget which eyes have been asked, then queue.
// The only way to look again at a kit whose eyes are all exhausted.
const requeuing = ref(false);
function recheck() {
    if (requeuing.value) return;
    requeuing.value = true;
    router.post(
        route('dna.origins.requeue', props.sample.id),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => { requeuing.value = false; },
            onSuccess: () => reloadNow(),
        },
    );
}
</script>

<template>
    <Head :title="`Origins · ${sample.display_label}`" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader compact :title="sample.display_label" :eyebrow="`Sample #${sample.id} · Origins`">
                <template #titleBefore>
                    <SampleAvatar
                        :photo-url="sample.photoUrl || ''"
                        :gender="sample.effective_gender || ''"
                        :alt="sample.display_label"
                    />
                </template>

                <template #subtitle>
                    <Link :href="route('dna.matches', sample.id)" class="hover:underline">
                        Matches
                    </Link>
                    <span v-if="sample.person_id" class="text-paper-400">·</span>
                    <Link
                        v-if="sample.person_id"
                        :href="route('people.show', sample.person_id)"
                        class="hover:underline"
                    >
                        {{ sample.person_name }}
                    </Link>
                </template>

                <template #actions>
                    <button
                        v-if="state === 'loading'"
                        type="button"
                        class="btn-ghost"
                        title="Origins are still loading — auto-refreshes every 5 s; click to refresh now"
                        @click="reloadNow"
                    >
                        <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" />
                            <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                        </svg>
                        Loading…
                    </button>
                    <button
                        v-else-if="status.loadable"
                        type="button"
                        class="btn-ghost"
                        :disabled="requeuing"
                        title="Ask Ancestry again through every matching kit"
                        @click="recheck"
                    >
                        Re-check
                    </button>
                </template>
            </PageHeader>
        </template>

        <!-- Completeness. The headline number is how much of this kit's
             ethnicity we can see at all, which is a genuinely different
             thing from how much has loaded. -->
        <div class="card mb-4 p-4">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <span class="text-2xl font-semibold tabular-nums text-ink-600">
                    {{ status.stored_percent }}%
                </span>
                <span class="text-sm text-sepia-500">
                    of this kit's ethnicity is visible
                </span>
            </div>

            <div class="mt-2.5 h-2 w-full overflow-hidden rounded bg-paper-200" role="img"
                 :aria-label="`${status.stored_percent} per cent visible`">
                <div
                    class="h-full rounded"
                    :class="state === 'complete' ? 'bg-marine-500' : 'bg-wine-500'"
                    :style="{ width: Math.min(100, status.stored_percent) + '%' }"
                ></div>
            </div>

            <p class="mt-2.5 text-sm text-sepia-500">
                <template v-if="state === 'unloadable'">
                    None of your kits match this sample, so Ancestry will not show its
                    ethnicity to you.
                </template>
                <template v-else-if="state === 'loading'">
                    Loading — checked {{ status.eyes_tried }} of {{ status.eyes_total }}
                    {{ status.eyes_total === 1 ? 'kit' : 'kits' }} so far.
                    <template v-if="status.eyes_remaining">
                        Results fill in as each one is asked.
                    </template>
                </template>
                <template v-else-if="state === 'complete'">
                    Complete — this kit shows its full ethnicity estimate.
                </template>
                <template v-else>
                    This kit only shows ethnicity it has <em>in common</em> with whoever is
                    looking, so a single view is never the whole picture. The
                    {{ status.stored_percent }}% above is the union of what all
                    {{ status.eyes_total }} of your matching
                    {{ status.eyes_total === 1 ? 'kit' : 'kits' }} could see; the remaining
                    {{ hiddenPercent }}% is in regions none of them share.
                </template>
            </p>
        </div>

        <!-- Ranked bar list. One hue throughout: the region name carries
             identity, the bar carries magnitude. -->
        <div class="card overflow-hidden">
            <div class="border-b border-paper-300 bg-paper-100 px-4 py-2.5">
                <h2 class="text-sm font-medium text-ink-500">
                    Regions
                    <span v-if="regions.length" class="ml-1 font-normal text-sepia-500">
                        {{ regions.length }}
                    </span>
                </h2>
            </div>

            <div v-if="!regions.length" class="empty-cell">
                <template v-if="state === 'loading'">Waiting for the first result…</template>
                <template v-else-if="state === 'unloadable'">Nothing to show.</template>
                <template v-else>
                    Ancestry returned no ethnicity for this kit — its results may be
                    withheld, or not finished processing.
                </template>
            </div>

            <div v-else class="divide-y divide-paper-300">
                <section v-for="g in groups" :key="g.key" class="px-4 py-3">
                    <div class="mb-2 flex max-w-2xl items-baseline justify-between gap-3">
                        <h3 class="text-xs font-medium uppercase tracking-eyebrow text-sepia-500">
                            {{ g.name }}
                        </h3>
                        <span class="text-xs tabular-nums text-sepia-500">{{ g.total }}%</span>
                    </div>

                    <!-- Capped width: a bar stranded at the far edge of a wide
                         screen is unreadable against its own label. -->
                    <div class="max-w-2xl space-y-1.5">
                        <div
                            v-for="r in g.rows"
                            :key="r.region_key"
                            class="grid grid-cols-[minmax(0,14rem)_minmax(0,1fr)_2.75rem] items-center gap-3"
                            :title="`${r.region_name} — ${r.percentage}%`"
                        >
                            <span class="truncate text-sm text-ink-500">{{ r.region_name }}</span>
                            <span class="h-1.5 overflow-hidden rounded bg-paper-200">
                                <span
                                    class="block h-full rounded bg-wine-500"
                                    :style="{ width: (r.percentage / maxPercent * 100) + '%' }"
                                ></span>
                            </span>
                            <span class="text-right text-sm tabular-nums text-ink-400">
                                {{ r.percentage }}%
                            </span>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <p v-if="status.loaded" class="mt-3 text-xs text-sepia-500">
            Last loaded {{ status.loaded }}<template v-if="status.eyes_total">
            · {{ status.eyes_tried }} of {{ status.eyes_total }} matching
            {{ status.eyes_total === 1 ? 'kit' : 'kits' }} asked</template>
        </p>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import f3 from 'family-chart';
import 'family-chart/styles/family-chart.css';

const props = defineProps({
    person: { type: Object, required: true },
    tree: { type: Object, required: true },
});

const chartContainer = ref(null);

// Descendant fan-out is unbounded (a prolific ancestor can have hundreds of
// great-grandchildren), so it defaults shallow. Ancestor fan-out is bounded
// by 2 parents/generation, so it defaults to whatever depth was loaded.
const ancestryDepth = ref(props.tree.ancestor_depth);
const progenyDepth = ref(Math.min(2, props.tree.descendant_depth));

let chart = null;

function applyAncestryDepth(n) {
    ancestryDepth.value = Math.max(1, Math.min(props.tree.ancestor_depth, n));
    chart.setAncestryDepth(ancestryDepth.value).updateTree();
}

function applyProgenyDepth(n) {
    progenyDepth.value = Math.max(1, Math.min(props.tree.descendant_depth, n));
    chart.setProgenyDepth(progenyDepth.value).updateTree();
}

onMounted(() => {
    chart = f3.createChart(chartContainer.value, props.tree.people)
        .setTransitionTime(300)
        .setCardXSpacing(260)
        .setCardYSpacing(150)
        .setOrientationVertical()
        .setShowSiblingsOfMain(true)
        .setDuplicateBranchToggle(true)
        .setSingleParentEmptyCard(false, { label: '' })
        .setAncestryDepth(ancestryDepth.value)
        .setProgenyDepth(progenyDepth.value);

    function handleCardClick(e, d) {
        const personId = d?.data?.data?._person_id ?? d?.data?._person_id;
        if ((e.ctrlKey || e.metaKey) && personId) {
            e.preventDefault();
            e.stopPropagation();
            router.visit(route('people.show', personId));
            return;
        }
        chart.store.updateMainId(d.data.id);
        chart.store.updateTree({});
    }

    chart.setCard(f3.cardHtml)
        .setCardDisplay([['first name'], ['birthday']])
        .setCardDim({ w: 220, h: 70 })
        .setMiniTree(true)
        .setStyle('rect')
        .setOnCardClick(handleCardClick);

    chart.updateMainId(props.tree.focus_id);
    chart.updateTree({ initial: true });
});
</script>

<template>
    <Head :title="`Family tree · ${person.display_label}`" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader
                compact
                :title="person.display_label"
                :eyebrow="`Person #${person.id} · family tree`"
                :subtitle="`${tree.people.length} people loaded · click a card to re-center · ctrl-click for details`"
            >
                <template #actions>
                    <div class="flex items-center gap-1 rounded-md border border-paper-300 bg-paper-50 px-2 py-1 text-xs text-sepia-500">
                        <span>Ancestors</span>
                        <button
                            type="button"
                            class="flex h-5 w-5 items-center justify-center rounded text-ink-400 transition-colors hover:bg-paper-200 hover:text-ink-600 disabled:opacity-35 disabled:hover:bg-transparent"
                            :disabled="ancestryDepth <= 1"
                            @click="applyAncestryDepth(ancestryDepth - 1)"
                        >−</button>
                        <span class="w-8 text-center tabular-nums text-ink-500">{{ ancestryDepth }}/{{ tree.ancestor_depth }}</span>
                        <button
                            type="button"
                            class="flex h-5 w-5 items-center justify-center rounded text-ink-400 transition-colors hover:bg-paper-200 hover:text-ink-600 disabled:opacity-35 disabled:hover:bg-transparent"
                            :disabled="ancestryDepth >= tree.ancestor_depth"
                            @click="applyAncestryDepth(ancestryDepth + 1)"
                        >+</button>
                    </div>
                    <div class="flex items-center gap-1 rounded-md border border-paper-300 bg-paper-50 px-2 py-1 text-xs text-sepia-500">
                        <span>Descendants</span>
                        <button
                            type="button"
                            class="flex h-5 w-5 items-center justify-center rounded text-ink-400 transition-colors hover:bg-paper-200 hover:text-ink-600 disabled:opacity-35 disabled:hover:bg-transparent"
                            :disabled="progenyDepth <= 1"
                            @click="applyProgenyDepth(progenyDepth - 1)"
                        >−</button>
                        <span class="w-8 text-center tabular-nums text-ink-500">{{ progenyDepth }}/{{ tree.descendant_depth }}</span>
                        <button
                            type="button"
                            class="flex h-5 w-5 items-center justify-center rounded text-ink-400 transition-colors hover:bg-paper-200 hover:text-ink-600 disabled:opacity-35 disabled:hover:bg-transparent"
                            :disabled="progenyDepth >= tree.descendant_depth"
                            @click="applyProgenyDepth(progenyDepth + 1)"
                        >+</button>
                    </div>
                    <Link :href="route('people.show', person.id)" class="btn-ghost">
                        ← {{ person.display_label }}
                    </Link>
                </template>
            </PageHeader>
        </template>

        <div class="card overflow-hidden">
            <div
                ref="chartContainer"
                class="f3"
                style="width: 100%; height: 78vh; background: var(--paper-50, #faf7f1);"
            ></div>
        </div>
    </AuthenticatedLayout>
</template>

<style>
/* Darken the connector lines — f3 sets stroke="#fff" inline; CSS wins. */
.f3 svg.main_svg .links_view path.link {
    stroke: #57534e;
    stroke-width: 1.5;
}
</style>

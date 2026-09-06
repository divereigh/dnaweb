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

// Ancestor fan-out is bounded (2 parents/generation), so a global depth
// stepper works fine there. Descendant fan-out is not bounded — a single
// prolific ancestor can have hundreds of great-grandchildren — so instead of
// a global depth, each card gets its own arrow that reveals just that
// person's children. Expanding one branch never widens the rest of the tree.
const ancestryDepth = ref(props.tree.ancestor_depth);
const expandedChildrenIds = new Set([props.tree.focus_id]);

let chart = null;

function applyAncestryDepth(n) {
    ancestryDepth.value = Math.max(1, Math.min(props.tree.ancestor_depth, n));
    chart.setAncestryDepth(ancestryDepth.value).updateTree();
}

// f3 calls this per hierarchy side (ancestry / progeny) with the d3 root
// node for that side; deleting `.children` here is how we hide a branch
// without touching the underlying data.
function pruneCollapsedChildren(node) {
    if (!node.children) {
        return;
    }
    if (!expandedChildrenIds.has(node.data.id)) {
        delete node.children;
        return;
    }
    node.children.forEach(pruneCollapsedChildren);
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
        .setModifyTreeHierarchy((root, is_ancestry) => {
            if (!is_ancestry) {
                pruneCollapsedChildren(root);
            }
        });

    function toggleChildren(id) {
        if (expandedChildrenIds.has(id)) {
            expandedChildrenIds.delete(id);
        } else {
            expandedChildrenIds.add(id);
        }
        chart.store.updateTree({});
    }

    function handleCardClick(e, d) {
        const toggle = e.target.closest('.f3-children-toggle');
        if (toggle) {
            e.preventDefault();
            e.stopPropagation();
            toggleChildren(toggle.dataset.expandId);
            return;
        }
        const personId = d?.data?.data?._person_id ?? d?.data?._person_id;
        if ((e.ctrlKey || e.metaKey) && personId) {
            e.preventDefault();
            e.stopPropagation();
            router.visit(route('people.show', personId));
            return;
        }
        expandedChildrenIds.add(d.data.id);
        chart.store.updateMainId(d.data.id);
        chart.store.updateTree({});
    }

    // Runs after f3 renders each card's default HTML; append a small
    // expand/collapse arrow when the person has children, rather than
    // replacing the card markup ourselves.
    function handleCardUpdate(d) {
        const cardEl = this.querySelector('.card');
        if (!cardEl) {
            return;
        }
        cardEl.querySelector('.f3-children-toggle')?.remove();
        if (d.is_ancestry) {
            return;
        }
        const childIds = d.data.rels?.children || [];
        if (!childIds.length) {
            return;
        }
        const id = d.data.id;
        const expanded = expandedChildrenIds.has(id);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'f3-children-toggle';
        btn.dataset.expandId = id;
        btn.title = expanded ? 'Hide children' : `Show children (${childIds.length})`;
        btn.textContent = expanded ? '▾' : '▸';
        cardEl.appendChild(btn);
    }

    chart.setCard(f3.cardHtml)
        .setCardDisplay([['first name'], ['birthday']])
        .setCardDim({ w: 220, h: 70 })
        .setMiniTree(true)
        .setStyle('rect')
        .setOnCardClick(handleCardClick)
        .setOnCardUpdate(handleCardUpdate);

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
                :subtitle="`${tree.people.length} people loaded · click a card to re-center · ▸ shows children · ctrl-click for details`"
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

/* Per-card expand/collapse arrow for that person's children. */
.f3 .card {
    position: relative;
}
.f3 .f3-children-toggle {
    position: absolute;
    left: 50%;
    bottom: -11px;
    transform: translateX(-50%);
    width: 22px;
    height: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    background: #faf7f1;
    border: 1px solid #cbbfa8;
    color: #57534e;
    font-size: 11px;
    line-height: 1;
    cursor: pointer;
    z-index: 2;
}
.f3 .f3-children-toggle:hover {
    background: #efe6d5;
    border-color: #57534e;
}
</style>

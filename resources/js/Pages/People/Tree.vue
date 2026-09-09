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

// The server hands us a whole neighbourhood (6 generations up, 4 down, plus
// siblings and spouses) — several hundred people for a well-researched line.
// Drawing all of it at once is unreadable, so the page opens on the Ancestry
// "family view" shape: the focus, their spouses, and one generation of
// parents. Everything else is behind a per-card arrow, and nothing is fetched
// again when one is clicked — the data is already here, we only decide what
// the layout is allowed to see.
//
// Three sets/flags below are the whole of that state:
//   expandedParentIds   — people whose parents are drawn (▴ above the card)
//   expandedChildrenIds — people whose children are drawn (▾ below the card)
//   showSiblings        — siblings of the *main* person (▸ beside the card)
//
// Parents and children are per-person because fan-out is per-person: opening
// one branch must never widen the rest of the tree. Siblings are a single
// flag because family-chart only ever renders siblings of the main card.
const INITIAL_ANCESTOR_LEVELS = 1;

const expandedParentIds = new Set();
const expandedChildrenIds = new Set();
let showSiblings = false;

// The header's generation counter mirrors expandedParentIds, which is a plain
// Set because the d3 callbacks that read it don't want reactivity.
const ancestorLevels = ref(INITIAL_ANCESTOR_LEVELS);

const byId = new Map(props.tree.people.map((p) => [p.id, p]));
const relsOf = (id) => byId.get(id)?.rels ?? { parents: [], children: [], spouses: [] };

let chart = null;

// The header lives outside the chart; onMounted fills these in once the two
// bulk controls have a chart to act on.
let stepAncestors = () => {};
let resetView = () => {};

/**
 * Ids of everyone whose parents must be open for `levels` generations of
 * ancestors to be visible above `id`. Walks the payload rather than the
 * rendered tree so it works before the first draw.
 */
function ancestorsWithin(id, levels) {
    const out = new Set();
    const seen = new Set();
    let frontier = [id];
    for (let gen = 0; gen < levels && frontier.length; gen++) {
        const next = [];
        for (const pid of frontier) {
            if (seen.has(pid)) {
                continue; // cyclical data would otherwise spin here
            }
            seen.add(pid);
            out.add(pid);
            next.push(...relsOf(pid).parents);
        }
        frontier = next;
    }

    return out;
}

/**
 * How many generations of ancestors are actually open above `id`. The header
 * stepper sets a uniform depth, but the per-card arrows can leave the tree
 * ragged, so the number shown is derived rather than remembered.
 */
function openAncestorLevels(id) {
    let levels = 0;
    const seen = new Set();
    let frontier = [id];
    for (let gen = 0; gen < props.tree.ancestor_depth && frontier.length; gen++) {
        const next = [];
        let anyOpen = false;
        for (const pid of frontier) {
            if (seen.has(pid) || !expandedParentIds.has(pid)) {
                continue;
            }
            seen.add(pid);
            anyOpen = true;
            next.push(...relsOf(pid).parents);
        }
        if (!anyOpen) {
            break;
        }
        levels = gen + 1;
        frontier = next;
    }

    return levels;
}

/** Everyone sharing at least one parent with `id`, half-siblings included. */
function siblingIdsOf(id) {
    const out = new Set();
    for (const parentId of relsOf(id).parents) {
        for (const childId of relsOf(parentId).children) {
            if (childId !== id) {
                out.add(childId);
            }
        }
    }

    return out;
}

// f3 calls this per hierarchy side with the d3 root node for that side. On
// both sides `.children` is the next rank away from the main person — the
// parents going up, the children going down — so deleting it is how a branch
// is hidden without touching the underlying data.
function pruneCollapsed(node, expanded) {
    if (!node.children) {
        return;
    }
    if (!expanded.has(node.data.id)) {
        delete node.children;

        return;
    }
    node.children.forEach((child) => pruneCollapsed(child, expanded));
}

onMounted(() => {
    const mainId = () => chart.store.getMainId();

    // family-chart hangs siblings off the main person's parent row and throws
    // outright if that row isn't drawn, so the two are coupled: asking for
    // siblings opens the parents, and closing the parents puts the siblings
    // away again.
    function syncSiblings() {
        if (showSiblings && !expandedParentIds.has(mainId())) {
            showSiblings = false;
        }
        chart.setShowSiblingsOfMain(showSiblings);
    }

    function redraw() {
        syncSiblings();
        ancestorLevels.value = openAncestorLevels(mainId());
        chart.store.updateTree({});
    }

    function applyAncestorLevels(n) {
        const levels = Math.max(0, Math.min(props.tree.ancestor_depth, n));
        expandedParentIds.clear();
        for (const id of ancestorsWithin(mainId(), levels)) {
            expandedParentIds.add(id);
        }
        redraw();
    }

    /** Back to the shape the page opened with, around whoever is main now. */
    function resetAround(id) {
        expandedChildrenIds.clear();
        showSiblings = false;
        expandedParentIds.clear();
        for (const pid of ancestorsWithin(id, INITIAL_ANCESTOR_LEVELS)) {
            expandedParentIds.add(pid);
        }
        redraw();
    }

    chart = f3
        .createChart(chartContainer.value, props.tree.people)
        .setTransitionTime(300)
        .setCardXSpacing(260)
        .setCardYSpacing(150)
        .setOrientationVertical()
        .setShowSiblingsOfMain(false)
        .setDuplicateBranchToggle(true)
        .setSingleParentEmptyCard(false, { label: '' })
        // Depth is enforced by the pruning below, not by f3 — leave its own
        // limit at the full extent of what the server sent.
        .setAncestryDepth(props.tree.ancestor_depth)
        .setModifyTreeHierarchy((root, is_ancestry) => {
            pruneCollapsed(root, is_ancestry ? expandedParentIds : expandedChildrenIds);
        });

    function toggle(set, id) {
        if (set.has(id)) {
            set.delete(id);
        } else {
            set.add(id);
        }
        redraw();
    }

    function toggleSiblings() {
        showSiblings = !showSiblings;
        if (showSiblings) {
            expandedParentIds.add(mainId()); // siblings need the parent row
        }
        redraw();
    }

    function handleCardClick(e, d) {
        const toggleEl = e.target.closest('.f3-rel-toggle');
        if (toggleEl) {
            e.preventDefault();
            e.stopPropagation();
            const id = toggleEl.dataset.relId;
            if (toggleEl.dataset.rel === 'parents') {
                toggle(expandedParentIds, id);
            } else if (toggleEl.dataset.rel === 'children') {
                toggle(expandedChildrenIds, id);
            } else {
                toggleSiblings();
            }

            return;
        }
        const personId = d?.data?.data?._person_id ?? d?.data?._person_id;
        if ((e.ctrlKey || e.metaKey) && personId) {
            e.preventDefault();
            e.stopPropagation();
            router.visit(route('people.show', personId));

            return;
        }
        // Re-centring lands on the same shape the page opened with, so moving
        // around the tree doesn't drag whatever was expanded on the way there
        // along with it — a branch opened four generations up is rarely what
        // you want hanging off the person you just clicked.
        chart.store.updateMainId(d.data.id);
        resetAround(d.data.id);
    }

    // Runs after f3 renders each card's default HTML; we append arrows rather
    // than replacing the card markup ourselves.
    function handleCardUpdate(d) {
        const cardEl = this.querySelector('.card');
        if (!cardEl) {
            return;
        }
        cardEl.querySelectorAll('.f3-rel-toggle').forEach((el) => el.remove());

        // Spouse and sibling cards are placed beside the layout rather than
        // being nodes in it, so their own relatives have nowhere to render.
        if (d.added || d.sibling) {
            return;
        }
        const id = d.data.id;
        const rels = d.data.rels || {};
        const isMain = !!d.data.main;

        // Parents hang off the ancestry side, which the main card roots.
        if ((d.is_ancestry || isMain) && (rels.parents || []).length) {
            const open = expandedParentIds.has(id);
            cardEl.appendChild(relToggle({
                rel: 'parents',
                id,
                side: 'up',
                open,
                glyph: open ? '▾' : '▴',
                title: open ? 'Hide parents' : `Show parents (${rels.parents.length})`,
            }));
        }

        // ...and children off the progeny side, which it also roots.
        if (!d.is_ancestry && (rels.children || []).length) {
            const open = expandedChildrenIds.has(id);
            cardEl.appendChild(relToggle({
                rel: 'children',
                id,
                side: 'down',
                open,
                glyph: open ? '▴' : '▾',
                title: open ? 'Hide children' : `Show children (${rels.children.length})`,
            }));
        }

        // Siblings are only ever drawn for the main person.
        if (isMain) {
            const count = siblingIdsOf(id).size;
            if (count) {
                cardEl.appendChild(relToggle({
                    rel: 'siblings',
                    id,
                    side: 'side',
                    open: showSiblings,
                    glyph: showSiblings ? '◂' : '▸',
                    title: showSiblings ? 'Hide siblings' : `Show siblings (${count})`,
                }));
            }
        }
    }

    // An open arrow points back at its own card ("fold this away"), which
    // makes the up and down arrows read the same glyph in opposite states —
    // hence the filled variant, so the state is legible without comparing
    // the two ends of the card.
    function relToggle({ rel, id, side, glyph, title, open }) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `f3-rel-toggle f3-rel-toggle--${side}${open ? ' f3-rel-toggle--open' : ''}`;
        btn.dataset.rel = rel;
        btn.dataset.relId = id;
        btn.title = title;
        btn.textContent = glyph;

        return btn;
    }

    chart
        .setCard(f3.cardHtml)
        .setCardDisplay([['first name'], ['birthday']])
        .setCardDim({ w: 220, h: 70 })
        // The arrows say what is hidden, and say it more clearly than f3's
        // mini-tree stub does — two indicators for one fact is clutter.
        .setMiniTree(false)
        .setStyle('rect')
        .setOnCardClick(handleCardClick)
        .setOnCardUpdate(handleCardUpdate);

    chart.updateMainId(props.tree.focus_id);
    for (const id of ancestorsWithin(props.tree.focus_id, INITIAL_ANCESTOR_LEVELS)) {
        expandedParentIds.add(id);
    }
    ancestorLevels.value = openAncestorLevels(props.tree.focus_id);
    chart.updateTree({ initial: true });

    stepAncestors = applyAncestorLevels;
    resetView = () => resetAround(mainId());
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
                :subtitle="`${tree.people.length} people available · ▴ parents · ▾ children · ▸ siblings · click a card to re-centre · ctrl-click for details`"
            >
                <template #actions>
                    <div class="flex items-center gap-1 rounded-md border border-paper-300 bg-paper-50 px-2 py-1 text-xs text-sepia-500">
                        <span>Ancestors</span>
                        <button
                            type="button"
                            class="flex h-5 w-5 items-center justify-center rounded text-ink-400 transition-colors hover:bg-paper-200 hover:text-ink-600 disabled:opacity-35 disabled:hover:bg-transparent"
                            :disabled="ancestorLevels <= 0"
                            @click="stepAncestors(ancestorLevels - 1)"
                        >−</button>
                        <span class="w-8 text-center tabular-nums text-ink-500">{{ ancestorLevels }}/{{ tree.ancestor_depth }}</span>
                        <button
                            type="button"
                            class="flex h-5 w-5 items-center justify-center rounded text-ink-400 transition-colors hover:bg-paper-200 hover:text-ink-600 disabled:opacity-35 disabled:hover:bg-transparent"
                            :disabled="ancestorLevels >= tree.ancestor_depth"
                            @click="stepAncestors(ancestorLevels + 1)"
                        >+</button>
                    </div>
                    <button type="button" class="btn-ghost" @click="resetView()">Collapse</button>
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

/* Expand/collapse arrows: above for parents, below for children, beside for
   siblings — the three directions a card can grow in. */
.f3 .card {
    position: relative;
}
.f3 .f3-rel-toggle {
    position: absolute;
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
.f3 .f3-rel-toggle:hover {
    background: #efe6d5;
    border-color: #57534e;
}
.f3 .f3-rel-toggle--open {
    background: #57534e;
    border-color: #57534e;
    color: #faf7f1;
}
.f3 .f3-rel-toggle--open:hover {
    background: #3f3b37;
}
.f3 .f3-rel-toggle--up {
    left: 50%;
    top: -11px;
    transform: translateX(-50%);
}
.f3 .f3-rel-toggle--down {
    left: 50%;
    bottom: -11px;
    transform: translateX(-50%);
}
.f3 .f3-rel-toggle--side {
    right: -11px;
    top: 50%;
    transform: translateY(-50%);
}
</style>

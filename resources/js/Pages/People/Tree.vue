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

// The page opens on the Ancestry "family view" shape — the focus, their
// spouses, and one generation of parents — and grows only where asked, from
// three arrows on the cards:
//
//   expandedParentIds   — people whose parents are drawn (▴ above the card)
//   expandedChildrenIds — people whose children are drawn (▾ below the card)
//   showSiblings        — siblings of the *main* person (▸ beside the card)
//
// Parents and children are per-person because fan-out is per-person: opening
// one branch must never widen the rest of the tree. Siblings are a single
// flag because family-chart only ever renders siblings of the main card.
//
// The controller hands us a read-ahead buffer (six generations up, four down)
// rather than a limit: an arrow whose relatives aren't in it fetches them from
// people.tree.expand and merges them in, so the tree has no fixed depth and
// walking up past the sixth generation is just more clicking.
const INITIAL_ANCESTOR_LEVELS = 1;

const expandedParentIds = new Set();
const expandedChildrenIds = new Set();
let showSiblings = false;

// Header state. The three above are plain Sets/flags because the d3 callbacks
// that read them don't want reactivity; these are the read-outs.
const ancestorLevels = ref(0);
const canDeepen = ref(true);
const peopleHeld = ref(0);
const loading = ref(false);
const loadError = ref('');

// f3's own data array. We push into it as more of the tree arrives, so the
// chart keeps drawing from the same array it was created with.
const data = [];
const byId = new Map();

// The server sends each person's *complete* relations. What f3 is allowed to
// see is the subset naming people we actually hold (`datum.rels`, rewritten in
// ingest); this map keeps the full lists, which is what the arrows count and
// what tells us whether clicking one needs a fetch first.
const rawRels = new Map();

const held = (id) => byId.has(id);
const relsOf = (id) => rawRels.get(id) ?? { parents: [], children: [], spouses: [] };

let chart = null;

// The header lives outside the chart; onMounted fills these in once the
// bulk controls have a chart to act on.
let stepAncestors = () => {};
let resetView = () => {};

/**
 * Merge a batch of people into the chart's data. Returns how many were new;
 * people we already hold are left as the same object, since f3's tree nodes
 * point at them, and only their relations are refreshed.
 */
function ingest(datums) {
    let added = 0;
    for (const d of datums) {
        rawRels.set(d.id, {
            parents: d.rels?.parents ?? [],
            children: d.rels?.children ?? [],
            spouses: d.rels?.spouses ?? [],
        });
        if (!byId.has(d.id)) {
            byId.set(d.id, d);
            data.push(d);
            added++;
        }
    }
    // Re-narrow everyone's rels to people we hold. f3 dereferences a spouse id
    // without checking it resolves (setupSpouses), so a dangling one is a
    // crash rather than a gap — and a dangling parent or child would quietly
    // distort the layout.
    for (const d of data) {
        const raw = rawRels.get(d.id);
        d.rels = {
            parents: raw.parents.filter(held),
            children: raw.children.filter(held),
            spouses: raw.spouses.filter(held),
        };
    }
    peopleHeld.value = data.length;

    return added;
}

async function fetchExpand(id, rel, levels = 1) {
    loading.value = true;
    loadError.value = '';
    try {
        const url = new URL(route('people.tree.expand', id), window.location.origin);
        url.searchParams.set('rel', rel);
        if (levels > 1) {
            url.searchParams.set('levels', String(levels));
        }
        const res = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }

        return ingest((await res.json()).people ?? []);
    } catch (e) {
        loadError.value = 'Could not load more of the tree';

        return 0;
    } finally {
        loading.value = false;
    }
}

/** Fetch whatever `rel` needs, if we don't already hold all of it. */
async function ensureLoaded(rel, id) {
    if (rel === 'siblings') {
        // Siblings are found through the parents, so they have to come first.
        await ensureLoaded('parents', id);
        if ([...siblingIdsOf(id)].every(held)) {
            return;
        }
        await fetchExpand(id, 'siblings');

        return;
    }
    if (relsOf(id)[rel].every(held)) {
        return;
    }
    await fetchExpand(id, rel);
}

/**
 * Ids of everyone whose parents must be open for `levels` generations of
 * ancestors to be visible above `id`.
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
    while (frontier.length) {
        const next = [];
        for (const pid of frontier) {
            if (seen.has(pid) || !expandedParentIds.has(pid)) {
                continue;
            }
            seen.add(pid);
            next.push(...relsOf(pid).parents);
        }
        if (!next.length) {
            break;
        }
        levels++;
        frontier = next;
    }

    return levels;
}

/** True while some card above `id` still has parents left to open. */
function canDeepenAncestors(id) {
    const seen = new Set();
    let frontier = [id];
    while (frontier.length) {
        const next = [];
        for (const pid of frontier) {
            if (seen.has(pid)) {
                continue;
            }
            seen.add(pid);
            const parents = relsOf(pid).parents;
            if (!expandedParentIds.has(pid)) {
                if (parents.length) {
                    return true;
                }

                continue;
            }
            next.push(...parents);
        }
        frontier = next;
    }

    return false;
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
    let busy = false;

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

    // 'inherit' leaves the viewport exactly where it was, which is what an
    // expand arrow wants — refitting on every click makes a branch hard to
    // follow, since the thing you just opened jumps somewhere else. The bulk
    // controls and re-centring do refit, because there the whole picture
    // changed and there is nothing to keep your place in.
    function redraw(tree_position = 'fit') {
        syncSiblings();
        ancestorLevels.value = openAncestorLevels(mainId());
        canDeepen.value = canDeepenAncestors(mainId());
        chart.store.updateTree({ tree_position });
    }

    async function applyAncestorLevels(n) {
        if (busy) {
            return;
        }
        busy = true;
        try {
            const levels = Math.max(0, n);
            if (levels > 0) {
                await fetchExpand(mainId(), 'parents', levels);
            }
            expandedParentIds.clear();
            for (const id of ancestorsWithin(mainId(), levels)) {
                expandedParentIds.add(id);
            }
            redraw('fit');
        } finally {
            busy = false;
        }
    }

    /** Back to the shape the page opened with, around whoever is main now. */
    async function resetAround(id) {
        expandedChildrenIds.clear();
        expandedParentIds.clear();
        showSiblings = false;
        await ensureLoaded('parents', id);
        for (const pid of ancestorsWithin(id, INITIAL_ANCESTOR_LEVELS)) {
            expandedParentIds.add(pid);
        }
        redraw('fit');
    }

    ingest(props.tree.people);

    chart = f3
        .createChart(chartContainer.value, data)
        .setTransitionTime(300)
        .setCardXSpacing(260)
        .setCardYSpacing(150)
        .setOrientationVertical()
        .setShowSiblingsOfMain(false)
        .setDuplicateBranchToggle(true)
        .setSingleParentEmptyCard(false, { label: '' })
        // No setAncestryDepth/setProgenyDepth: with data arriving on demand
        // there is no ceiling to enforce, and the pruning below already
        // decides what the layout may see.
        .setModifyTreeHierarchy((root, is_ancestry) => {
            pruneCollapsed(root, is_ancestry ? expandedParentIds : expandedChildrenIds);
        });

    async function toggleRel(rel, id) {
        if (busy) {
            return;
        }
        busy = true;
        try {
            if (rel === 'siblings') {
                if (!showSiblings) {
                    await ensureLoaded('siblings', id);
                    expandedParentIds.add(id); // siblings need the parent row
                }
                showSiblings = !showSiblings;
            } else {
                const set = rel === 'parents' ? expandedParentIds : expandedChildrenIds;
                if (set.has(id)) {
                    set.delete(id);
                } else {
                    await ensureLoaded(rel, id);
                    set.add(id);
                }
            }
            redraw('inherit');
        } finally {
            busy = false;
        }
    }

    function handleCardClick(e, d) {
        const toggleEl = e.target.closest('.f3-rel-toggle');
        if (toggleEl) {
            e.preventDefault();
            e.stopPropagation();
            toggleRel(toggleEl.dataset.rel, toggleEl.dataset.relId);

            return;
        }
        const personId = d?.data?.data?._person_id ?? d?.data?._person_id;
        if ((e.ctrlKey || e.metaKey) && personId) {
            e.preventDefault();
            e.stopPropagation();
            router.visit(route('people.show', personId));

            return;
        }
        if (busy) {
            return;
        }
        // Re-centring lands on the same shape the page opened with, so moving
        // around the tree doesn't drag whatever was expanded on the way there
        // along with it — a branch opened four generations up is rarely what
        // you want hanging off the person you just clicked.
        busy = true;
        chart.store.updateMainId(d.data.id);
        resetAround(d.data.id).finally(() => {
            busy = false;
        });
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
        const raw = relsOf(id);
        const isMain = !!d.data.main;

        // Parents hang off the ancestry side, which the main card roots.
        if ((d.is_ancestry || isMain) && raw.parents.length) {
            const open = expandedParentIds.has(id);
            cardEl.appendChild(relToggle({
                rel: 'parents',
                id,
                side: 'up',
                open,
                glyph: open ? '▾' : '▴',
                title: open ? 'Hide parents' : `Show parents (${raw.parents.length})`,
            }));
        }

        // ...and children off the progeny side, which it also roots.
        if (!d.is_ancestry && raw.children.length) {
            const open = expandedChildrenIds.has(id);
            cardEl.appendChild(relToggle({
                rel: 'children',
                id,
                side: 'down',
                open,
                glyph: open ? '▴' : '▾',
                title: open ? 'Hide children' : `Show children (${raw.children.length})`,
            }));
        }

        // Siblings are only ever drawn for the main person. Their number is
        // only known once we hold the parents — until then the arrow says
        // there may be some rather than pretending to a count.
        if (isMain && raw.parents.length) {
            const count = raw.parents.every(held) ? siblingIdsOf(id).size : null;
            if (count === null || count > 0) {
                cardEl.appendChild(relToggle({
                    rel: 'siblings',
                    id,
                    side: 'side',
                    open: showSiblings,
                    glyph: showSiblings ? '◂' : '▸',
                    title: showSiblings ? 'Hide siblings' : (count === null ? 'Show siblings' : `Show siblings (${count})`),
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
    canDeepen.value = canDeepenAncestors(props.tree.focus_id);
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
                :subtitle="`${peopleHeld} people loaded · ▴ parents · ▾ children · ▸ siblings · click a card to re-centre · ctrl-click for details`"
            >
                <template #actions>
                    <span v-if="loadError" class="text-xs text-wine-600">{{ loadError }}</span>
                    <span v-else-if="loading" class="text-xs text-sepia-500">Loading…</span>
                    <div class="flex items-center gap-1 rounded-md border border-paper-300 bg-paper-50 px-2 py-1 text-xs text-sepia-500">
                        <span>Ancestors</span>
                        <button
                            type="button"
                            class="flex h-5 w-5 items-center justify-center rounded text-ink-400 transition-colors hover:bg-paper-200 hover:text-ink-600 disabled:opacity-35 disabled:hover:bg-transparent"
                            :disabled="ancestorLevels <= 0 || loading"
                            @click="stepAncestors(ancestorLevels - 1)"
                        >−</button>
                        <span class="w-6 text-center tabular-nums text-ink-500">{{ ancestorLevels }}</span>
                        <button
                            type="button"
                            class="flex h-5 w-5 items-center justify-center rounded text-ink-400 transition-colors hover:bg-paper-200 hover:text-ink-600 disabled:opacity-35 disabled:hover:bg-transparent"
                            :disabled="!canDeepen || loading"
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

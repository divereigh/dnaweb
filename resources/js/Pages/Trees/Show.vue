<script setup>
import { computed, provide, reactive, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import TreeBranch from '@/Components/App/TreeBranch.vue';
import { HEAT_BANDS } from '@/lib/heat';

// The web rendering of ~/ancestry-program/print-tree. The server has already
// done the layout (App\Services\TreeStructureService); this page's own work is
// deciding how much of a 4,000-row forest to put on screen at once, and
// painting the shared-DNA heat map over it.
const props = defineProps({
    tree: { type: Object, required: true },
    counts: { type: Object, required: true },
    roots: { type: Array, required: true },
    unplaced: { type: Array, required: true },
    warnings: { type: Array, required: true },
});

const showWarnings = ref(false);
const showUnplaced = ref(false);

// Above this many rows the page opens with only the top two generations
// showing. Big trees are mostly repetition of a shape you can already see,
// and 4,000 rows of it is slower to paint than it is useful.
const AUTO_COLLAPSE_ABOVE = 600;

/**
 * Give every rendered row a key. A person with two parents in the tree is
 * drawn twice — once in full, once as a back-reference — so the node number
 * alone does not identify a row; the path to it does.
 */
function withKeys(nodes, prefix) {
    return nodes.map((n) => {
        const key = `${prefix}/${n.n}`;
        return { ...n, key, children: withKeys(n.children, key) };
    });
}

const keyed = computed(() => withKeys(props.roots, ''));

const totalRows = computed(() => {
    let n = 0;
    const walk = (list) => list.forEach((x) => { n += 1; walk(x.children); });
    walk(keyed.value);
    return n;
});

// ---------------------------------------------------------------- collapse

// Open by key rather than by node number, for the same reason keys exist:
// the two copies of a twice-parented person collapse independently.
const open = reactive(new Set());

function expandAll() {
    const walk = (list) => list.forEach((x) => { open.add(x.key); walk(x.children); });
    walk(keyed.value);
}

function collapseAll() {
    open.clear();
}

function seed() {
    open.clear();
    if (totalRows.value <= AUTO_COLLAPSE_ABOVE) {
        expandAll();
    } else {
        keyed.value.forEach((r) => open.add(r.key));
    }
}

watch(keyed, seed, { immediate: true });

// ---------------------------------------------------------------- heat map

const refQuery = ref('');
const heat = ref({ ref: null, cm: {}, candidates: [] });
const heatLoading = ref(false);
const heatError = ref('');
let heatSeq = 0;

const hasHeat = computed(() => !!heat.value.ref);

/**
 * Kits in this tree that nothing has ever compared with the reference — so
 * neither a match nor a zero, just no reading. Only possible when the
 * reference is not an eye; see TreeStructureService::heatmap().
 */
const unknownKits = computed(() =>
    heat.value.ref ? heat.value.ref.kits - 1 - heat.value.ref.compared : 0,
);

async function lookup(q) {
    const term = q.trim();
    if (!term) {
        clearHeat();
        return;
    }
    const seq = ++heatSeq;
    heatLoading.value = true;
    heatError.value = '';
    try {
        const res = await fetch(route('trees.heat', { tree: props.tree.name, q: term }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!res.ok) throw new Error(`Lookup failed (${res.status})`);
        const data = await res.json();
        // A slow reply for an earlier term must not overwrite a newer one.
        if (seq !== heatSeq) return;
        heat.value = data;
        if (data.ref) revealMatches(data.cm);
    } catch (e) {
        if (seq === heatSeq) heatError.value = e.message;
    } finally {
        if (seq === heatSeq) heatLoading.value = false;
    }
}

let debounce;
watch(refQuery, (q) => {
    clearTimeout(debounce);
    debounce = setTimeout(() => lookup(q), 350);
});

function pick(name) {
    refQuery.value = name;
    clearTimeout(debounce);
    lookup(name);
}

function clearHeat() {
    heatSeq += 1;
    refQuery.value = '';
    heat.value = { ref: null, cm: {}, candidates: [] };
    heatError.value = '';
    heatLoading.value = false;
    seed();
}

/**
 * Open just the branches that lead to a match, and leave the rest as they
 * were. On a tree with 1,362 top-level branches, a heat map you have to go
 * hunting for is no use — but expanding everything to find forty matches
 * buries them again.
 */
function revealMatches(cm) {
    const walk = (list, path) => {
        list.forEach((node) => {
            const here = [...path, node.key];
            if (node.dna_sample_id && cm[node.dna_sample_id] != null) {
                path.forEach((k) => open.add(k));
            }
            walk(node.children, here);
        });
    };
    walk(keyed.value, []);
}

provide('treeView', {
    isOpen: (key) => open.has(key),
    toggle: (key) => (open.has(key) ? open.delete(key) : open.add(key)),
    // null means "no reading" — either no reference is chosen, or nothing
    // has ever compared this kit with the reference. A known zero arrives as
    // a real 0 in the map; the server only puts one there when one side of
    // the pair is an eye. See TreeStructureService::heatmap().
    cmFor: (sampleId) => heat.value.cm[sampleId] ?? null,
    isRef: (sampleId) => heat.value.ref?.dna_sample_id === sampleId,
    get hasHeat() {
        return hasHeat.value;
    },
});
</script>

<template>
    <Head :title="`Tree — ${tree.name}`" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader :title="tree.name" eyebrow="Tree">
                <template #titleBefore>
                    <span
                        class="inline-block h-3.5 w-3.5 shrink-0 rounded-full ring-1 ring-inset"
                        :class="tree.colour ? 'ring-black/15' : 'ring-paper-400'"
                        :style="{ backgroundColor: tree.colour || 'transparent' }"
                        :title="tree.colour || 'No colour set'"
                    />
                </template>
                <template #subtitle>
                    <span>{{ counts.people.toLocaleString() }} members</span>
                    <span class="text-sepia-400">·</span>
                    <span>{{ counts.dna.toLocaleString() }} on the DNA line</span>
                    <span class="text-sepia-400">·</span>
                    <span>{{ counts.samples.toLocaleString() }} kits</span>
                    <span class="text-sepia-400">·</span>
                    <span>{{ counts.roots.toLocaleString() }} {{ counts.roots === 1 ? 'branch' : 'branches' }}</span>
                    <span v-if="tree.description" class="text-sepia-400">·</span>
                    <span v-if="tree.description">{{ tree.description }}</span>
                </template>
                <template #actions>
                    <Link :href="route('trees.index')" class="btn-ghost">All trees</Link>
                </template>
            </PageHeader>
        </template>

        <div class="filter-bar mb-4 sm:grid-cols-[minmax(0,26rem)_auto_1fr_auto_auto]">
            <input
                v-model="refQuery"
                type="search"
                placeholder="Compare with… paste a person's name"
                title="Shades every kit in the tree by how much DNA it shares with this person's kit"
                class="text-sm"
            />
            <button
                v-if="refQuery"
                type="button"
                class="btn-ghost"
                @click="clearHeat"
            >Clear</button>
            <span v-else />
            <span class="text-xs text-sepia-500">
                <template v-if="heatLoading">Looking up…</template>
                <template v-else-if="heatError">{{ heatError }}</template>
                <template v-else-if="heat.ref">
                    {{ heat.ref.matches.toLocaleString() }} of
                    {{ heat.ref.kits.toLocaleString() }} kits share DNA with
                    <strong class="font-medium text-ink-500">{{ heat.ref.name }}</strong>
                    <template v-if="unknownKits">
                        · {{ unknownKits.toLocaleString() }} never compared with it
                    </template>
                </template>
                <template v-else-if="heat.candidates.length">Did you mean:</template>
                <template v-else-if="refQuery.trim()">No person of that name has a DNA kit.</template>
            </span>
            <button type="button" class="btn-ghost" @click="expandAll">Expand all</button>
            <button type="button" class="btn-ghost" @click="collapseAll">Collapse all</button>
        </div>

        <div v-if="heat.candidates.length" class="mb-4 flex flex-wrap gap-1.5">
            <button
                v-for="c in heat.candidates"
                :key="c.person_id"
                type="button"
                class="btn-ghost"
                @click="pick(c.name)"
            >
                {{ c.name }}
                <span v-if="c.dna_name && c.dna_name !== c.name" class="text-sepia-500">
                    [{{ c.dna_name }}]
                </span>
            </button>
        </div>

        <div v-if="hasHeat" class="heat-legend card mb-4 px-4 py-2.5">
            <span class="me-1">Shared DNA</span>
            <span
                v-for="b in [...HEAT_BANDS].reverse()"
                :key="b.band"
                data-band
                :class="`heat-${b.band}`"
            >{{ b.label }}</span>
            <span>cM</span>
            <span class="text-sepia-400">·</span>
            <span data-band class="heat-ref">reference</span>
            <span class="text-sepia-400">·</span>
            <span data-band class="tree-tag tree-tag--dna">DNA</span>
            <span>never compared</span>
            <span class="ms-auto">Branches with a match have been opened.</span>
        </div>

        <div v-if="warnings.length" class="mb-4">
            <button
                type="button"
                class="card flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-ink-300 hover:bg-paper-100"
                @click="showWarnings = !showWarnings"
            >
                <span class="font-mono text-xs text-sepia-400">{{ showWarnings ? '▼' : '▶' }}</span>
                <span class="font-medium text-ink-500">{{ warnings.length }}</span>
                {{ warnings.length === 1 ? 'link' : 'links' }} the layout could not keep
                <span class="ms-auto hidden text-xs text-sepia-500 sm:inline">
                    parent links dropped because the birth years cannot both be right
                </span>
            </button>
            <ul v-if="showWarnings" class="card mt-2 divide-y divide-paper-300 text-sm text-ink-300">
                <li v-for="(w, i) in warnings" :key="i" class="px-4 py-2">{{ w }}</li>
            </ul>
        </div>

        <div class="tree-view card px-3 py-3">
            <div v-if="keyed.length" class="tree-head">
                <span class="flex-1">Person</span>
                <span class="w-[5.75rem] flex-none">DNA</span>
                <span class="w-[23rem] flex-none">Other parent</span>
            </div>
            <TreeBranch v-if="keyed.length" :nodes="keyed" />
            <p v-else class="px-2 py-10 text-center text-sm text-sepia-500">
                This tree has no members.
            </p>
        </div>

        <div v-if="unplaced.length" class="mt-4">
            <button
                type="button"
                class="card flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-ink-300 hover:bg-paper-100"
                @click="showUnplaced = !showUnplaced"
            >
                <span class="font-mono text-xs text-sepia-400">{{ showUnplaced ? '▼' : '▶' }}</span>
                <span class="font-medium text-ink-500">{{ unplaced.length }}</span>
                not placed
                <span class="ms-auto hidden text-xs text-sepia-500 sm:inline">
                    members of this tree who appear nowhere above, not even as a partner
                </span>
            </button>
            <ul v-if="showUnplaced" class="card mt-2 divide-y divide-paper-300">
                <li v-for="u in unplaced" :key="u.n" class="flex items-baseline gap-2 px-4 py-2 text-sm">
                    <Link :href="route('people.show', u.person_id)" class="ref-link">{{ u.name }}</Link>
                    <span class="tree-years">({{ u.years }})</span>
                    <Link
                        v-if="u.dna_sample_id"
                        :href="route('dna.matches', u.dna_sample_id)"
                        class="tree-tag tree-tag--dna"
                    >DNA</Link>
                </li>
            </ul>
        </div>
    </AuthenticatedLayout>
</template>

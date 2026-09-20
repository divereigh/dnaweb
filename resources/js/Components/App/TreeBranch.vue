<script setup>
import { inject } from 'vue';
import { Link } from '@inertiajs/vue3';
import { heatClass } from '@/lib/heat';

// One generation of the forest on /tree/{name}. Renders itself for each
// child, so the whole tree is this component all the way down.
defineProps({
    nodes: { type: Array, required: true },
});

// Collapse state and the heat map live on the page, not here: a branch
// several levels down still has to answer "expand all", and threading a
// prop through every generation to do it would be worse than one shared
// store.
const view = inject('treeView');

// The heat band a person's own name is painted with — the same band as their
// chip, so a branch full of close matches reads as one warm block rather than
// a column of chips you have to track back to a name.
function nameClass(node) {
    if (!node.dna_sample_id) return '';
    if (view.isRef(node.dna_sample_id)) return 'heat-ref';
    const cm = view.cmFor(node.dna_sample_id);
    return cm === null ? '' : heatClass(cm);
}
</script>

<script>
export default { name: 'TreeBranch' };
</script>

<template>
    <ul class="tree-list">
        <li v-for="node in nodes" :key="node.key">
            <div
                :id="node.repeat_of ? undefined : `n${node.n}`"
                :class="['tree-row', node.repeat_of ? 'is-repeat' : '']"
            >
                <button
                    v-if="node.children.length"
                    type="button"
                    class="tree-toggle"
                    :title="view.isOpen(node.key) ? 'Collapse' : `Expand (${node.descendants} below)`"
                    @click="view.toggle(node.key)"
                >
                    {{ view.isOpen(node.key) ? '▼' : '▶' }}
                </button>
                <span v-else class="tree-toggle tree-toggle--leaf">•</span>

                <span class="tree-gen" title="Generations below the top of this branch">
                    {{ node.depth }}
                </span>

                <span class="tree-name">
                    <Link
                        v-if="node.person_id"
                        :href="route('people.show', node.person_id)"
                        :class="['ref-link tree-person truncate', nameClass(node)]"
                    >{{ node.name }}<template v-if="node.alt">:{{ node.alt }}</template></Link>
                    <span v-else :class="['tree-person truncate text-ink-500', nameClass(node)]">{{ node.name }}</span>

                    <span class="tree-years">({{ node.years }})</span>

                    <span
                        v-if="node.same_as"
                        class="tree-tag tree-tag--muted"
                        title="Recorded as the same person — identical twin or duplicate kit"
                    >same as {{ node.same_as }}</span>

                    <span
                        v-if="node.force_join"
                        class="tree-tag tree-tag--muted"
                        title="forceJoin — this member starts a branch of its own"
                    >join</span>

                    <span
                        v-if="node.ign_parents"
                        class="tree-tag tree-tag--muted"
                        :title="`Ignoring the recorded ${node.ign_parents}`"
                    >ign {{ node.ign_parents }}</span>

                    <a
                        v-if="node.repeat_of"
                        :href="`#n${node.n}`"
                        class="tree-ref hover:text-wine-500"
                        title="Also a child on another line — shown in full elsewhere"
                    >↖ shown elsewhere</a>
                </span>

                <!-- Shared DNA with the reference kit, when one is chosen.
                     The chip carries the figure as well as the colour, so the
                     band is never the only thing saying how close it is. -->
                <span class="tree-dna">
                    <Link
                        v-if="node.dna_sample_id && view.isRef(node.dna_sample_id)"
                        :href="route('dna.matches', node.dna_sample_id)"
                        class="tree-tag heat-ref"
                        :title="`${node.dna_name || 'This kit'} — the kit everything here is compared against`"
                    >reference</Link>
                    <Link
                        v-else-if="node.dna_sample_id && view.cmFor(node.dna_sample_id) !== null"
                        :href="route('dna.matches', node.dna_sample_id)"
                        :class="['tree-tag', heatClass(view.cmFor(node.dna_sample_id))]"
                        :title="
                            (node.dna_name || 'DNA kit') +
                            (view.cmFor(node.dna_sample_id)
                                ? ` — shares ${view.cmFor(node.dna_sample_id)} cM with the reference`
                                : ' — no shared DNA recorded with the reference') +
                            (node.disabled ? ' — disabled in this tree' : '')
                        "
                    >{{ view.cmFor(node.dna_sample_id).toLocaleString() }} cM</Link>
                    <Link
                        v-else-if="node.dna_sample_id"
                        :href="route('dna.matches', node.dna_sample_id)"
                        :class="['tree-tag', node.disabled ? 'tree-tag--muted' : 'tree-tag--dna']"
                        :title="
                            (node.dna_name || 'DNA kit') +
                            (node.disabled ? ' — disabled in this tree' : '') +
                            (view.hasHeat ? ' — never compared with the reference, so there is no reading either way' : '')
                        "
                    >{{ node.disabled ? '(DNA)' : 'DNA' }}</Link>
                </span>

                <!-- print-tree's "(P: …)": the parent on the other side from
                     the line we came down. Its own column so these line up. -->
                <span class="tree-partner">
                    <template v-if="!node.partner">—</template>
                    <template v-else-if="node.partner.external">outside this tree</template>
                    <template v-else>
                        <Link
                            :href="route('people.show', node.partner.person_id)"
                            class="hover:text-wine-500"
                        >{{ node.partner.name }}</Link>
                        <span class="tree-years">({{ node.partner.years }})</span>
                    </template>
                </span>
            </div>

            <TreeBranch v-if="node.children.length && view.isOpen(node.key)" :nodes="node.children" />
        </li>
    </ul>
</template>

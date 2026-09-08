<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import SampleAvatar from '@/Components/App/SampleAvatar.vue';
import AncestryProfileButtons from '@/Components/App/AncestryProfileButtons.vue';
import ParentSideDialog from '@/Components/App/ParentSideDialog.vue';

const props = defineProps({
    eyes: { type: Array, required: true },
    // Top matches per cluster for the one eye whose ParentSide editor is
    // open. Fetched on demand by ParentSideDialog, null otherwise.
    cluster_evidence: { type: Object, default: null },
});

// Eyes we can no longer fetch through: the kit's Ancestry access is gone,
// so the loaders have nothing to run as. Everything already loaded through
// it stays browsable, which is why it is still listed.
const noSession = computed(() => props.eyes.filter((e) => !e.has_session).length);

const subtitle = computed(() => {
    if (!props.eyes.length) return 'No kits';
    const kits = `${props.eyes.length} DNA test ${props.eyes.length === 1 ? 'kit' : 'kits'}`;
    return noSession.value ? `${kits}, ${noSession.value} without a session` : kits;
});

// ParentSide mapping — which of a kit's p1 / p2 clusters is the paternal
// one. Ancestry only fills `paternalCluster` once the owner has labelled
// their own sides, so for a good number of eyes the pills across the app
// fall back to a bare P1 / P2; `paternalClusterOverride` is our answer and
// wins over theirs. See deploy/paternal-cluster-override.sql.
function mapping(e) {
    const clustered = (e.cluster_p1_count || 0) + (e.cluster_p2_count || 0);
    const override = (e.paternalClusterOverride || '').trim().toLowerCase();
    const ancestry = (e.paternalCluster_ancestry || '').trim().toLowerCase();
    const effective = override || ancestry;
    return {
        clustered,
        override,
        ancestry,
        effective,
        // Ours and Ancestry's both present and pointing opposite ways.
        conflict: !!override && !!ancestry && override !== ancestry,
    };
}

const parentSideEye = ref(null);

function openParentSide(e) {
    parentSideEye.value = e;
}
</script>

<template>
    <Head title="Eyes" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader
                title="Eyes"
                :subtitle="subtitle"
            />
        </template>

        <div class="card overflow-hidden">
            <table class="ref-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Person</th>
                        <th data-numeric>Matches</th>
                        <th>Sex</th>
                        <th>ParentSide mapping</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="e in eyes" :key="e.id">
                        <td>
                            <div class="flex items-center gap-2">
                                <SampleAvatar
                                    :photo-url="e.photoUrl || ''"
                                    :alt="e.displayName || ''"
                                    :gender="e.effective_gender || ''"
                                />
                                <Link :href="route('dna.matches', e.id)" class="ref-link">
                                    {{ e.displayName || `Eye #${e.id}` }}
                                </Link>
                                <span class="font-mono text-[11px] text-sepia-400">#{{ e.id }}</span>
                                <span
                                    v-if="!e.has_session"
                                    class="rounded border border-sepia-300 px-1 text-[10px] uppercase tracking-wide text-sepia-500"
                                    title="No Ancestry session for this kit — existing data is browsable, but nothing new can be loaded through it"
                                >
                                    no session
                                </span>
                            </div>
                        </td>
                        <td>
                            <Link
                                v-if="e.person_id"
                                :href="route('people.show', e.person_id)"
                                class="ref-link"
                            >
                                {{ e.person_name }}
                            </Link>
                            <span v-else class="text-sepia-400">—</span>
                        </td>
                        <td class="num">{{ Number(e.match_count).toLocaleString() }}</td>
                        <td class="font-mono text-xs text-sepia-500">
                            {{ e.gender || '—' }}
                        </td>
                        <td>
                            <button
                                v-if="mapping(e).clustered"
                                type="button"
                                class="group inline-flex items-center gap-1.5 rounded px-1 py-0.5 text-left hover:bg-paper-100 focus:outline-none focus:ring-1 focus:ring-wine-500"
                                :title="`Set which cluster is the paternal side for ${e.display_label}`"
                                @click="openParentSide(e)"
                            >
                                <template v-if="mapping(e).effective">
                                    <span class="font-mono text-xs text-sepia-600">
                                        {{ mapping(e).effective }} = paternal
                                    </span>
                                    <span
                                        v-if="mapping(e).override"
                                        class="rounded px-1 text-[10px] uppercase tracking-wide ring-1"
                                        :class="mapping(e).conflict
                                            ? 'bg-orange-100 text-orange-800 ring-orange-200'
                                            : 'bg-paper-200 text-sepia-600 ring-paper-300'"
                                        :title="mapping(e).conflict
                                            ? `Overridden here — Ancestry says ${mapping(e).ancestry}`
                                            : 'Set here, not by Ancestry'"
                                    >
                                        override
                                    </span>
                                </template>
                                <span v-else class="text-xs text-sepia-400">
                                    not set — showing P1 / P2
                                </span>
                                <span
                                    class="text-[11px] text-sepia-400 opacity-0 group-hover:opacity-100"
                                    aria-hidden="true"
                                >
                                    edit
                                </span>
                            </button>
                            <span v-else class="text-sepia-400">—</span>
                        </td>
                        <td class="!text-right">
                            <div class="inline-flex items-center gap-1">
                                <Link
                                    v-if="e.person_id"
                                    :href="route('people.show', e.person_id)"
                                    class="inline-flex items-center"
                                    :title="`Open ${e.person_name || 'person'} #${e.person_id}`"
                                >
                                    <img src="/icon-person.png" alt="" class="h-5 w-5" />
                                    <span class="sr-only">Open person</span>
                                </Link>
                                <AncestryProfileButtons
                                    :user-uuid="e.userUUID || ''"
                                    :admin-user-uuid="e.admin_userUUID || ''"
                                    :label="e.display_label"
                                    :admin-label="e.display_label"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!eyes.length">
                        <td colspan="6" class="empty-cell">No eyes.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ParentSideDialog
            :show="!!parentSideEye"
            :eye="parentSideEye"
            :evidence="cluster_evidence"
            @close="parentSideEye = null"
        />
    </AuthenticatedLayout>
</template>

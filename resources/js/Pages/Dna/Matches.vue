<script setup>
import { ref, computed, watch, onUnmounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Pagination from '@/Components/App/Pagination.vue';
import ClusterPill from '@/Components/App/ClusterPill.vue';
import SampleAvatar from '@/Components/App/SampleAvatar.vue';
import AncestryProfileButtons from '@/Components/App/AncestryProfileButtons.vue';
import PersonEditDialog from '@/Components/App/PersonEditDialog.vue';
import NoteEditDialog from '@/Components/App/NoteEditDialog.vue';
import TreePill from '@/Components/App/TreePill.vue';
import TreeEditDialog from '@/Components/App/TreeEditDialog.vue';
import AddToTreeDialog from '@/Components/App/AddToTreeDialog.vue';

const props = defineProps({
    sample: { type: Object, required: true },
    matches: { type: Array, required: true },
    page: { type: Number, required: true },
    pages: { type: Number, required: true },
    total: { type: Number, required: true },
    per_page: { type: Number, required: true },
    eye_matches: { type: Array, required: true },
    eye_id: { type: Number, default: null },
    selected_eye: { type: Object, default: null },
    loading_in_progress: { type: Boolean, default: false },
    ancestry_trees: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ q: '' }) },
    title_note: { type: String, default: null },
    notes_eye_id: { type: Number, default: null },
    notes_eye_label: { type: String, default: null },
    pov_paternal_cluster: { type: String, default: null },
    title_pill: { type: Object, default: null },
    side_enabled: { type: Boolean, default: false },
});

// Note-editor side panel state. One panel shared for the title-note
// click and every row-note click; openNoteEditor sets which (sample,
// label, initial-text) we're editing.
const editingNote = ref(null);
function openNoteEditor(sampleId, sampleLabel, initial) {
    if (!props.notes_eye_id) return;
    editingNote.value = {
        sampleId,
        sampleLabel,
        initial: initial || '',
    };
}
function closeNoteEditor() {
    editingNote.value = null;
}

// Tree-pill editor side panel. openTreeEditor receives the tree
// object from a clicked pill; on save the dialog reloads `matches`
// so every row carrying that tree picks up the new name/colour.
const editingTree = ref(null);
function openTreeEditor(tree) {
    editingTree.value = { ...tree };
}
function closeTreeEditor() {
    editingTree.value = null;
}

// "Add to tree" side panel. Distinct set of trees shown anywhere on
// the current page feeds the panel's searchable picker.
const pageTrees = computed(() => {
    const by = new Map();
    for (const m of props.matches) {
        for (const t of m.trees || []) {
            if (!by.has(t.id)) by.set(t.id, t);
        }
    }
    return [...by.values()];
});
const addingToTree = ref(null);
function openAddToTree(m) {
    addingToTree.value = { personId: m.person_id, label: m.display_label };
}
function closeAddToTree() {
    addingToTree.value = null;
}

// `eye_matches` is fixed per title sample, so it's not in ONLY (no
// need to refetch on search / eye-change). The others all shift
// when the eye selection changes.
const ONLY = ['matches', 'page', 'pages', 'total', 'eye_id', 'selected_eye', 'filters', 'pov_paternal_cluster', 'title_pill', 'notes_eye_id', 'notes_eye_label', 'title_note', 'side_enabled'];

function ancestryCompareUrl(otherUuid) {
    if (!props.selected_eye?.dnaUUID || !otherUuid) return null;
    const eye = String(props.selected_eye.dnaUUID).toUpperCase();
    const other = String(otherUuid).toUpperCase();
    return `https://www.ancestry.com.au/discoveryui-matches/compare/${eye}/with/${other}/matchesofmatches`;
}

// When the page sample is itself an eye, A↔B compare links use A's UUID.
function sampleCompareUrl(otherUuid) {
    if (!props.sample?.dnaUUID || !otherUuid) return null;
    const sample = String(props.sample.dnaUUID).toUpperCase();
    const other = String(otherUuid).toUpperCase();
    return `https://www.ancestry.com.au/discoveryui-matches/compare/${sample}/with/${other}/matchesofmatches`;
}

function ancestryHeaderUrl() {
    if (!props.selected_eye?.dnaUUID || !props.sample?.dnaUUID) return null;
    const eye = String(props.selected_eye.dnaUUID).toUpperCase();
    const sample = String(props.sample.dnaUUID).toUpperCase();
    return `https://www.ancestry.com.au/discoveryui-matches/compare/${eye}/with/${sample}/matchesofmatches`;
}

// When the page sample is NOT an eye but the row is, view the compare
// page from the row-eye's perspective (it has the Ancestry session).
function rowEyeCompareUrl(otherUuid) {
    if (!props.sample?.dnaUUID || !otherUuid) return null;
    const eye = String(otherUuid).toUpperCase();
    const sample = String(props.sample.dnaUUID).toUpperCase();
    return `https://www.ancestry.com.au/discoveryui-matches/compare/${eye}/with/${sample}/matchesofmatches`;
}

const sampleIsEye = computed(() => !!props.sample?.managed);
const selectedEyeIsSample = computed(
    () => !!props.selected_eye && Number(props.selected_eye.id) === Number(props.sample.id),
);

const selectedEye = ref(props.eye_id ?? '');
const loading = ref(false);
const eyeListOpen = ref(false);
const treesOpen = ref(false);

// The match row inside eye_matches that corresponds to the currently
// selected eye — supplies cM/cluster/etc for the closed-accordion bar.
const selectedEyeRow = computed(() => {
    if (!props.selected_eye) return null;
    return props.eye_matches.find(
        (e) => Number(e.other_id) === Number(props.selected_eye.id),
    ) || null;
});

function matchLink(otherId) {
    const base = route('dna.matches', otherId);
    if (props.selected_eye) return `${base}?eye=${props.selected_eye.id}`;
    if (props.sample?.managed) return `${base}?eye=${props.sample.id}`;
    return base;
}

// Search by display name. Debounced — typing fires after 280ms idle,
// which is what Eyes/Matches uses too. Preserves the eye filter so
// searches happen within whatever common-with view is active.
const q = ref(props.filters?.q ?? '');
const side = ref(props.filters?.side ?? 'ALL');

// Shared reload — both the search box and the ParentSide dropdown
// reset to page 1 and preserve every other active filter.
function reloadFilters() {
    router.reload({
        only: ONLY,
        preserveState: true,
        preserveScroll: true,
        replace: true,
        data: {
            q: q.value.trim() || undefined,
            side: side.value !== 'ALL' ? side.value : undefined,
            eye: props.selected_eye?.id || undefined,
            page: 1,
        },
    });
}

let qTimer = null;
watch(q, () => {
    if (qTimer) clearTimeout(qTimer);
    qTimer = setTimeout(reloadFilters, 280);
});

watch(side, reloadFilters);

// When the POV eye disappears (e.g. switching the eye filter back to
// "All"), the dropdown is disabled — snap the value back to ALL so a
// stale PATERNAL/P1 selection doesn't linger in the URL.
watch(() => props.side_enabled, (enabled) => {
    if (!enabled && side.value !== 'ALL') side.value = 'ALL';
});

function reloadPage() {
    router.reload({ preserveState: true, preserveScroll: true });
}

// Force a full requeue of every (eye, this-sample) pair, then nudge
// Inertia so loading_in_progress flips to true and the spinner takes
// over. The polling watcher then takes care of refreshing the page
// when the workers drain.
const requeuing = ref(false);
function forceReload() {
    if (requeuing.value) return;
    requeuing.value = true;
    router.post(
        route('dna.matches.requeue', props.sample.id),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => { requeuing.value = false; },
            onSuccess: () => router.reload({
                only: ['loading_in_progress'],
                preserveScroll: true,
                preserveState: true,
            }),
        },
    );
}

// While the queue is still draining, poll only the loading_in_progress
// prop every 10s. When it flips false, do one full reload to pull the
// freshly-loaded matches in. Click-to-refresh button still works for
// impatient users.
let loadingPollTimer = null;
function startLoadingPoll() {
    if (loadingPollTimer) return;
    loadingPollTimer = setInterval(() => {
        router.reload({
            only: ['loading_in_progress'],
            preserveScroll: true,
            preserveState: true,
        });
    }, 10000);
}
function stopLoadingPoll() {
    if (loadingPollTimer) {
        clearInterval(loadingPollTimer);
        loadingPollTimer = null;
    }
}

watch(
    () => props.loading_in_progress,
    (current, previous) => {
        if (current) {
            startLoadingPoll();
        } else {
            stopLoadingPoll();
            if (previous === true) {
                router.reload({ preserveScroll: true });
            }
        }
    },
    { immediate: true },
);

onUnmounted(stopLoadingPoll);

watch(selectedEye, (val) => {
    eyeListOpen.value = false;
    router.reload({
        only: ONLY,
        preserveState: true,
        preserveScroll: true,
        replace: true,
        data: {
            eye: val || undefined,
            q: q.value.trim() || undefined,
            side: side.value !== 'ALL' ? side.value : undefined,
            page: 1,
        },
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
});

const editing = ref(null);

function openEdit(m) {
    editing.value = {
        sampleId: m.other_id,
        personId: m.person_id || null,
        prefill: m.person_id
            ? {
                  fullName: m.person_name || '',
                  minBirth: m.person_minBirth,
                  maxBirth: m.person_maxBirth,
                  death: m.person_death,
                  gender: m.person_gender,
              }
            : {
                  fullName: m.other_name || '',
                  minBirth: null,
                  maxBirth: null,
                  death: null,
                  gender: m.other_gender || null,
              },
    };
}

function openSampleEdit(s) {
    editing.value = {
        sampleId: s.id,
        personId: s.person_id || null,
        prefill: s.person_id
            ? {
                  fullName: s.person_name || '',
                  minBirth: s.person_minBirth,
                  maxBirth: s.person_maxBirth,
                  death: s.person_death,
                  gender: s.person_gender,
              }
            : {
                  fullName: s.displayName || '',
                  minBirth: null,
                  maxBirth: null,
                  death: null,
                  gender: s.gender || null,
              },
    };
}

function closeEdit() {
    editing.value = null;
}
</script>

<template>
    <Head :title="`Matches · ${sample.display_label}`" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader
                compact
                :title="sample.display_label"
                :eyebrow="`Sample #${sample.id}`"
            >
                <template #subtitle>
                    <Link
                        v-if="sample.person_id"
                        :href="route('people.show', sample.person_id)"
                        class="inline-flex items-center"
                        :title="`Open ${sample.person_name || 'person'} #${sample.person_id}`"
                    >
                        <img src="/icon-person.png" alt="" class="h-5 w-5" />
                        <span class="sr-only">Open person</span>
                    </Link>
                    <a
                        v-if="selected_eye"
                        :href="ancestryHeaderUrl()"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 rounded border border-paper-300 bg-paper-50 px-1.5 py-0.5 text-[11px] font-medium text-ink-300 hover:border-paper-400 hover:bg-paper-100 hover:text-ink-500"
                        :title="`Compare on Ancestry: ${selected_eye.display_label} ↔ ${sample.display_label}`"
                    >
                        <img src="/ancestry-icon.svg" alt="" class="h-3.5 w-3.5" />
                        DNA
                    </a>
                    <AncestryProfileButtons
                        :user-uuid="sample.userUUID || ''"
                        :admin-user-uuid="sample.admin_userUUID || ''"
                        :label="sample.display_label"
                        :admin-label="sample.display_label"
                    />
                    <button
                        v-if="loading_in_progress"
                        type="button"
                        @click="reloadPage"
                        class="inline-flex items-center gap-1 rounded border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-800 hover:bg-amber-100"
                        title="Match-of-match data is still loading — auto-refreshes every 10 s; click to refresh now"
                    >
                        <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" />
                            <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                        </svg>
                        Loading…
                    </button>
                    <button
                        v-else
                        type="button"
                        :disabled="requeuing"
                        @click="forceReload"
                        class="inline-flex items-center gap-1 rounded border border-paper-300 bg-paper-50 px-1.5 py-0.5 text-[11px] font-medium text-ink-300 hover:border-paper-400 hover:bg-paper-100 hover:text-ink-500 disabled:opacity-60"
                        title="Re-fetch every (eye ↔ this sample) pair from Ancestry, ignoring already-loaded state"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 20 20"
                            fill="currentColor"
                            class="h-3.5 w-3.5"
                            :class="requeuing ? 'animate-spin' : ''"
                            aria-hidden="true"
                        >
                            <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H3.989a.75.75 0 0 0-.75.75v4.242a.75.75 0 0 0 1.5 0v-2.43l.31.31a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39zm1.23-3.723a.75.75 0 0 0 .219-.53V2.929a.75.75 0 0 0-1.5 0v2.42l-.31-.31A7 7 0 0 0 3.239 8.175a.75.75 0 1 0 1.448.389A5.5 5.5 0 0 1 13.89 6.1l.311.31h-2.432a.75.75 0 0 0 0 1.5h4.243a.75.75 0 0 0 .53-.219z" clip-rule="evenodd" />
                        </svg>
                        RELOAD
                    </button>
                </template>
                <template #titleBefore>
                    <SampleAvatar
                        :photo-url="sample.photoUrl || ''"
                        :alt="sample.display_label"
                        :gender="sample.effective_gender || ''"
                        size="md"
                    />
                </template>
                <template #titleAfter>
                    <button
                        type="button"
                        class="inline-flex items-center rounded p-0.5 text-sepia-400 hover:bg-paper-100 hover:text-wine-500 focus:outline-none focus:ring-1 focus:ring-wine-500"
                        :title="sample.person_id ? 'Edit person' : 'Create person'"
                        @click="openSampleEdit(sample)"
                    >
                        <img src="/icon-person-edit.png" alt="" class="h-6 w-6" />
                        <span class="sr-only">{{ sample.person_id ? 'Edit person' : 'Create person' }}</span>
                    </button>
                    <button
                        v-if="notes_eye_id"
                        type="button"
                        class="inline-flex items-center rounded p-0.5 text-sepia-400 hover:bg-paper-100 hover:text-wine-500 focus:outline-none focus:ring-1 focus:ring-wine-500"
                        :title="title_note ? `Edit notes for ${sample.display_label}` : `Add notes for ${sample.display_label}`"
                        @click="openNoteEditor(sample.id, sample.display_label, title_note)"
                    >
                        <img src="/icon-note.png" alt="" class="h-6 w-6" />
                        <span class="sr-only">{{ title_note ? 'Edit notes' : 'Add notes' }}</span>
                    </button>
                    <img
                        v-if="sampleIsEye"
                        src="/icon-eye.png"
                        alt="Eye"
                        title="Managed eye"
                        class="h-6 w-6"
                    />
                    <ClusterPill
                        v-if="title_pill"
                        :code="title_pill.matchClusterCode || ''"
                        :paternal-cluster="title_pill.paternalCluster || ''"
                        :parent-side="title_pill.parentSide || ''"
                    />
                </template>
                <template #actions>
                    <Link :href="route('dna.index')" class="btn-ghost">← DNA search</Link>
                </template>
                <template v-if="title_note" #belowSubtitle>
                    <div class="ps-[4ch] text-xs italic text-sepia-500">
                        <button
                            type="button"
                            class="text-left hover:text-wine-500 focus:outline-none focus:underline"
                            :title="`Edit notes for ${sample.display_label}`"
                            @click="openNoteEditor(sample.id, sample.display_label, title_note)"
                        >
                            {{ title_note.length > 80 ? title_note.slice(0, 80) + '…' : title_note }}
                        </button>
                    </div>
                </template>
            </PageHeader>
        </template>

        <div v-if="eye_matches.length" class="card mb-4 overflow-hidden">
            <button
                type="button"
                class="flex w-full items-center gap-3 border-b border-paper-300 bg-paper-100 px-4 py-2.5 text-left hover:bg-paper-200/60 focus:outline-none focus:ring-1 focus:ring-wine-500"
                :aria-expanded="eyeListOpen"
                @click="eyeListOpen = !eyeListOpen"
            >
                <span
                    class="text-sepia-500 transition-transform"
                    :class="eyeListOpen ? 'rotate-90' : ''"
                    aria-hidden="true"
                >
                    ▶
                </span>
                <p class="eyebrow">Matching eyes</p>
                <div
                    v-if="selectedEyeRow"
                    class="flex flex-1 items-center gap-2 text-sm"
                >
                    <SampleAvatar
                        :photo-url="selectedEyeRow.other_photoUrl || ''"
                        :alt="selectedEyeRow.display_label"
                        :gender="selectedEyeRow.effective_gender || ''"
                    />
                    <span class="font-medium text-ink-500">
                        {{ selectedEyeRow.display_label }}
                    </span>
                    <img src="/icon-eye.png" alt="Eye" title="Managed eye" class="h-6 w-6" />
                    <span class="font-mono text-xs text-sepia-500">
                        {{ selectedEyeRow.sharedCentimorgans }} cM
                    </span>
                    <ClusterPill
                        :code="selectedEyeRow.matchClusterCode || ''"
                        :paternal-cluster="selectedEyeRow.paternalCluster || ''"
                        :parent-side="selectedEyeRow.parentSide || ''"
                    />
                </div>
                <p v-else class="flex-1 text-sm text-sepia-600">
                    All Eyes ({{ eye_matches.length }})
                </p>
                <p v-if="!eyeListOpen" class="text-xs text-sepia-500">
                    Click to pick a filter
                </p>
            </button>
            <table v-show="eyeListOpen" class="ref-table">
                <thead>
                    <tr>
                        <th></th>
                        <th>Name</th>
                        <th>Predicted</th>
                        <th data-numeric>cM</th>
                        <th>ParentSide</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <input
                                id="eye-pick-all"
                                v-model="selectedEye"
                                type="radio"
                                value=""
                                class="cursor-pointer"
                            />
                        </td>
                        <td colspan="5">
                            <label for="eye-pick-all" class="cursor-pointer text-sm text-sepia-600">
                                All matches (no filter)
                            </label>
                        </td>
                    </tr>
                    <tr
                        v-for="e in eye_matches"
                        :key="e.other_id"
                        :class="e.ignored ? 'opacity-50' : ''"
                    >
                        <td>
                            <input
                                :id="`eye-pick-${e.other_id}`"
                                v-model.number="selectedEye"
                                type="radio"
                                :value="e.other_id"
                                class="cursor-pointer"
                            />
                        </td>
                        <td>
                            <div class="flex items-center gap-2">
                                <SampleAvatar
                                    :photo-url="e.other_photoUrl || ''"
                                    :alt="e.display_label"
                                    :gender="e.effective_gender || ''"
                                />
                                <Link
                                    :href="matchLink(e.other_id)"
                                    class="ref-link"
                                    :class="e.ignored ? 'line-through decoration-sepia-400/60' : ''"
                                >
                                    {{ e.display_label }}
                                </Link>
                                <img
                                    v-if="e.connected_via_tree"
                                    src="/icon-link.png"
                                    alt=""
                                    class="ms-1 h-6 w-6 opacity-80"
                                    :title="`Connected to ${sample.display_label} via the family tree`"
                                />
                                <img src="/icon-eye.png" alt="Eye" title="Managed eye" class="ms-2 h-6 w-6" />
                            </div>
                        </td>
                        <td class="text-sm text-sepia-700">
                            {{ (e.kinships || []).join(' / ') }}
                        </td>
                        <td class="num">{{ e.sharedCentimorgans }}</td>
                        <td>
                            <ClusterPill
                                :code="e.matchClusterCode || ''"
                                :paternal-cluster="e.paternalCluster || ''"
                                :parent-side="e.parentSide || ''"
                            />
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
                                <a
                                    v-if="!sampleIsEye && e.other_uuid"
                                    :href="rowEyeCompareUrl(e.other_uuid)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 rounded border border-paper-300 bg-paper-50 px-1.5 py-0.5 text-[11px] font-medium text-ink-300 hover:border-paper-400 hover:bg-paper-100 hover:text-ink-500"
                                    :title="`Compare on Ancestry: ${e.display_label} ↔ ${sample.display_label}`"
                                >
                                    <img src="/ancestry-icon.svg" alt="" class="h-3.5 w-3.5" />
                                    DNA
                                </a>
                                <a
                                    v-if="sampleIsEye && e.other_uuid"
                                    :href="sampleCompareUrl(e.other_uuid)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 rounded border border-paper-300 bg-paper-50 px-1.5 py-0.5 text-[11px] font-medium text-ink-300 hover:border-paper-400 hover:bg-paper-100 hover:text-ink-500"
                                    :title="`Compare on Ancestry: ${sample.display_label} ↔ ${e.display_label}`"
                                >
                                    <img src="/ancestry-icon.svg" alt="" class="h-3.5 w-3.5" />
                                    DNA
                                </a>
                                <AncestryProfileButtons
                                    :user-uuid="e.other_userUUID || ''"
                                    :admin-user-uuid="e.other_admin_userUUID || ''"
                                    :label="e.display_label"
                                    :admin-label="e.display_label"
                                />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="ancestry_trees.length" class="card mb-4 overflow-hidden">
            <button
                type="button"
                class="flex w-full items-center gap-3 border-b border-paper-300 bg-paper-100 px-4 py-2.5 text-left hover:bg-paper-200/60 focus:outline-none focus:ring-1 focus:ring-wine-500"
                :aria-expanded="treesOpen"
                @click="treesOpen = !treesOpen"
            >
                <span
                    class="text-sepia-500 transition-transform"
                    :class="treesOpen ? 'rotate-90' : ''"
                    aria-hidden="true"
                >
                    ▶
                </span>
                <p class="eyebrow flex-1">
                    Ancestry trees ({{ ancestry_trees.length }})
                </p>
            </button>
            <ul v-show="treesOpen" class="divide-y divide-paper-200 px-4 py-2 text-sm text-sepia-600">
                <li
                    v-for="t in ancestry_trees"
                    :key="`${t.atreeid}-${t.ancestryid}`"
                    class="py-1"
                >
                    <a
                        :href="`https://www.ancestry.com.au/family-tree/tree/${t.atreeid}/family?cfpid=${t.ancestryid}`"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="ref-link"
                    >
                        {{ t.name || 'Unknown Tree' }}
                    </a>
                </li>
            </ul>
        </div>

        <form
            class="mb-4 flex flex-wrap items-center gap-2"
            @submit.prevent
        >
            <input
                v-model="q"
                type="search"
                placeholder="Search matches by name…"
                class="w-full max-w-xs rounded-md border-paper-300 text-sm focus:border-wine-500 focus:ring-wine-500"
            />
            <label class="flex items-center gap-1.5 text-xs text-sepia-500">
                ParentSide
                <select
                    v-model="side"
                    :disabled="!side_enabled"
                    class="rounded-md border-paper-300 text-sm focus:border-wine-500 focus:ring-wine-500 disabled:cursor-not-allowed disabled:bg-paper-100 disabled:text-sepia-400"
                    :title="side_enabled ? 'Filter rows by ParentSide' : 'Select an eye to filter by ParentSide'"
                >
                    <option value="ALL">All</option>
                    <option value="MATERNAL">Maternal</option>
                    <option value="PATERNAL">Paternal</option>
                    <option value="P1">P1</option>
                    <option value="P2">P2</option>
                </select>
            </label>
        </form>

        <div
            v-if="loading"
            class="card flex items-center justify-center gap-2 py-16 text-sm text-sepia-500"
        >
            <svg class="h-4 w-4 animate-spin text-sepia-400" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" />
                <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
            </svg>
            Loading matches…
        </div>

        <template v-else>
        <div class="mb-4">
            <Pagination :page="page" :pages="pages" :total="total" :only="ONLY" />
        </div>

        <div class="card overflow-hidden">
            <table class="ref-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Predicted</th>
                        <th data-numeric>cM</th>
                        <th>ParentSide</th>
                        <th>Trees</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="m in matches" :key="m.other_id">
                    <tr :class="m.ignored ? 'opacity-50' : ''">
                        <td>
                            <div class="flex items-center gap-2">
                                <SampleAvatar
                                    :photo-url="m.other_photoUrl || ''"
                                    :alt="m.display_label"
                                    :gender="m.effective_gender || ''"
                                />
                                <Link
                                    :href="matchLink(m.other_id)"
                                    class="ref-link"
                                    :class="m.ignored ? 'line-through decoration-sepia-400/60' : ''"
                                >
                                    {{ m.display_label }}
                                </Link>
                                <button
                                    type="button"
                                    class="ms-1 inline-flex items-center rounded p-0.5 align-middle text-sepia-400 hover:bg-paper-100 hover:text-wine-500 focus:outline-none focus:ring-1 focus:ring-wine-500"
                                    :title="m.person_id ? 'Edit person' : 'Create person'"
                                    @click="openEdit(m)"
                                >
                                    <img src="/icon-person-edit.png" alt="" class="h-6 w-6" />
                                    <span class="sr-only">{{ m.person_id ? 'Edit' : 'Create' }}</span>
                                </button>
                                <button
                                    v-if="notes_eye_id"
                                    type="button"
                                    class="ms-1 inline-flex items-center rounded p-0.5 align-middle text-sepia-400 hover:bg-paper-100 hover:text-wine-500 focus:outline-none focus:ring-1 focus:ring-wine-500"
                                    :title="m.note ? `Edit notes for ${m.display_label}` : `Add notes for ${m.display_label}`"
                                    @click="openNoteEditor(m.other_id, m.display_label, m.note)"
                                >
                                    <img src="/icon-note.png" alt="" class="h-6 w-6" />
                                    <span class="sr-only">{{ m.note ? 'Edit notes' : 'Add notes' }}</span>
                                </button>
                                <img
                                    v-if="m.connected_via_tree"
                                    src="/icon-link.png"
                                    alt=""
                                    class="ms-1 h-6 w-6 opacity-80"
                                    :title="`Connected to ${sample.display_label} via the family tree`"
                                />
                                <img
                                    v-if="m.other_managed"
                                    src="/icon-eye.png"
                                    alt="Eye"
                                    title="Managed eye"
                                    class="ms-2 h-6 w-6"
                                />
                            </div>
                            <div
                                v-if="m.note"
                                class="mt-1 ps-[4ch] text-xs italic text-sepia-500"
                            >
                                <button
                                    type="button"
                                    class="text-left hover:text-wine-500 focus:outline-none focus:underline"
                                    :title="`Edit notes for ${m.display_label}`"
                                    @click="openNoteEditor(m.other_id, m.display_label, m.note)"
                                >
                                    {{ m.note.length > 80 ? m.note.slice(0, 80) + '…' : m.note }}
                                </button>
                            </div>
                        </td>
                        <td class="text-sm text-sepia-700">
                            {{ (m.kinships || []).join(' / ') }}
                        </td>
                        <td class="num">{{ m.sharedCentimorgans }}</td>
                        <td>
                            <ClusterPill
                                :code="m.matchClusterCode || ''"
                                :paternal-cluster="pov_paternal_cluster || ''"
                                :parent-side="m.parentSide || ''"
                            />
                        </td>
                        <td>
                            <div class="flex flex-wrap items-center gap-1">
                                <TreePill
                                    v-for="t in (m.trees || [])"
                                    :key="t.id"
                                    :tree="t"
                                    @edit="openTreeEditor"
                                />
                                <button
                                    v-if="m.person_id"
                                    type="button"
                                    class="inline-flex h-5 w-5 items-center justify-center rounded text-sm font-semibold leading-none text-sepia-500 ring-1 ring-inset ring-dashed ring-sepia-300 transition hover:text-wine-500 hover:ring-wine-500 focus:outline-none focus:ring-2 focus:ring-wine-500"
                                    :title="`Add ${m.display_label} to a tree`"
                                    @click="openAddToTree(m)"
                                >
                                    +
                                    <span class="sr-only">Add to tree</span>
                                </button>
                            </div>
                        </td>
                        <td class="!text-right">
                            <div class="inline-flex items-center gap-1">
                                <Link
                                    v-if="m.person_id"
                                    :href="route('people.show', m.person_id)"
                                    class="inline-flex items-center"
                                    :title="`Open ${m.person_name || 'person'} #${m.person_id}`"
                                >
                                    <img src="/icon-person.png" alt="" class="h-5 w-5" />
                                    <span class="sr-only">Open person</span>
                                </Link>
                                <a
                                    v-if="sampleIsEye && m.other_uuid && !selectedEyeIsSample"
                                    :href="sampleCompareUrl(m.other_uuid)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 rounded border border-paper-300 bg-paper-50 px-1.5 py-0.5 text-[11px] font-medium text-ink-300 hover:border-paper-400 hover:bg-paper-100 hover:text-ink-500"
                                    :title="`Compare on Ancestry: ${sample.display_label} ↔ ${m.display_label}`"
                                >
                                    <img src="/ancestry-icon.svg" alt="" class="h-3.5 w-3.5" />
                                    DNA
                                </a>
                                <a
                                    v-if="!sampleIsEye && m.other_managed && m.other_uuid"
                                    :href="rowEyeCompareUrl(m.other_uuid)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 rounded border border-paper-300 bg-paper-50 px-1.5 py-0.5 text-[11px] font-medium text-ink-300 hover:border-paper-400 hover:bg-paper-100 hover:text-ink-500"
                                    :title="`Compare on Ancestry: ${m.display_label} ↔ ${sample.display_label}`"
                                >
                                    <img src="/ancestry-icon.svg" alt="" class="h-3.5 w-3.5" />
                                    DNA
                                </a>
                                <a
                                    v-if="selected_eye && m.other_uuid"
                                    :href="ancestryCompareUrl(m.other_uuid)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 rounded border border-paper-300 bg-paper-50 px-1.5 py-0.5 text-[11px] font-medium text-ink-300 hover:border-paper-400 hover:bg-paper-100 hover:text-ink-500"
                                    :title="`Compare on Ancestry: ${selected_eye.display_label} ↔ ${m.display_label}`"
                                >
                                    <img src="/ancestry-icon.svg" alt="" class="h-3.5 w-3.5" />
                                    DNA
                                </a>
                                <AncestryProfileButtons
                                    :user-uuid="m.other_userUUID || ''"
                                    :admin-user-uuid="m.other_admin_userUUID || ''"
                                    :label="m.display_label"
                                    :admin-label="m.display_label"
                                />
                            </div>
                        </td>
                    </tr>
                    </template>
                    <tr v-if="!matches.length">
                        <td colspan="6" class="empty-cell">No matches.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            <Pagination :page="page" :pages="pages" :total="total" :only="ONLY" />
        </div>
        </template>

        <PersonEditDialog
            :show="!!editing"
            :sample-id="editing?.sampleId ?? 0"
            :person-id="editing?.personId ?? null"
            :prefill="editing?.prefill ?? {}"
            @close="closeEdit"
        />

        <NoteEditDialog
            :show="!!editingNote"
            :sample-id="editingNote?.sampleId ?? 0"
            :sample-label="editingNote?.sampleLabel ?? ''"
            :eye-id="notes_eye_id ?? 0"
            :eye-label="notes_eye_label ?? ''"
            :initial="editingNote?.initial ?? ''"
            @close="closeNoteEditor"
        />

        <TreeEditDialog
            :show="!!editingTree"
            :tree="editingTree"
            @close="closeTreeEditor"
        />

        <AddToTreeDialog
            :show="!!addingToTree"
            :person-id="addingToTree?.personId ?? null"
            :person-label="addingToTree?.label ?? ''"
            :trees="pageTrees"
            @close="closeAddToTree"
        />
    </AuthenticatedLayout>
</template>

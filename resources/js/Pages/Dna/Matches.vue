<script setup>
import { ref, computed, watch, onBeforeUnmount, onMounted, onUnmounted } from 'vue';
import { Head, InfiniteScroll, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ClusterPill from '@/Components/App/ClusterPill.vue';
import SampleAvatar from '@/Components/App/SampleAvatar.vue';
import AncestryProfileButtons from '@/Components/App/AncestryProfileButtons.vue';
import PersonEditDialog from '@/Components/App/PersonEditDialog.vue';
import NoteEditDialog from '@/Components/App/NoteEditDialog.vue';
import TreePill from '@/Components/App/TreePill.vue';
import TreeEditDialog from '@/Components/App/TreeEditDialog.vue';
import PersonTreesDialog from '@/Components/App/PersonTreesDialog.vue';
import TreeFilterDropdown from '@/Components/App/TreeFilterDropdown.vue';
import OriginIcons from '@/Components/App/OriginIcons.vue';

const props = defineProps({
    sample: { type: Object, required: true },
    // Scroll envelope: { data: [...rows], has_more: bool }. Inertia
    // appends each fetched chunk into `data`, so this grows as the
    // user scrolls rather than being replaced.
    matches: { type: Object, required: true },
    per_page: { type: Number, required: true },
    eye_matches: { type: Array, required: true },
    eye_id: { type: Number, default: null },
    selected_eye: { type: Object, default: null },
    loading_status: {
        type: Object,
        default: () => ({ state: 'complete', message: '', eyes_total: 0, eyes_done: 0,
                          eyes_failed: 0, worker_alive: true, reasons: {} }),
    },
    ancestry_trees: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ q: '' }) },
    title_note: { type: String, default: null },
    pov_paternal_cluster: { type: String, default: null },
    title_pill: { type: Object, default: null },
    title_trees: { type: Array, default: () => [] },
    side_enabled: { type: Boolean, default: false },
    tree_options: { type: Array, default: () => [] },
    // Rows re-read by id after a write — never sent on a normal load,
    // only in answer to refreshRows() below.
    row_patch: { type: Array, default: () => [] },
});

// --- Local edits, held as overlays over the server's rows -----------
//
// Writes on this page answer with `back()`, a *full* Inertia visit
// that would rebuild every prop — re-running the whole match query —
// to pick up the one row that changed. So the writes below submit
// with a minimal `only:` and record what changed here instead.
//
// These have to be overlays rather than a mutable copy of the rows,
// because `matches.data` is an append path: every scroll chunk hands
// back the accumulated list from the server, which would wash a local
// edit straight back out. Applying the edit at render time instead
// means it survives any number of appends, and it also reaches rows
// that scroll into view later — which matters for a tree rename, where
// one edit repaints a pill on rows nobody has loaded yet.
//
// Both overlays are idempotent: they hold either the user's latest
// edit or a value the server has already caught up with, so
// re-applying them over fresh server data is a no-op. They are cleared
// where we deliberately ask for a clean list (filter/eye change, the
// RELOAD button, and the reload after the workers drain).
const rowOverlay = ref({});        // other_id -> partial row (or a whole re-read row)
const treeOverlay = ref({});       // tree id  -> { name, colour, letter }
const titleNoteOverlay = ref();    // undefined = no local edit

function clearOverlays() {
    rowOverlay.value = {};
    treeOverlay.value = {};
    titleNoteOverlay.value = undefined;
}

function withTreeOverlay(trees) {
    if (! trees?.length) return trees;
    const o = treeOverlay.value;
    return Object.keys(o).length ? trees.map((t) => (o[t.id] ? { ...t, ...o[t.id] } : t)) : trees;
}

const rows = computed(() =>
    (props.matches?.data || []).map((r) => {
        const patch = rowOverlay.value[r.other_id];
        const row = patch ? { ...r, ...patch } : r;
        const trees = withTreeOverlay(row.trees);
        return trees === row.trees ? row : { ...row, trees };
    }),
);

const titleNote = computed(() =>
    titleNoteOverlay.value !== undefined ? titleNoteOverlay.value : props.title_note,
);
const titleTrees = computed(() => withTreeOverlay(props.title_trees || []) || []);
const treeOptions = computed(() => withTreeOverlay(props.tree_options || []) || []);

// --- Condensed header ---------------------------------------------
//
// Once the reader is into the list, the header's job is to say which
// kit this is; the tree pills, action chips and load state are setup,
// not reading matter. Scrolling drops them and keeps the name, so the
// pane gets that space back — on a 720px window the fixed chrome was
// 61% of the screen and four rows.
//
// Driven by the pane's own scroll, because the window has not scrolled
// since the pane took over.
const condensed = ref(false);

function onPaneScroll(event) {
    const el = event.target;
    const top = el.scrollTop;

    // Condensing gives the pane ~120px more height, which can leave a
    // barely-scrollable list unable to stay scrolled: it clamps back to
    // the top, un-condenses, and bounces. Only bother when there is
    // comfortably more list than that.
    const scrollable = el.scrollHeight - el.clientHeight;

    if (! condensed.value && top > 64 && scrollable > 240) {
        condensed.value = true;
    } else if (condensed.value && top < 16) {
        condensed.value = false;
    }
}

// Sample id of the row holding a given person, so a person-keyed edit
// can name the rows it touched. Null when the person is the title's
// own (which lives in `title_trees`, not in a row).
function sampleIdForPerson(personId) {
    const row = rows.value.find((r) => Number(r.person_id) === Number(personId));
    return row ? Number(row.other_id) : null;
}

// Re-read specific rows from the server and lay them over the list.
// Used for the two writes whose result the client can't derive: a
// person edit (display label, effective gender and kinship labels are
// all server-side) and a tree add, which may have created the tree.
// `extraOnly` names any other cheap props that moved with it.
function refreshRows(sampleIds, extraOnly = []) {
    const ids = [...new Set((sampleIds || []).map(Number).filter(Boolean))];
    if (! ids.length && ! extraOnly.length) return;
    router.reload({
        only: ids.length ? ['row_patch', ...extraOnly] : extraOnly,
        data: { patch: ids },
        preserveState: true,
        preserveScroll: true,
        preserveUrl: true,
        onSuccess: (page) => {
            for (const r of page?.props?.row_patch || []) {
                rowOverlay.value[r.other_id] = r;
            }
        },
    });
}

// A person edit changes the display label, effective gender and the
// kinship labels, all of which are built server-side, so the row has to
// be re-read.
//
// The title sample is the exception: it is not among its own matches,
// so matchRows() has nothing to return for it and the header would keep
// the old name until a manual refresh. Its name lives in the `sample`
// prop, so ask for that instead.
function onPersonSaved({ sampleId }) {
    if (Number(sampleId) === Number(props.sample.id)) {
        refreshRows([], ['sample']);
        return;
    }
    refreshRows([sampleId]);
}

// A note is one string on one sample — the client knows the result
// exactly, so nothing needs re-reading.
function onNoteSaved({ sampleId, notes }) {
    const text = (notes || '').trim() || null;
    if (Number(sampleId) === Number(props.sample.id)) {
        titleNoteOverlay.value = text;
        return;
    }
    rowOverlay.value = {
        ...rowOverlay.value,
        [sampleId]: { ...(rowOverlay.value[sampleId] || {}), note: text },
    };
}

// A tree's name and colour are echoed on every pill that carries it,
// in the title's tree list and in the Trees filter options. `letter`
// mirrors DnaSampleService's uppercase-first-character rule.
function onTreeSaved({ id, name, colour }) {
    treeOverlay.value = {
        ...treeOverlay.value,
        [id]: { name, colour, letter: (name || '?').trim().charAt(0).toUpperCase() || '?' },
    };
}

// Adding to a tree can create one (find-or-create by name), so the id
// and colour have to come from the server. Removing can't, but it goes
// the same way to keep one path through tree membership.
function onPersonTreesChanged(personId) {
    const sampleId = sampleIdForPerson(personId);
    refreshRows(sampleId ? [sampleId] : [], ['title_trees', 'tree_options']);
}

// Note-editor side panel state. One panel shared for the title-note
// click and every row-note click; openNoteEditor sets which (sample,
// label, initial-text) we're editing.
//
// No eye involved since 2026-09-09: notes are one per sample
// (dna_sample_notes), where they used to be keyed (sample, eye) and so
// could only be shown or edited once an eye was in play.
const editingNote = ref(null);
function openNoteEditor(sampleId, sampleLabel, initial) {
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

// Distinct set of trees shown anywhere on the current page — feeds
// the PersonTreesDialog add-picker suggestions.
const pageTrees = computed(() => {
    const by = new Map();
    for (const t of titleTrees.value) {
        if (!by.has(t.id)) by.set(t.id, t);
    }
    for (const m of rows.value) {
        for (const t of m.trees || []) {
            if (!by.has(t.id)) by.set(t.id, t);
        }
    }
    return [...by.values()];
});

// Per-person "Trees" management panel. We track only the personId so
// the panel's tree list stays live: after an add/remove reloads the
// page, managedTrees recomputes from the fresh props and the open
// panel updates in place.
const managingPersonId = ref(null);
const managingLabel = ref('');
function openPersonTrees(personId, label) {
    if (!personId) return;
    managingPersonId.value = personId;
    managingLabel.value = label;
}
function closePersonTrees() {
    managingPersonId.value = null;
}
const managedTrees = computed(() => {
    const pid = managingPersonId.value;
    if (!pid) return [];
    if (Number(props.sample.person_id) === Number(pid)) return titleTrees.value;
    const row = rows.value.find((m) => Number(m.person_id) === Number(pid));
    return row?.trees || [];
});

// `eye_matches` is fixed per title sample, so it's not in ONLY (no
// need to refetch on search / eye-change). The others all shift
// when the eye selection changes.
const ONLY = ['matches', 'eye_id', 'selected_eye', 'filters', 'pov_paternal_cluster', 'title_pill', 'title_note', 'side_enabled'];

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

// Eye-ness for navigation: a kit whose session has gone is still an eye you
// can browse through, because its matches are already loaded.
const sampleIsEye = computed(() => !!props.sample?.is_eye);
// Whether Ancestry itself can still be reached through this kit. The compare
// links below need this, not sampleIsEye — without a session the kit is gone
// from the Ancestry account too, so the URL would 404 for the user.
const sampleHasSession = computed(() => !!props.sample?.has_session);
const selectedEyeIsSample = computed(
    () => !!props.selected_eye && Number(props.selected_eye.id) === Number(props.sample.id),
);

const selectedEye = ref(props.eye_id ?? '');
const loading = ref(false);
// --- Eye picker dropdown -------------------------------------------
//
// Same mechanics as TreeFilterDropdown next to it: a trigger on the
// filter row and a panel teleported to the body, dismissed by an
// outside click, Escape, or any scroll. The panel is wide because it
// holds the full eye table — avatars, cM, cluster pills, compare links
// — so it is clamped to the viewport rather than anchored blindly to
// the trigger's left edge.
const eyeMenuOpen = ref(false);
const eyeTrigger = ref(null);
const eyeMenu = ref(null);
const eyePos = ref({ top: 0, left: 0, width: 0 });

function placeEyeMenu() {
    if (! eyeTrigger.value) return;
    const r = eyeTrigger.value.getBoundingClientRect();
    const margin = 16;
    const width = Math.min(880, window.innerWidth - margin * 2);
    eyePos.value = {
        top: r.bottom + 4,
        left: Math.max(margin, Math.min(r.left, window.innerWidth - width - margin)),
        width,
    };
}

function toggleEyeMenu() {
    if (eyeMenuOpen.value) {
        eyeMenuOpen.value = false;
        return;
    }
    placeEyeMenu();
    eyeMenuOpen.value = true;
}

function onEyeDocClick(e) {
    if (! eyeMenuOpen.value) return;
    if (eyeTrigger.value?.contains(e.target)) return;
    if (eyeMenu.value?.contains(e.target)) return;
    eyeMenuOpen.value = false;
}

function onEyeKey(e) {
    if (e.key === 'Escape') eyeMenuOpen.value = false;
}

function onEyeScrollResize(e) {
    if (! eyeMenuOpen.value) return;
    // Scrolling inside the panel itself is not a dismissal.
    if (e?.target && eyeMenu.value && (eyeMenu.value === e.target || eyeMenu.value.contains(e.target))) return;
    eyeMenuOpen.value = false;
}

onMounted(() => {
    document.addEventListener('click', onEyeDocClick);
    document.addEventListener('keydown', onEyeKey);
    window.addEventListener('scroll', onEyeScrollResize, true);
    window.addEventListener('resize', onEyeScrollResize);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onEyeDocClick);
    document.removeEventListener('keydown', onEyeKey);
    window.removeEventListener('scroll', onEyeScrollResize, true);
    window.removeEventListener('resize', onEyeScrollResize);
});

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
    if (props.sample?.is_eye) return `${base}?eye=${props.sample.id}`;
    return base;
}

// Search by display name. Debounced — typing fires after 280ms idle,
// which is what Eyes/Matches uses too. Preserves the eye filter so
// searches happen within whatever common-with view is active.
const q = ref(props.filters?.q ?? '');
const side = ref(props.filters?.side ?? 'ALL');
const treeInclude = ref([...(props.filters?.tin ?? [])]);
const treeExclude = ref([...(props.filters?.tex ?? [])]);

// Shared reload — the search box and the ParentSide / Trees dropdowns
// reset to page 1 and preserve every other active filter.
function reloadFilters() {
    clearOverlays();
    router.reload({
        only: ONLY,
        // A new filter is a new list: `reset` tells Inertia to replace
        // `matches` outright rather than appending the first chunk of
        // the new results onto the old ones.
        reset: ['matches'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        data: {
            q: q.value.trim() || undefined,
            side: side.value !== 'ALL' ? side.value : undefined,
            tin: treeInclude.value.length ? treeInclude.value : undefined,
            tex: treeExclude.value.length ? treeExclude.value : undefined,
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
watch(treeInclude, reloadFilters);
watch(treeExclude, reloadFilters);

// When the POV eye disappears (e.g. switching the eye filter back to
// "All"), the dropdown is disabled — snap the value back to ALL so a
// stale PATERNAL/P1 selection doesn't linger in the URL.
watch(() => props.side_enabled, (enabled) => {
    if (!enabled && side.value !== 'ALL') side.value = 'ALL';
});

function reloadPage() {
    clearOverlays();
    router.reload({ reset: ['matches'], preserveState: true, preserveScroll: true, data: { page: 1 } });
}

// Force a full requeue of every (eye, this-sample) pair, then nudge
// Inertia so loading_status flips to loading and the spinner takes
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
                only: ['loading_status'],
                preserveScroll: true,
                preserveState: true,
            }),
        },
    );
}

// The five states the page can be in, straight from the server so the
// precedence between them lives in one place (DnaSampleService).
//   loading    — something is running, or queued with a live worker
//   queued     — outstanding, but nothing is moving it: no worker
//                running, or sitting out a retry backoff
//   partial    — settled, but some eyes failed permanently
//   complete   — every eye loaded
//   unloadable — no eye with a live session can see this sample
//   disabled   — Ancestry has disabled this kit; nothing more will ever
//                be loaded about it, so what is here is all there is
const loadState = computed(() => props.loading_status?.state ?? 'complete');

// Disabled in Ancestry. The page still renders everything already
// loaded — the kit is gone as a source, not as a record — but nothing
// that asks Ancestry for more is offered.
const sampleDisabled = computed(() => !!props.sample?.disabled);

// Poll while there is outstanding work, whether or not a worker is
// picking it up — `queued` is the case where someone restarts the
// worker and we want the page to notice. Only the loading_status prop
// is fetched; the heavy props stay untouched until it settles.
let loadingPollTimer = null;
function startLoadingPoll() {
    if (loadingPollTimer) return;
    loadingPollTimer = setInterval(() => {
        router.reload({
            only: ['loading_status'],
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
    loadState,
    (current, previous) => {
        const busy = current === 'loading' || current === 'queued';
        if (busy) {
            startLoadingPoll();
        } else {
            stopLoadingPoll();
            // Only pull the heavy props when we were actually waiting on
            // something — otherwise every first render would fire a
            // second full request for no reason.
            if (previous === 'loading' || previous === 'queued') {
                clearOverlays();
                router.reload({ reset: ['matches'], preserveScroll: true, data: { page: 1 } });
            }
        }
    },
    { immediate: true },
);

onUnmounted(stopLoadingPoll);

watch(selectedEye, (val) => {
    eyeMenuOpen.value = false;
    clearOverlays();
    router.reload({
        only: ONLY,
        reset: ['matches'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        data: {
            eye: val || undefined,
            q: q.value.trim() || undefined,
            side: side.value !== 'ALL' ? side.value : undefined,
            tin: treeInclude.value.length ? treeInclude.value : undefined,
            tex: treeExclude.value.length ? treeExclude.value : undefined,
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
    <AuthenticatedLayout full-height :header-compact="condensed">
        <template #header>
            <PageHeader
                compact
                :title="sample.display_label"
            >
                <template v-if="!condensed" #subtitle>
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
                    <Link
                        :href="route('dna.origins', sample.id)"
                        class="inline-flex items-center gap-1 rounded border border-paper-300 bg-paper-50 px-1.5 py-0.5 text-[11px] font-medium text-ink-300 hover:border-paper-400 hover:bg-paper-100 hover:text-ink-500"
                        :title="`Ethnicity estimate for ${sample.display_label}`"
                    >
                        <img src="/icon-globe.png" alt="" class="h-3.5 w-3.5" />
                        Origins
                    </Link>
                    <!-- Something is genuinely being worked on. -->
                    <button
                        v-if="loadState === 'loading'"
                        type="button"
                        @click="reloadPage"
                        class="inline-flex items-center gap-1 rounded border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-800 hover:bg-amber-100"
                        :title="`${loading_status.message} Auto-refreshes every 10 s; click to refresh now.`"
                    >
                        <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" />
                            <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                        </svg>
                        Loading…
                    </button>
                    <!--
                      Outstanding work that nothing is moving: the loader
                      is stopped, or the pair is sitting out a retry
                      backoff. Deliberately NOT a spinner — an animation
                      here is a lie, and this state is the whole reason
                      the worker heartbeat exists.
                    -->
                    <span
                        v-if="loadState === 'queued'"
                        class="inline-flex items-center gap-1 rounded border border-amber-400 bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-900"
                        :title="loading_status.message"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16zm.75-12a.75.75 0 0 0-1.5 0v4c0 .28.16.54.4.67l2.5 1.5a.75.75 0 1 0 .77-1.29l-2.17-1.3V6z" clip-rule="evenodd" />
                        </svg>
                        {{ loading_status.worker_alive ? 'Waiting' : 'Loader stopped' }}
                    </span>
                    <!--
                      Settled, but incomplete. This used to show nothing
                      at all: the spinner correctly stopped and the page
                      simply omitted the data with no explanation.
                    -->
                    <span
                        v-if="loadState === 'partial'"
                        class="inline-flex items-center gap-1 rounded border border-rose-300 bg-rose-50 px-1.5 py-0.5 text-[11px] font-medium text-rose-800"
                        :title="loading_status.message"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.63-1.516 2.63H3.72c-1.347 0-2.19-1.463-1.516-2.63L8.485 2.495zM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2z" clip-rule="evenodd" />
                        </svg>
                        {{ loading_status.eyes_failed }} of {{ loading_status.eyes_total }} unavailable
                    </span>
                    <!--
                      Disabled in Ancestry: a different thing from
                      "not loadable right now" — this one never becomes
                      loadable again, so it gets its own badge rather
                      than the grey "Not loadable" one.
                    -->
                    <span
                        v-if="loadState === 'disabled'"
                        class="inline-flex items-center gap-1 rounded border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-800"
                        :title="loading_status.message"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-5a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1zm3 8V5.5a3 3 0 1 0-6 0V9h6z" clip-rule="evenodd" />
                        </svg>
                        Disabled in Ancestry
                    </span>
                    <!-- No eye with a live session can fetch this kit at all. -->
                    <span
                        v-if="loadState === 'unloadable'"
                        class="inline-flex items-center gap-1 rounded border border-paper-300 bg-paper-100 px-1.5 py-0.5 text-[11px] font-medium text-ink-300"
                        :title="loading_status.message"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM6.28 6.28a.75.75 0 0 0-1.06 1.06L8.94 11l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 12.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 11l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 9.94 6.28 6.28z" clip-rule="evenodd" />
                        </svg>
                        Not loadable
                    </span>
                    <!--
                      RELOAD is a request to Ancestry, so it is not
                      offered for a kit Ancestry has disabled — the
                      controller refuses the POST for the same reason.
                    -->
                    <button
                        v-if="loadState !== 'loading' && !sampleDisabled"
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
                <template v-if="!condensed && (sample.person_id || titleTrees.length || ancestry_trees.length)" #belowTitle>
                    <div class="flex flex-wrap items-center gap-1">
                        <button
                            v-if="sample.person_id && !titleTrees.length"
                            type="button"
                            class="inline-flex h-5 w-5 items-center justify-center rounded text-sm font-semibold leading-none text-sepia-500 ring-1 ring-inset ring-dashed ring-sepia-300 transition hover:text-wine-500 hover:ring-wine-500 focus:outline-none focus:ring-2 focus:ring-wine-500"
                            :title="`Trees for ${sample.display_label}`"
                            @click="openPersonTrees(sample.person_id, sample.display_label)"
                        >
                            +
                            <span class="sr-only">Manage trees</span>
                        </button>
                        <TreePill
                            v-for="t in titleTrees"
                            :key="t.id"
                            :tree="t"
                            @edit="openPersonTrees(sample.person_id, sample.display_label)"
                        />
                        <!--
                          Ancestry's own trees for this person. They used
                          to be a collapsed card of their own, which cost
                          63px of the pane to say "Ancestry trees (1)".
                          Our tree pills and these are the same idea from
                          two sources, so they share a row.
                        -->
                        <a
                            v-for="t in ancestry_trees"
                            :key="`${t.atreeid}-${t.ancestryid}`"
                            :href="`https://www.ancestry.com.au/family-tree/tree/${t.atreeid}/family?cfpid=${t.ancestryid}`"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex max-w-[14rem] items-center gap-1 rounded border border-paper-300 bg-paper-50 px-1.5 py-0.5 text-[11px] font-medium text-ink-300 hover:border-paper-400 hover:bg-paper-100 hover:text-ink-500"
                            :title="`Open ${t.name || 'this tree'} on Ancestry`"
                        >
                            <img src="/ancestry-icon.svg" alt="" class="h-3 w-3 shrink-0" />
                            <span class="truncate">{{ t.name || 'Unknown Tree' }}</span>
                        </a>
                    </div>
                </template>
                <template #titleAfter>
                    <span class="font-mono text-xs text-sepia-400" :title="`Sample #${sample.id}`">
                        #{{ sample.id }}
                    </span>
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
                        type="button"
                        class="inline-flex items-center rounded p-0.5 text-sepia-400 hover:bg-paper-100 hover:text-wine-500 focus:outline-none focus:ring-1 focus:ring-wine-500"
                        :title="titleNote ? `Edit notes for ${sample.display_label}` : `Add notes for ${sample.display_label}`"
                        @click="openNoteEditor(sample.id, sample.display_label, titleNote)"
                    >
                        <img src="/icon-note.png" alt="" class="h-6 w-6" />
                        <span class="sr-only">{{ titleNote ? 'Edit notes' : 'Add notes' }}</span>
                    </button>
                    <OriginIcons :icons="sample.origin_icons || []" />
                    <img
                        v-if="sampleIsEye"
                        src="/icon-eye.png"
                        alt="Eye"
                        :title="sampleHasSession
                            ? 'Managed eye'
                            : 'Eye (no Ancestry session)'"
                        :class="{ 'opacity-50': !sampleHasSession }"
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
            </PageHeader>
        </template>

        <!--
          Fixed-height shell: the window never scrolls. Everything the
          user filters with (eye picker, search, ParentSide, trees)
          stays put in the shrink-0 band at the top, and only the
          results pane below it scrolls. Scrolling the filters out of
          reach on a 20k-row list was the whole problem.
        -->
        <div class="flex min-h-0 flex-1 flex-col">

        <!--
          The kit is gone from Ancestry, but everything already fetched
          about it is still here and still true as of when it was
          fetched. Say both halves: the data stands, and it stops here.
        -->
        <div
            v-if="sampleDisabled"
            class="mb-4 flex shrink-0 items-start gap-2 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
        >
            <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.63-1.516 2.63H3.72c-1.347 0-2.19-1.463-1.516-2.63L8.485 2.495zM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2z" clip-rule="evenodd" />
            </svg>
            <p>
                <strong class="font-semibold">This kit has been disabled in Ancestry.</strong>
                Everything below is what had already been loaded before that happened — it may be
                incomplete, and no more of it can be fetched. Notes, people and tree membership
                can still be edited here.
            </p>
        </div>


        <form
            class="mb-4 flex shrink-0 flex-wrap items-center gap-2"
            @submit.prevent
        >
            <!--
              The eye picker is a filter like the three beside it, and it
              used to say so — "Click to pick a filter" — from inside a
              card of its own that cost 63px of the list to show one line
              of summary. It is a dropdown on this row now; the panel
              below holds the same table it always did.
            -->
            <div v-if="eye_matches.length" class="relative">
                <button
                    ref="eyeTrigger"
                    type="button"
                    class="flex max-w-[22rem] items-center gap-1.5 rounded-md border border-paper-300 bg-paper-50 px-2 py-1 text-sm text-ink-500 focus:border-wine-500 focus:outline-none focus:ring-1 focus:ring-wine-500"
                    :aria-expanded="eyeMenuOpen"
                    :title="selectedEyeRow ? `Looking through ${selectedEyeRow.display_label}` : 'Pick an eye to look through'"
                    @click="toggleEyeMenu"
                >
                    <img src="/icon-eye.png" alt="Eye" class="h-5 w-5 shrink-0" />
                    <template v-if="selectedEyeRow">
                        <SampleAvatar
                            :photo-url="selectedEyeRow.other_photoUrl || ''"
                            :alt="selectedEyeRow.display_label"
                            :gender="selectedEyeRow.effective_gender || ''"
                        />
                        <span class="min-w-0 truncate font-medium">{{ selectedEyeRow.display_label }}</span>
                        <span class="shrink-0 font-mono text-xs text-sepia-500">
                            {{ selectedEyeRow.sharedCentimorgans }} cM
                        </span>
                        <ClusterPill
                            :code="selectedEyeRow.matchClusterCode || ''"
                            :paternal-cluster="selectedEyeRow.paternalCluster || ''"
                            :parent-side="selectedEyeRow.parentSide || ''"
                        />
                    </template>
                    <span v-else class="shrink-0 text-sepia-600">All eyes ({{ eye_matches.length }})</span>
                    <svg class="h-3.5 w-3.5 shrink-0 text-sepia-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06z" clip-rule="evenodd" />
                    </svg>
                </button>

                <Teleport to="body">
                    <div
                        v-if="eyeMenuOpen"
                        ref="eyeMenu"
                        class="fixed z-50 max-h-[60vh] overflow-auto rounded-md border border-paper-300 bg-paper-50 shadow-lg"
                        :style="{ top: eyePos.top + 'px', left: eyePos.left + 'px', width: eyePos.width + 'px' }"
                    >
                    <table class="ref-table ref-table--sticky">
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
                                        <OriginIcons :icons="e.origin_icons || []" />
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
                                            v-if="!sampleHasSession && e.other_uuid"
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
                                            v-if="sampleHasSession && e.other_uuid"
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
                </Teleport>
            </div>
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
            <label v-if="treeOptions.length" class="flex items-center gap-1.5 text-xs text-sepia-500">
                Trees
                <TreeFilterDropdown
                    v-model:include="treeInclude"
                    v-model:exclude="treeExclude"
                    :options="treeOptions"
                />
            </label>
        </form>

        <div
            v-if="loading"
            class="card flex min-h-0 flex-1 items-center justify-center gap-2 text-sm text-sepia-500"
        >
            <svg class="h-4 w-4 animate-spin text-sepia-400" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" />
                <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
            </svg>
            Loading matches…
        </div>

        <template v-else>
        <!--
          The one scrolling pane on the page. `relative` is load-bearing:
          Tailwind's .sr-only is position:absolute, and without a positioned
          ancestor those spans resolve against the viewport, escape the
          pane's clip and stretch the document — which put the window
          scrollbar back and scrolled the filters out of sight.
        -->
        <div class="card relative min-h-0 flex-1 overflow-auto" @scroll.passive="onPaneScroll">
            <!--
              InfiniteScroll wraps the table rather than standing in for
              <tbody>, so the sentinel it observes is a plain div of its
              own making, next to the table instead of inside it. It has
              to be the component's own element: handed a selector it
              resolves it once, during setup, before Vue has put
              anything in the DOM, and caches the null forever — the
              trigger then never fires and the list simply stops at 50.
              `items-element` still points at the tbody, which is what
              it watches for new rows.
            -->
            <InfiniteScroll
                data="matches"
                only-next
                items-element="#matches-rows"
                :start-element="() => null"
            >
            <table class="ref-table ref-table--sticky">
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
                <!--
                  `as="tbody"` so the rows stay inside a real table, and
                  the scroll sentinels are handed in explicitly: left to
                  itself the component renders its own <div> markers
                  either side of the items element, which inside a
                  <table> is invalid markup. `start-element` returning
                  null suppresses the leading one — this list only ever
                  grows downwards.
                -->
                <tbody id="matches-rows">
                    <template v-for="m in rows" :key="m.other_id">
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
                                    type="button"
                                    class="ms-1 inline-flex items-center rounded p-0.5 align-middle text-sepia-400 hover:bg-paper-100 hover:text-wine-500 focus:outline-none focus:ring-1 focus:ring-wine-500"
                                    :title="m.note ? `Edit notes for ${m.display_label}` : `Add notes for ${m.display_label}`"
                                    @click="openNoteEditor(m.other_id, m.display_label, m.note)"
                                >
                                    <img src="/icon-note.png" alt="" class="h-6 w-6" />
                                    <span class="sr-only">{{ m.note ? 'Edit notes' : 'Add notes' }}</span>
                                </button>
                                <OriginIcons :icons="m.origin_icons || []" />
                                <img
                                    v-if="m.connected_via_tree"
                                    src="/icon-link.png"
                                    alt=""
                                    class="ms-1 h-6 w-6 opacity-80"
                                    :title="`Connected to ${sample.display_label} via the family tree`"
                                />
                                <img
                                    v-if="m.other_is_eye"
                                    src="/icon-eye.png"
                                    alt="Eye"
                                    :title="m.other_managed === null
                                        ? 'Eye (no Ancestry session)'
                                        : 'Managed eye'"
                                    class="ms-2 h-6 w-6"
                                    :class="{ 'opacity-50': m.other_managed === null }"
                                />
                            </div>
                        </td>
                        <td class="text-sm text-sepia-700">
                            {{ (m.kinships || []).join(' / ') }}
                            <span v-if="m.assignment && m.sharedCentimorgans >= 200" class="text-red-600">[{{ m.assignment }}]</span>
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
                                <button
                                    v-if="m.person_id && !(m.trees || []).length"
                                    type="button"
                                    class="inline-flex h-5 w-5 items-center justify-center rounded text-sm font-semibold leading-none text-sepia-500 ring-1 ring-inset ring-dashed ring-sepia-300 transition hover:text-wine-500 hover:ring-wine-500 focus:outline-none focus:ring-2 focus:ring-wine-500"
                                    :title="`Trees for ${m.display_label}`"
                                    @click="openPersonTrees(m.person_id, m.display_label)"
                                >
                                    +
                                    <span class="sr-only">Manage trees</span>
                                </button>
                                <TreePill
                                    v-for="t in (m.trees || [])"
                                    :key="t.id"
                                    :tree="t"
                                    @edit="openPersonTrees(m.person_id, m.display_label)"
                                />
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
                                    v-if="sampleHasSession && m.other_uuid && !selectedEyeIsSample"
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
                                    v-if="!sampleHasSession && m.other_managed !== null && m.other_uuid"
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
                    <tr v-if="!rows.length">
                        <td colspan="6" class="empty-cell">No matches.</td>
                    </tr>
                </tbody>
            </table>

            <!--
              Rendered inside the component's own end sentinel, so it is
              exactly the box being watched.
            -->
            <template #next="{ loading, hasMore }">
                <div class="flex justify-center px-4 py-3">
                    <span v-if="loading" class="inline-flex items-center gap-2 text-sm text-sepia-500">
                        <svg class="h-4 w-4 animate-spin text-sepia-400" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" />
                            <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                        </svg>
                        Loading more…
                    </span>
                    <span v-else-if="!hasMore && rows.length" class="text-xs text-sepia-400">
                        End of matches
                    </span>
                </div>
            </template>
            </InfiniteScroll>
        </div>

        </template>

        </div>

        <PersonEditDialog
            :show="!!editing"
            :sample-id="editing?.sampleId ?? 0"
            :person-id="editing?.personId ?? null"
            :prefill="editing?.prefill ?? {}"
            :reload-only="['filters']"
            @saved="onPersonSaved"
            @close="closeEdit"
        />

        <NoteEditDialog
            :show="!!editingNote"
            :sample-id="editingNote?.sampleId ?? 0"
            :sample-label="editingNote?.sampleLabel ?? ''"
            :initial="editingNote?.initial ?? ''"
            @saved="onNoteSaved"
            @close="closeNoteEditor"
        />

        <TreeEditDialog
            :show="!!editingTree"
            :tree="editingTree"
            @saved="onTreeSaved"
            @close="closeTreeEditor"
        />

        <PersonTreesDialog
            :show="!!managingPersonId"
            :person-id="managingPersonId"
            :person-label="managingLabel"
            :trees="managedTrees"
            :page-trees="pageTrees"
            @changed="onPersonTreesChanged(managingPersonId)"
            @close="closePersonTrees"
            @edit-tree="(t) => { closePersonTrees(); openTreeEditor(t); }"
        />
    </AuthenticatedLayout>
</template>
